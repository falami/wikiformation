<?php
// src/Controller/Formateur/PositioningFormateurController.php
declare(strict_types=1);

namespace App\Controller\Formateur;

use App\Entity\{PositioningAssignment, Entite, Utilisateur, PositioningAttempt};
use App\Repository\PositioningAnswerRepository;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Security\Permission\TenantPermission;
use App\Form\Formateur\PositioningConclusionType;
use Symfony\Component\HttpFoundation\JsonResponse;



#[Route('/formateur/{entite}/positionnements', name: 'app_formateur_positioning_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::FORMATEUR_POSITIONING_MANAGE, subject: 'entite')]
final class PositioningFormateurController extends AbstractController
{
  public function __construct(
    private UtilisateurEntiteManager $utilisateurEntiteManager,
  ) {}

  #[Route('', name: 'index', methods: ['GET'])]
  public function index(Entite $entite, Request $req): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    $ue = $this->utilisateurEntiteManager->getUserEntiteLink($entite);
    if (!$ue) {
      throw $this->createAccessDeniedException('Accès interdit à cette entité.');
    }

    return $this->render('formateur/positioning/index.html.twig', [
      'entite' => $entite,
      'utilisateurEntite' => $ue,
    ]);
  }


  #[Route('/{attempt}', name: 'show', methods: ['GET', 'POST'], requirements: ['attempt' => '\d+'])]
  public function show(
    Entite $entite,
    PositioningAttempt $attempt,
    Request $req,
    EM $em,
    PositioningAnswerRepository $answerRepo
  ): Response {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    // ✅ sécurité entité
    $ok = false;
    if ($attempt->getQuestionnaire()?->getEntite()?->getId() === $entite->getId()) $ok = true;
    elseif ($attempt->getSession()?->getEntite()?->getId() === $entite->getId()) $ok = true;
    elseif ($attempt->getInscription()?->getSession()?->getEntite()?->getId() === $entite->getId()) $ok = true;

    if (!$ok) {
      throw $this->createNotFoundException();
    }

    if (!$attempt->getSubmittedAt()) {
      throw $this->createAccessDeniedException('Positionnement non soumis.');
    }

    $assignment = $attempt->getAssignment();
    if (!$assignment instanceof PositioningAssignment) {
      throw $this->createAccessDeniedException('Aucune affectation liée à cette tentative.');
    }

    if ($assignment->getEvaluator()?->getId() !== $user->getId()) {
      throw $this->createAccessDeniedException('Ce positionnement ne vous est pas affecté.');
    }

    // ✅ Form conclusion (Enum + textarea)
    $form = $this->createForm(PositioningConclusionType::class, $attempt, [
      'csrf_token_id' => 'concl_' . $attempt->getId(),
    ]);
    $form->handleRequest($req);

    if ($form->isSubmitted() && $form->isValid()) {
      $em->flush();
      $this->addFlash('success', 'Conclusion enregistrée.');

      return $this->redirectToRoute('app_formateur_positioning_show', [
        'entite' => $entite->getId(),
        'attempt' => $attempt->getId(),
      ]);
    }

    $answers = $answerRepo->findForAttemptOrdered($attempt);

    return $this->render('formateur/positioning/show.html.twig', [
      'entite' => $entite,
      'attempt' => $attempt,
      'answers' => $answers,
      'form' => $form->createView(),

    ]);
  }




  #[Route('/ajax', name: 'ajax', methods: ['POST'])]
  public function ajax(Entite $entite, Request $req, EM $em): JsonResponse
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    // ✅ sécurité: le formateur doit être rattaché à l’entité
    $ue = $this->utilisateurEntiteManager->getUserEntiteLink($entite);
    if (!$ue) {
      return new JsonResponse(['draw' => 1, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []], 403);
    }

    $draw   = (int) $req->request->get('draw', 1);
    $start  = max(0, (int) $req->request->get('start', 0));
    $length = max(10, (int) $req->request->get('length', 10));

    $searchArr = (array) $req->request->all('search');
    $search = trim((string) ($searchArr['value'] ?? ''));

    // filtres custom
    $conclusionFilter = (string) $req->request->get('conclusionFilter', 'all'); // all|done|todo
    $stagiaireFilter = trim((string) $req->request->get('stagiaireFilter', ''));

    $qb = $em->createQueryBuilder()
      ->select('a, s, q, sess, ins, sessIns')
      ->from(PositioningAttempt::class, 'a')
      ->leftJoin('a.stagiaire', 's')
      ->leftJoin('a.questionnaire', 'q')
      ->leftJoin('a.session', 'sess')
      ->leftJoin('a.inscription', 'ins')
      ->leftJoin('ins.session', 'sessIns')
      ->leftJoin('a.assignment', 'ass')
      ->andWhere('a.submittedAt IS NOT NULL')
      ->andWhere('ass.evaluator = :me')->setParameter('me', $user)
      ->andWhere('(q.entite = :e OR sess.entite = :e OR sessIns.entite = :e)')->setParameter('e', $entite);

    // filtre conclusion
    if ($conclusionFilter === 'done') {
      $qb->andWhere('a.formateurConclusion IS NOT NULL AND a.formateurConclusion <> \'\'');
    } elseif ($conclusionFilter === 'todo') {
      $qb->andWhere('a.formateurConclusion IS NULL OR a.formateurConclusion = \'\'');
    }

    // filtre stagiaire (champ)
    if ($stagiaireFilter !== '') {
      $qb->andWhere('LOWER(s.nom) LIKE :st OR LOWER(s.prenom) LIKE :st OR LOWER(s.email) LIKE :st')
        ->setParameter('st', '%' . mb_strtolower($stagiaireFilter) . '%');
    }

    // search global DataTables
    if ($search !== '') {
      $qb->andWhere('LOWER(s.nom) LIKE :s OR LOWER(s.prenom) LIKE :s OR LOWER(s.email) LIKE :s OR LOWER(q.title) LIKE :s')
        ->setParameter('s', '%' . mb_strtolower($search) . '%');
    }

    // total
    $recordsTotal = (int) $em->createQueryBuilder()
      ->select('COUNT(DISTINCT a2.id)')
      ->from(PositioningAttempt::class, 'a2')
      ->leftJoin('a2.questionnaire', 'q2')
      ->leftJoin('a2.session', 'sess2')
      ->leftJoin('a2.inscription', 'ins2')
      ->leftJoin('ins2.session', 'sessIns2')
      ->leftJoin('a2.assignment', 'ass2')
      ->andWhere('a2.submittedAt IS NOT NULL')
      ->andWhere('ass2.evaluator = :me')->setParameter('me', $user)
      ->andWhere('(q2.entite = :e OR sess2.entite = :e OR sessIns2.entite = :e)')->setParameter('e', $entite)
      ->getQuery()->getSingleScalarResult();

    // filtered
    $qbCount = clone $qb;
    $qbCount->resetDQLPart('select')->resetDQLPart('orderBy');
    $qbCount->select('COUNT(DISTINCT a.id)');
    $recordsFiltered = (int) $qbCount->getQuery()->getSingleScalarResult();

    // order
    $order = (array) ($req->request->all('order')[0] ?? ['column' => 0, 'dir' => 'desc']);
    $col = (int) ($order['column'] ?? 0);
    $dir = strtolower((string) ($order['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

    $columns = [
      0 => 's.nom',
      1 => 'q.title',
      2 => 'a.id',          // session display custom (pas triable idéalement)
      3 => 'a.submittedAt',
      4 => 'a.formateurConclusion',
      5 => 'a.id',
    ];
    $qb->orderBy($columns[$col] ?? 'a.submittedAt', $dir);

    $qb->setFirstResult($start)->setMaxResults($length);
    $rows = $qb->getQuery()->getResult();

    $data = [];
    foreach ($rows as $a) {
      /** @var PositioningAttempt $a */
      $stagiaire = $a->getStagiaire();

      $stagiaireLabel = $stagiaire
        ? trim(($stagiaire->getPrenom() ?? '') . ' ' . ($stagiaire->getNom() ?? '')) ?: ($stagiaire->getEmail() ?? '—')
        : '—';

      $sessionLabel = 'Hors session';
      if ($a->getSession()) {
        $sessionLabel = '#' . $a->getSession()->getId();
      } elseif ($a->getInscription()?->getSession()) {
        $sessionLabel = '#' . $a->getInscription()->getSession()->getId();
      }

      $isDone = (string) $a->getFormateurConclusion() !== '';

      $conclusionBadge = $isDone
        ? '<span class="badge-soft badge-done"><i class="bi bi-check2"></i> Rédigée</span>'
        : '<span class="badge-soft badge-todo"><i class="bi bi-pencil"></i> À faire</span>';

      $openUrl = $this->generateUrl('app_formateur_positioning_show', [
        'entite' => $entite->getId(),
        'attempt' => $a->getId(),
      ]);

      $actions = '<div class="d-inline-flex gap-1 justify-content-end">
      <a class="btn btn-sm btn-warning fw-semibold" href="' . $openUrl . '">
        <i class="bi bi-eye"></i> Ouvrir
      </a>
    </div>';

      $data[] = [
        'stagiaire' => htmlspecialchars($stagiaireLabel),
        'questionnaire' => htmlspecialchars($a->getQuestionnaire()?->getTitle() ?? '—'),
        'session' => htmlspecialchars($sessionLabel),
        'submittedAt' => $a->getSubmittedAt()?->format('d/m/Y H:i') ?? '—',
        'conclusion' => $conclusionBadge,
        'actions' => $actions,
      ];
    }

    return new JsonResponse([
      'draw' => $draw,
      'recordsTotal' => (int) $recordsTotal,
      'recordsFiltered' => (int) $recordsFiltered,
      'data' => $data,
    ]);
  }
}
