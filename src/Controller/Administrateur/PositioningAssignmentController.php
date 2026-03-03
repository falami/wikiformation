<?php
// src/Controller/Administrateur/PositioningAssignmentController.php
declare(strict_types=1);

namespace App\Controller\Administrateur;

use App\Entity\{PositioningAssignment, Entite, Utilisateur, Session, PositioningAttempt, PositioningAnswer, UtilisateurEntite};
use App\Form\Administrateur\PositioningAssignmentType;
use App\Service\Positioning\PositioningAssigner;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use Doctrine\ORM\EntityManagerInterface as EM;
use App\Enum\SuggestedLevel;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use App\Security\Permission\TenantPermission;



#[Route('/administrateur/{entite}/positionnements/assignments', name: 'app_administrateur_positioning_assignment_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::POSITIONING_ASSIGNMENT_MANAGE, subject: 'entite')]
final class PositioningAssignmentController extends AbstractController
{
  public function __construct(
    private UtilisateurEntiteManager $utilisateurEntiteManager,
  ) {}

  #[Route('', name: 'index', methods: ['GET', 'POST'])]
  public function index(Entite $entite, Request $req, EM $em, PositioningAssigner $assigner): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    // ✅ sécurité : admin rattaché à l’entité
    $ue = $this->utilisateurEntiteManager->getUserEntiteLink($entite);
    if (!$ue) {
      throw $this->createAccessDeniedException('Accès interdit à cette entité.');
    }

    $form = $this->createForm(PositioningAssignmentType::class, null, ['entite' => $entite]);
    $form->handleRequest($req);

    if ($form->isSubmitted() && $form->isValid()) {
      $stagiaire = $form->get('stagiaire')->getData();
      $q = $form->get('questionnaire')->getData();
      $required = (bool) $form->get('isRequired')->getData();

      if (!$stagiaire || !$q || $q->getEntite()?->getId() !== $entite->getId()) {
        $this->addFlash('danger', 'Stagiaire / Questionnaire invalide.');
        return $this->redirectToRoute('app_administrateur_positioning_assignment_index', ['entite' => $entite->getId()]);
      }

      $assigner->assignToUser($stagiaire, $q, $user, $entite, $required);
      $em->flush();

      $this->addFlash('success', 'Questionnaire attribué au stagiaire.');
      return $this->redirectToRoute('app_administrateur_positioning_assignment_index', ['entite' => $entite->getId()]);
    }

    // ✅ Liste des formateurs (Utilisateurs TENANT_FORMATEUR rattachés à l’entité)
    $formateurs = [];
    $links = $this->utilisateurEntiteManager->getRepository()->findBy(['entite' => $entite]);
    foreach ($links as $link) {
      $u = $link->getUtilisateur();
      if (!$u) continue;

      if ($link->hasRole(UtilisateurEntite::TENANT_FORMATEUR)) {
        $formateurs[] = $u;
      }
    }



    // ✅ Liste des sessions de l’entité (tri PHP via getDateDebut())
    $sessions = $em->getRepository(Session::class)->createQueryBuilder('s')
      ->andWhere('s.entite = :e')->setParameter('e', $entite)
      ->getQuery()->getResult();

    // tri desc par dateDebut calculée (getDateDebut())
    usort($sessions, static function (Session $a, Session $b): int {
      $da = $a->getDateDebut();
      $db = $b->getDateDebut();

      // sessions sans jours => à la fin
      if ($da === null && $db === null) return 0;
      if ($da === null) return 1;
      if ($db === null) return -1;

      // DESC
      return $db <=> $da;
    });

    // (optionnel) limiter après tri
    $sessions = array_slice($sessions, 0, 200);



    return $this->render('administrateur/positioning/assignment/index.html.twig', [
      'entite' => $entite,
      'form' => $form->createView(),
      'formateurs' => $formateurs,
      'utilisateurEntite' => $ue,
      'sessions' => $sessions,
    ]);
  }

  #[Route('/ajax', name: 'ajax', methods: ['POST'])]
  public function ajax(Entite $entite, Request $req, EM $em, CsrfTokenManagerInterface $csrfManager): JsonResponse
  {
    $draw = (int) $req->request->get('draw', 1);
    $start = max(0, (int) $req->request->get('start', 0));
    $length = max(10, (int) $req->request->get('length', 10));

    $searchArr = (array) $req->request->all('search');
    $search = trim((string) ($searchArr['value'] ?? ''));

    $requiredFilter = (string) $req->request->get('requiredFilter', 'all');   // all|yes|no
    $submittedFilter = (string) $req->request->get('submittedFilter', 'all'); // all|yes|no
    $questionnaireId = (string) $req->request->get('questionnaireFilter', 'all'); // all|id (si tu l’ajoutes)
    $stagiaireText = trim((string) $req->request->get('stagiaireFilter', ''));

    $qb = $em->createQueryBuilder()
      ->select('a') // ✅ IMPORTANT (sinon résultats mixtes)
      ->from(PositioningAssignment::class, 'a')
      ->join('a.stagiaire', 'u')
      ->join('a.questionnaire', 'q')
      ->leftJoin('a.attempt', 't')
      ->leftJoin('a.evaluator', 'ev')
      ->andWhere('q.entite = :e')->setParameter('e', $entite);

    // filtres
    if ($requiredFilter === 'yes') $qb->andWhere('a.isRequired = true');
    elseif ($requiredFilter === 'no') $qb->andWhere('a.isRequired = false');

    if ($submittedFilter === 'yes') $qb->andWhere('t.submittedAt IS NOT NULL');
    elseif ($submittedFilter === 'no') $qb->andWhere('t.submittedAt IS NULL');

    if ($questionnaireId !== 'all' && ctype_digit($questionnaireId)) {
      $qb->andWhere('q.id = :qid')->setParameter('qid', (int) $questionnaireId);
    }

    if ($stagiaireText !== '') {
      $qb->andWhere('LOWER(u.nom) LIKE :st OR LOWER(u.prenom) LIKE :st OR LOWER(u.email) LIKE :st')
        ->setParameter('st', '%' . mb_strtolower($stagiaireText) . '%');
    }

    if ($search !== '') {
      $qb->andWhere('LOWER(u.nom) LIKE :s OR LOWER(u.prenom) LIKE :s OR LOWER(u.email) LIKE :s OR LOWER(q.title) LIKE :s')
        ->setParameter('s', '%' . mb_strtolower($search) . '%');
    }

    // total & filtered (✅ DISTINCT pour éviter doublons)
    $recordsTotal = (int) $em->createQueryBuilder()
      ->select('COUNT(DISTINCT a2.id)')
      ->from(PositioningAssignment::class, 'a2')
      ->join('a2.questionnaire', 'q2')
      ->andWhere('q2.entite = :e')->setParameter('e', $entite)
      ->getQuery()->getSingleScalarResult();

    $qbCountFiltered = clone $qb;
    $qbCountFiltered->resetDQLPart('select')->resetDQLPart('orderBy');
    $qbCountFiltered->select('COUNT(DISTINCT a.id)');
    $recordsFiltered = (int) $qbCountFiltered->getQuery()->getSingleScalarResult();

    // order (✅ aligné à tes colonnes Twig)
    $order = (array) ($req->request->all('order')[0] ?? ['column' => 0, 'dir' => 'desc']);
    $col = (int) ($order['column'] ?? 0);
    $dir = strtolower((string) ($order['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

    $columns = [
      0  => 'a.id',
      1  => 'u.nom',
      2  => 'u.email',
      3  => 'q.title',
      4  => 'ev.nom',        // formateur (même si orderable:false)
      5  => 'a.isRequired',
      6  => 't.submittedAt', // statut
      7  => 'a.createdAt',
      8  => 't.startedAt',
      9  => 't.submittedAt',
      10 => 'a.id',          // actions
    ];
    $qb->orderBy($columns[$col] ?? 'a.id', $dir);

    $qb->setFirstResult($start)->setMaxResults($length);
    $rows = $qb->getQuery()->getResult();


    $attemptIds = [];
    foreach ($rows as $a) {
      $t = $a->getAttempt();
      if ($t && $t->getId()) $attemptIds[] = $t->getId();
    }
    $attemptIds = array_values(array_unique($attemptIds));

    $levelMap = [];
    if ($attemptIds) {
      $levelMap = $em->getRepository(PositioningAnswer::class)
        ->computeLevelByAttemptIds($attemptIds);
    }


    $data = [];
    foreach ($rows as $a) {
      /** @var PositioningAssignment $a */
      $u = $a->getStagiaire();
      $q = $a->getQuestionnaire();
      $t = $a->getAttempt();
      $ev = $a->getEvaluator();

      $requiredBadge = $a->isRequired()
        ? '<span class="badge bg-warning text-dark">Obligatoire</span>'
        : '<span class="badge bg-secondary">Optionnel</span>';

      $started = $t?->getStartedAt();
      $submitted = $t?->getSubmittedAt();

      $computedLevel = '—';


      if ($t && $t->getId() && isset($levelMap[$t->getId()])) {
        $lvlInt = (int)$levelMap[$t->getId()];

        $badgeClass = match ($lvlInt) {
          1 => 'bg-info text-dark',
          2 => 'bg-primary',
          3 => 'bg-warning text-dark',
          4 => 'bg-danger',
          default => 'bg-secondary',
        };

        $label = match ($lvlInt) {
          1 => 'Initial',
          2 => 'Intermédiaire',
          3 => 'Avancé',
          4 => 'Expert',
          default => '—',
        };

        $computedLevel = '<span class="badge ' . $badgeClass . '">' . $label . '</span>';
      }



      // ✅ Niveau suggéré (SuggestedLevel sur PositioningAttempt)
      $level = $t?->getSuggestedLevel(); // ?SuggestedLevel

      $levelBadge = '—';
      if ($level instanceof SuggestedLevel) {
        $label = method_exists($level, 'label') ? $level->label() : $level->value;

        $badgeClass = match ($level) {
          SuggestedLevel::INITIAL => 'bg-info text-dark',
          SuggestedLevel::INTERMEDIAIRE => 'bg-primary',
          SuggestedLevel::AVANCE => 'bg-warning text-dark',
          SuggestedLevel::EXPERT => 'bg-danger',
          default => 'bg-secondary',
        };

        $levelBadge = '<span class="badge ' . $badgeClass . '">' . htmlspecialchars($label) . '</span>';
      }


      $status = $submitted
        ? '<span class="badge bg-success">Soumis</span>'
        : ($started ? '<span class="badge bg-primary">En cours</span>' : '<span class="badge bg-light text-dark">Non démarré</span>');

      $toggleUrl = $this->generateUrl('app_administrateur_positioning_assignment_toggle_required', [
        'entite' => $entite->getId(),
        'assignment' => $a->getId(),
      ]);

      $deleteUrl = $this->generateUrl('app_administrateur_positioning_assignment_delete', [
        'entite' => $entite->getId(),
        'assignment' => $a->getId(),
      ]);

      $showAttemptUrl = $t ? $this->generateUrl('app_administrateur_positioning_show', [
        'entite' => $entite->getId(),
        'attempt' => $t->getId(),
      ]) : null;

      // ✅ CSRF (une seule fois)
      $csrf = $csrfManager->getToken('del_assignment_' . $a->getId())->getValue();


      $session = $t?->getSession();


      $setSessionUrl = $this->generateUrl('app_administrateur_positioning_assignment_set_session', [
        'entite' => $entite->getId(),
        'assignment' => $a->getId(),
      ]);



      $setEvaluatorUrl = $this->generateUrl('app_administrateur_positioning_assignment_set_evaluator', [
        'entite' => $entite->getId(),
        'assignment' => $a->getId(),
      ]);

      $actions = '<div class="d-inline-flex gap-1 justify-content-end">';

      if ($showAttemptUrl) {
        $actions .= '<a class="btn btn-sm btn-outline-primary fw-semibold" href="' . $showAttemptUrl . '"><i class="bi bi-eye"></i></a>';
      } else {
        $actions .= '<button class="btn btn-sm btn-outline-secondary" type="button" disabled title="Aucune tentative"><i class="bi bi-eye"></i></button>';
      }


      $actions .= '<button class="btn btn-sm btn-outline-info fw-semibold js-assign-evaluator"
        data-assignment="' . $a->getId() . '"
        data-url="' . $setEvaluatorUrl . '"
        data-current="' . (int)($ev?->getId() ?? 0) . '"
        title="Affecter un formateur">
        <i class="bi bi-person-check"></i>
      </button>';


      $actions .= '<button class="btn btn-sm btn-outline-success fw-semibold js-assign-session"
        data-url="' . $setSessionUrl . '"
        data-current="' . (int)($session?->getId() ?? 0) . '"
        title="Affecter une session">
        <i class="bi bi-mortarboard"></i>
      </button>';


      $actions .= '<button class="btn btn-sm btn-outline-warning fw-semibold js-toggle-required" data-url="' . $toggleUrl . '"><i class="bi bi-exclamation-diamond"></i></button>';

      $actions .= '<form class="m-0 p-0 d-inline" method="post" action="' . $deleteUrl . '" onsubmit="return confirm(\'Supprimer cette affectation ?\');">
          <input type="hidden" name="_token" value="' . $csrf . '">
          <button class="btn btn-sm btn-outline-danger fw-semibold" type="submit"><i class="bi bi-trash"></i></button>
        </form>';

      $actions .= '</div>';

      $data[] = [
        'id' => $a->getId(),
        'stagiaire' => htmlspecialchars(trim(($u?->getPrenom() ?? '') . ' ' . ($u?->getNom() ?? ''))),
        'questionnaire' => htmlspecialchars($q?->getTitle() ?? ''),
        'computedLevel' => $computedLevel,
        'evaluator' => $ev ? htmlspecialchars(trim(($ev->getPrenom() ?? '') . ' ' . ($ev->getNom() ?? ''))) : '—',
        'evaluatorId' => $ev?->getId(),
        'required' => $requiredBadge,
        'status' => $status,
        'suggestedLevel' => $levelBadge,
        'createdAt' => $a->getCreatedAt()?->format('d/m/Y H:i') ?? '—',
        'submittedAt' => $submitted?->format('d/m/Y H:i') ?? '—',
        'actions' => $actions,
      ];
    }

    return new JsonResponse([
      'draw' => $draw,
      'recordsTotal' => $recordsTotal,
      'recordsFiltered' => $recordsFiltered,
      'data' => $data,
    ]);
  }

  #[Route('/{assignment}/set-evaluator', name: 'set_evaluator', methods: ['POST'], requirements: ['assignment' => '\d+'])]
  public function setEvaluator(Entite $entite, PositioningAssignment $assignment, Request $req, EM $em): JsonResponse
  {
    if ($assignment->getQuestionnaire()?->getEntite()?->getId() !== $entite->getId()) {
      return new JsonResponse(['ok' => false, 'message' => 'Not found'], 404);
    }

    $id = (string) $req->request->get('evaluatorId', '');
    $evaluator = null;

    if ($id !== '' && ctype_digit($id)) {
      /** @var Utilisateur|null $u */
      $u = $em->getRepository(Utilisateur::class)->find((int) $id);
      if (!$u) return new JsonResponse(['ok' => false, 'message' => 'Formateur introuvable'], 404);

      $ueTarget = $this->utilisateurEntiteManager->getRepository()->findOneBy([
        'entite' => $entite,
        'utilisateur' => $u,
      ]);

      if (!$ueTarget) {
        return new JsonResponse(['ok' => false, 'message' => 'Formateur non rattaché à cette entité'], 403);
      }

      if (!$ueTarget->hasRole(UtilisateurEntite::TENANT_FORMATEUR)) {
        return new JsonResponse(['ok' => false, 'message' => 'Cet utilisateur n’est pas formateur dans cette entité'], 403);
      }

      $evaluator = $u;
    }

    $assignment->setEvaluator($evaluator);
    $em->flush();

    return new JsonResponse([
      'ok' => true,
      'evaluator' => $evaluator ? trim(($evaluator->getPrenom() ?? '') . ' ' . ($evaluator->getNom() ?? '')) : null,
      'evaluatorId' => $evaluator?->getId(),
    ]);
  }

  #[Route('/{assignment}/toggle-required', name: 'toggle_required', methods: ['POST'], requirements: ['assignment' => '\d+'])]
  public function toggleRequired(Entite $entite, PositioningAssignment $assignment, EM $em): JsonResponse
  {
    if ($assignment->getQuestionnaire()?->getEntite()?->getId() !== $entite->getId()) {
      return new JsonResponse(['ok' => false], 404);
    }

    $assignment->setIsRequired(!$assignment->isRequired());
    $em->flush();

    return new JsonResponse(['ok' => true, 'required' => $assignment->isRequired()]);
  }

  #[Route('/{assignment}/delete', name: 'delete', methods: ['POST'], requirements: ['assignment' => '\d+'])]
  public function delete(Entite $entite, PositioningAssignment $assignment, EM $em, Request $req): Response
  {
    if ($assignment->getQuestionnaire()?->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException();
    }

    if (!$this->isCsrfTokenValid('del_assignment_' . $assignment->getId(), (string) $req->request->get('_token'))) {
      $this->addFlash('danger', 'Token CSRF invalide.');
      return $this->redirectToRoute('app_administrateur_positioning_assignment_index', ['entite' => $entite->getId()]);
    }

    $em->remove($assignment);
    $em->flush();

    $this->addFlash('success', 'Affectation supprimée.');
    return $this->redirectToRoute('app_administrateur_positioning_assignment_index', ['entite' => $entite->getId()]);
  }



  #[Route('/{assignment}/set-session', name: 'set_session', methods: ['POST'], requirements: ['assignment' => '\d+'])]
  public function setSession(Entite $entite, PositioningAssignment $assignment, Request $req, EM $em): JsonResponse
  {
    if ($assignment->getQuestionnaire()?->getEntite()?->getId() !== $entite->getId()) {
      return new JsonResponse(['ok' => false, 'message' => 'Not found'], 404);
    }

    $attempt = $assignment->getAttempt();
    if (!$attempt instanceof PositioningAttempt) {
      // si tu veux autoriser même sans attempt, tu peux créer l'attempt ici
      return new JsonResponse(['ok' => false, 'message' => 'Aucune tentative liée'], 400);
    }

    $id = (string) $req->request->get('sessionId', '');
    $session = null;

    if ($id !== '' && ctype_digit($id)) {
      /** @var Session|null $s */
      $s = $em->getRepository(Session::class)->find((int) $id);
      if (!$s) return new JsonResponse(['ok' => false, 'message' => 'Session introuvable'], 404);

      // ✅ sécurité entité
      if ($s->getEntite()?->getId() !== $entite->getId()) {
        return new JsonResponse(['ok' => false, 'message' => 'Session hors entité'], 403);
      }

      $session = $s;
    }

    $attempt->setSession($session);
    $em->flush();

    return new JsonResponse([
      'ok' => true,
      'sessionId' => $session?->getId(),
      'sessionLabel' => $session ? (method_exists($session, 'getCode') ? $session->getCode() : ('Session #' . $session->getId())) : null,
    ]);
  }
}
