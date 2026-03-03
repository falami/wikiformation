<?php

declare(strict_types=1);

namespace App\Controller\Administrateur;

use App\Entity\{Session, Entite, Utilisateur, FormateurSatisfactionAssignment};
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use App\Service\FormateurSatisfaction\FormateurSatisfactionAssigner;
use App\Service\Satisfaction\SatisfactionAccess;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment as Twig;
use App\Security\Permission\TenantPermission;



#[Route('/administrateur/{entite}/formateur-satisfaction/assignments', name: 'app_administrateur_formateur_satisfaction_assignment_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::FORMATEUR_SATISFACTION_ASSIGNMENT_MANAGE, subject: 'entite')]
final class FormateurSatisfactionAssignmentController extends AbstractController
{
  public function __construct(
    private UtilisateurEntiteManager $uem,
    private FormateurSatisfactionAssigner $assigner,
    private SatisfactionAccess $access,
    private Twig $twig,
  ) {}

  #[Route('', name: 'index', methods: ['GET'])]
  public function index(Entite $entite, EM $em): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();
    $ue = $this->uem->getUserEntiteLink($entite);
    if (!$ue) throw $this->createAccessDeniedException();

    $sessions = $em->getRepository(Session::class)->createQueryBuilder('s')
      ->andWhere('s.entite = :e')->setParameter('e', $entite)
      ->getQuery()->getResult();

    usort($sessions, static function (Session $a, Session $b): int {
      $da = method_exists($a, 'getDateDebut') ? $a->getDateDebut() : null;
      $db = method_exists($b, 'getDateDebut') ? $b->getDateDebut() : null;
      if ($da === null && $db === null) return 0;
      if ($da === null) return 1;
      if ($db === null) return -1;
      return $db <=> $da;
    });
    $sessions = array_slice($sessions, 0, 250);

    return $this->render('administrateur/formateur/satisfaction/assignment/index.html.twig', [
      'entite' => $entite,
      'utilisateurEntite' => $ue,
      'sessions' => $sessions,
      'title' => 'Affectations formateur (satisfaction)',
    ]);
  }

  #[Route('/ajax', name: 'ajax', methods: ['POST'])]
  public function ajax(Entite $entite, Request $req, EM $em, CsrfTokenManagerInterface $csrfManager): JsonResponse
  {
    $draw   = (int) $req->request->get('draw', 1);
    $start  = max(0, (int) $req->request->get('start', 0));
    $length = max(10, (int) $req->request->get('length', 10));

    $searchArr = (array) $req->request->all('search');
    $search = trim((string) ($searchArr['value'] ?? ''));

    $requiredFilter  = (string) $req->request->get('requiredFilter', 'all');
    $statusFilter    = (string) $req->request->get('statusFilter', 'all');
    $sessionFilter   = (string) $req->request->get('sessionFilter', 'all');
    $formateurText   = trim((string) $req->request->get('formateurFilter', ''));

    $qb = $em->createQueryBuilder()
      ->select('a')
      ->from(FormateurSatisfactionAssignment::class, 'a')
      ->join('a.session', 's')
      ->join('a.formateur', 'u')
      ->join('a.template', 'tpl')
      ->leftJoin('a.attempt', 't')
      ->leftJoin('s.formation', 'fo')
      ->andWhere('s.entite = :e')->setParameter('e', $entite);

    if ($requiredFilter === 'yes') $qb->andWhere('a.isRequired = true');
    elseif ($requiredFilter === 'no') $qb->andWhere('a.isRequired = false');

    if ($statusFilter === 'submitted') {
      $qb->andWhere('t.submittedAt IS NOT NULL');
    } elseif ($statusFilter === 'started') {
      $qb->andWhere('t.startedAt IS NOT NULL')->andWhere('t.submittedAt IS NULL');
    } elseif ($statusFilter === 'not_started') {
      $qb->andWhere('t.id IS NULL OR t.startedAt IS NULL');
    }

    if ($sessionFilter !== 'all' && ctype_digit($sessionFilter)) {
      $qb->andWhere('s.id = :sid')->setParameter('sid', (int) $sessionFilter);
    }

    if ($formateurText !== '') {
      $qb->andWhere('LOWER(u.nom) LIKE :f OR LOWER(u.prenom) LIKE :f OR LOWER(u.email) LIKE :f')
        ->setParameter('f', '%' . mb_strtolower($formateurText) . '%');
    }

    if ($search !== '') {
      $qb->andWhere('LOWER(u.nom) LIKE :s OR LOWER(u.prenom) LIKE :s OR LOWER(u.email) LIKE :s OR LOWER(tpl.titre) LIKE :s OR LOWER(fo.titre) LIKE :s')
        ->setParameter('s', '%' . mb_strtolower($search) . '%');
    }

    $recordsTotal = (int) $em->createQueryBuilder()
      ->select('COUNT(DISTINCT a2.id)')
      ->from(FormateurSatisfactionAssignment::class, 'a2')
      ->join('a2.session', 's2')
      ->andWhere('s2.entite = :e')->setParameter('e', $entite)
      ->getQuery()->getSingleScalarResult();

    $qbCountFiltered = clone $qb;
    $qbCountFiltered->resetDQLPart('select')->resetDQLPart('orderBy');
    $qbCountFiltered->select('COUNT(DISTINCT a.id)');
    $recordsFiltered = (int) $qbCountFiltered->getQuery()->getSingleScalarResult();

    $order = (array) ($req->request->all('order')[0] ?? ['column' => 0, 'dir' => 'desc']);
    $col = (int) ($order['column'] ?? 0);
    $dir = strtolower((string) ($order['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

    $columns = [
      0 => 'a.id',
      1 => 'u.nom',
      2 => 'u.email',
      3 => 's.id',
      4 => 'fo.titre',
      5 => 'tpl.titre',
      6 => 'a.isRequired',
      7 => 't.submittedAt',
      8 => 'a.createdAt',
      9 => 't.startedAt',
      10 => 't.submittedAt',
      11 => 'a.id',
    ];
    $qb->orderBy($columns[$col] ?? 'a.id', $dir)->addOrderBy('a.id', 'DESC');
    $qb->setFirstResult($start)->setMaxResults($length);

    /** @var FormateurSatisfactionAssignment[] $rows */
    $rows = $qb->getQuery()->getResult();

    $data = [];
    foreach ($rows as $a) {
      $u = $a->getFormateur();
      $s = $a->getSession();
      $fo = $s?->getFormation();
      $tpl = $a->getTemplate();
      $t = $a->getAttempt();

      $started = $t?->getStartedAt();
      $submitted = $t?->getSubmittedAt();

      $requiredBadge = $a->isRequired()
        ? '<span class="badge bg-warning text-dark">Obligatoire</span>'
        : '<span class="badge bg-secondary">Optionnel</span>';

      $status = $submitted
        ? '<span class="badge bg-success">Soumis</span>'
        : ($started ? '<span class="badge bg-primary">En cours</span>' : '<span class="badge bg-light text-dark">Non démarré</span>');

      $canFill = $s ? $this->access->canFill($s) : false;
      $windowBadge = $canFill
        ? '<span class="badge bg-info text-dark">Ouvert</span>'
        : '<span class="badge bg-dark">Fermé</span>';

      $toggleUrl = $this->generateUrl('app_administrateur_formateur_satisfaction_assignment_toggle_required', [
        'entite' => $entite->getId(),
        'assignment' => $a->getId(),
      ]);

      $forceAttemptUrl = $this->generateUrl('app_administrateur_formateur_satisfaction_assignment_force_attempt', [
        'entite' => $entite->getId(),
        'assignment' => $a->getId(),
      ]);

      $deleteUrl = $this->generateUrl('app_administrateur_formateur_satisfaction_assignment_delete', [
        'entite' => $entite->getId(),
        'assignment' => $a->getId(),
      ]);

      $showAttemptUrl = ($t && $t->getId())
        ? $this->generateUrl('app_administrateur_formateur_satisfaction_attempt_show', [
          'entite' => $entite->getId(),
          'attempt' => $t->getId(),
        ])
        : null;

      $csrf = $csrfManager->getToken('del_fsat_assignment_' . $a->getId())->getValue();

      $hasAttempt = (bool) ($t && $t->getId());

      $showAttemptUrl = $hasAttempt
        ? $this->generateUrl('app_administrateur_formateur_satisfaction_attempt_show', [
          'entite' => $entite->getId(),
          'attempt' => $t->getId(),
        ])
        : null;

      $actionsProps = [
        'id'         => $a->getId(),
        'hasAttempt' => $hasAttempt,
        'showUrl'    => $showAttemptUrl,
        'forceUrl'   => $forceAttemptUrl,
        'toggleUrl'  => $toggleUrl,
        'deleteUrl'  => $deleteUrl,
        'csrf'       => $csrf,
      ];

      $actionsHtml = $this->twig->render('administrateur/formateur/satisfaction/assignment/_actions.html.twig', [
        'entite' => $entite,
        'a'      => $actionsProps,
      ]);



      $sessionLabel = method_exists($s, 'getCode') && $s?->getCode()
        ? $s->getCode()
        : ('Session #' . ($s?->getId() ?? '—'));

      $data[] = [
        'id' => $a->getId(),
        'formateur' => htmlspecialchars(trim(($u?->getPrenom() ?? '') . ' ' . ($u?->getNom() ?? ''))),
        'email' => htmlspecialchars($u?->getEmail() ?? ''),
        'session' => htmlspecialchars($sessionLabel),
        'formation' => htmlspecialchars($fo?->getTitre() ?? '—'),
        'template' => htmlspecialchars($tpl?->getTitre() ?? ''),
        'required' => $requiredBadge,
        'status' => $status,
        'window' => $windowBadge,
        'createdAt' => $a->getCreatedAt()?->format('d/m/Y H:i') ?? '—',
        'startedAt' => $started?->format('d/m/Y H:i') ?? '—',
        'submittedAt' => $submitted?->format('d/m/Y H:i') ?? '—',
        'actions' => $actionsHtml,
      ];
    }

    return new JsonResponse([
      'draw' => $draw,
      'recordsTotal' => $recordsTotal,
      'recordsFiltered' => $recordsFiltered,
      'data' => $data,
    ]);
  }

  #[Route('/{assignment}/force-attempt', name: 'force_attempt', methods: ['POST'], requirements: ['assignment' => '\d+'])]
  public function forceAttempt(Entite $entite, FormateurSatisfactionAssignment $assignment, EM $em): JsonResponse
  {
    if ($assignment->getSession()?->getEntite()?->getId() !== $entite->getId()) {
      return new JsonResponse(['ok' => false, 'message' => 'Not found'], 404);
    }

    /** @var Utilisateur $user */
    $user = $this->getUser();

    $attempt = $this->assigner->ensureAttempt($assignment, $user, $entite);
    $em->flush();

    return new JsonResponse([
      'ok' => true,
      'attemptId' => $attempt->getId(),
      'startedAt' => $attempt->getStartedAt()?->format('d/m/Y H:i'),
    ]);
  }

  #[Route('/{assignment}/toggle-required', name: 'toggle_required', methods: ['POST'], requirements: ['assignment' => '\d+'])]
  public function toggleRequired(Entite $entite, FormateurSatisfactionAssignment $assignment, EM $em): JsonResponse
  {
    if ($assignment->getSession()?->getEntite()?->getId() !== $entite->getId()) {
      return new JsonResponse(['ok' => false], 404);
    }

    $assignment->setIsRequired(!$assignment->isRequired());
    $em->flush();

    return new JsonResponse(['ok' => true, 'required' => $assignment->isRequired()]);
  }

  #[Route('/{assignment}/delete', name: 'delete', methods: ['POST'], requirements: ['assignment' => '\d+'])]
  public function delete(Entite $entite, FormateurSatisfactionAssignment $assignment, EM $em, Request $req): Response
  {
    if ($assignment->getSession()?->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException();
    }

    if (!$this->isCsrfTokenValid('del_fsat_assignment_' . $assignment->getId(), (string) $req->request->get('_token'))) {
      $this->addFlash('danger', 'Token CSRF invalide.');
      return $this->redirectToRoute('app_administrateur_formateur_satisfaction_assignment_index', [
        'entite' => $entite->getId()
      ]);
    }

    $em->remove($assignment);
    $em->flush();

    $this->addFlash('success', 'Affectation supprimée.');
    return $this->redirectToRoute('app_administrateur_formateur_satisfaction_assignment_index', [
      'entite' => $entite->getId()
    ]);
  }
}
