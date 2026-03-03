<?php

declare(strict_types=1);

namespace App\Controller\Administrateur;


use App\Entity\{QcmAssignment, Entite, Utilisateur, Session, Qcm};
use App\Service\Qcm\QcmAssigner;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment as Twig;
use App\Security\Permission\TenantPermission;



#[Route('/administrateur/{entite}/qcm/assignments', name: 'app_administrateur_qcm_assignment_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::QCM_ASSIGNMENT_MANAGE, subject: 'entite')]
final class QcmAssignmentController extends AbstractController
{
  public function __construct(
    private UtilisateurEntiteManager $uem,
    private QcmAssigner $assigner,
    private Twig $twig,
  ) {}

  #[Route('', name: 'index', methods: ['GET'])]
  public function index(Entite $entite, EM $em): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    $ue = $this->uem->getUserEntiteLink($entite);
    if (!$ue) throw $this->createAccessDeniedException('Accès interdit à cette entité.');

    // sessions (pour filtre)
    $sessions = $em->getRepository(Session::class)->findBy(['entite' => $entite], ['id' => 'DESC']);

    // qcms actifs (pour affectation)
    $qcms = $em->getRepository(Qcm::class)->findBy(['entite' => $entite, 'isActive' => true], ['id' => 'DESC']);

    return $this->render('administrateur/qcm/assignment/index.html.twig', [
      'entite' => $entite,
      'utilisateurEntite' => $ue,
      'sessions' => $sessions,
      'qcms' => $qcms,
      'title' => 'Affectations QCM (Pré / Post)',
    ]);
  }

  #[Route('/ajax', name: 'ajax', methods: ['POST'])]
  public function ajax(Entite $entite, Request $req, EM $em): JsonResponse
  {
    $draw  = (int) $req->request->get('draw', 1);
    $start = max(0, (int) $req->request->get('start', 0));
    $len   = (int) $req->request->get('length', 25);
    $len   = ($len <= 0) ? 25 : min($len, 500);

    $search = trim((string) (($req->request->all('search')['value'] ?? '') ?? ''));
    $sessionFilter = (string) $req->request->get('sessionFilter', 'all');
    $phaseFilter   = (string) $req->request->get('phaseFilter', 'all');   // all|pre|post
    $statusFilter  = (string) $req->request->get('statusFilter', 'all');  // all|assigned|started|submitted|review_required|validated
    $stagiaireFilter = trim((string) $req->request->get('stagiaireFilter', ''));

    $conn = $em->getConnection();

    $params = ['entite' => $entite->getId()];
    $where = "s.entite_id = :entite";

    if ($sessionFilter !== 'all') {
      $where .= " AND s.id = :sid";
      $params['sid'] = (int)$sessionFilter;
    }

    if ($phaseFilter === 'pre' || $phaseFilter === 'post') {
      $where .= " AND a.phase = :phase";
      $params['phase'] = $phaseFilter;
    }

    if ($statusFilter !== 'all') {
      $where .= " AND a.status = :st";
      $params['st'] = $statusFilter;
    }

    if ($stagiaireFilter !== '') {
      $where .= " AND (u.nom LIKE :sf OR u.prenom LIKE :sf OR u.email LIKE :sf)";
      $params['sf'] = '%' . $stagiaireFilter . '%';
    }

    if ($search !== '') {
      $where .= " AND (
        CAST(a.id AS CHAR) LIKE :q OR
        q.titre LIKE :q OR
        u.nom LIKE :q OR
        u.prenom LIKE :q OR
        u.email LIKE :q
      )";
      $params['q'] = '%' . $search . '%';
    }

    $total = (int) $conn->fetchOne(
      "SELECT COUNT(*) 
       FROM qcm_assignment a
       INNER JOIN session s ON s.id = a.session_id
       WHERE s.entite_id = :entite",
      ['entite' => $entite->getId()]
    );

    $filtered = (int) $conn->fetchOne(
      "SELECT COUNT(*)
       FROM qcm_assignment a
       INNER JOIN session s ON s.id = a.session_id
       INNER JOIN inscription i ON i.id = a.inscription_id
       INNER JOIN utilisateur u ON u.id = i.stagiaire_id
       INNER JOIN qcm q ON q.id = a.qcm_id
       WHERE $where",
      $params
    );

    $orderCol = (int) ($req->request->all('order')[0]['column'] ?? 0);
    $orderDir = strtolower((string) ($req->request->all('order')[0]['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
    $columns = [
      0 => 'a.id',
      1 => 'u.nom',
      2 => 'u.email',
      3 => 's.id',
      4 => 'q.titre',
      5 => 'a.phase',
      6 => 'a.status',
      7 => 'a.assigned_at',
      8 => 'a.submitted_at',
    ];
    $orderBy = $columns[$orderCol] ?? 'a.id';

    $sql = "
      SELECT
        a.id,
        a.phase,
        a.status,
        a.assigned_at,
        a.submitted_at,
        u.id AS uid,
        u.prenom,
        u.nom,
        u.email,
        s.id AS sid,
        q.id AS qid,
        q.titre AS qtitre,
        (SELECT id FROM qcm_attempt t WHERE t.assignment_id = a.id LIMIT 1) AS attempt_id
      FROM qcm_assignment a
      INNER JOIN session s ON s.id = a.session_id
      INNER JOIN inscription i ON i.id = a.inscription_id
      INNER JOIN utilisateur u ON u.id = i.stagiaire_id
      INNER JOIN qcm q ON q.id = a.qcm_id
      WHERE $where
      ORDER BY $orderBy $orderDir
      LIMIT :lim OFFSET :off
    ";

    $stmt = $conn->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue('lim', $len, \PDO::PARAM_INT);
    $stmt->bindValue('off', $start, \PDO::PARAM_INT);

    $rows = $stmt->executeQuery()->fetchAllAssociative();

    $data = array_map(function (array $r) use ($entite) {
      $id = (int)$r['id'];

      $phase = (string)$r['phase'];
      $phaseHtml = $phase === 'pre'
        ? '<span class="badge bg-info-subtle text-info border">PRE</span>'
        : '<span class="badge bg-warning-subtle text-warning border">POST</span>';

      $status = (string)$r['status'];
      $statusHtml = match ($status) {
        'assigned' => '<span class="badge bg-secondary-subtle text-secondary border">Assigné</span>',
        'started' => '<span class="badge bg-primary-subtle text-primary border">En cours</span>',
        'submitted' => '<span class="badge bg-success-subtle text-success border">Soumis</span>',
        'review_required' => '<span class="badge bg-warning-subtle text-warning border">À relire</span>',
        'validated' => '<span class="badge bg-success text-white">Validé</span>',
        default => '<span class="badge bg-light text-dark border">—</span>',
      };

      $attemptId = $r['attempt_id'] ? (int) $r['attempt_id'] : null;

      $showUrl = $attemptId
        ? $this->generateUrl('app_administrateur_qcm_attempt_show', [
          'entite'  => $entite->getId(),
          'attempt' => $attemptId,
        ])
        : null;

      $forceUrl = $this->generateUrl('app_administrateur_qcm_assignment_force_attempt', [
        'entite' => $entite->getId(),
        'id'     => $id,
      ]);

      $actionsProps = [
        'id'         => $id,
        'hasAttempt' => (bool) $attemptId,
        'showUrl'    => $showUrl,
        'forceUrl'   => $forceUrl,
      ];

      $actionsHtml = $this->twig->render('administrateur/qcm/assignment/_actions.html.twig', [
        'entite' => $entite,
        'a'      => $actionsProps,
      ]);


      return [
        'id' => '<span class="mini-chip"><i class="bi bi-hash"></i> ' . $id . '</span>',
        'stagiaire' => htmlspecialchars(trim(($r['prenom'] ?? '') . ' ' . ($r['nom'] ?? '')) ?: '—', ENT_QUOTES),
        'email' => htmlspecialchars((string)($r['email'] ?? '—'), ENT_QUOTES),
        'session' => '<span class="mini-chip"><i class="bi bi-calendar3"></i> #' . ((int)$r['sid']) . '</span>',
        'qcm' => htmlspecialchars((string)($r['qtitre'] ?? ''), ENT_QUOTES),
        'phase' => $phaseHtml,
        'status' => $statusHtml,
        'assignedAt' => $r['assigned_at'] ? (new \DateTimeImmutable($r['assigned_at']))->format('d/m/Y H:i') : '—',
        'submittedAt' => $r['submitted_at'] ? (new \DateTimeImmutable($r['submitted_at']))->format('d/m/Y H:i') : '—',
        'actions' => $actionsHtml,
      ];
    }, $rows);

    return $this->json([
      'draw' => $draw,
      'recordsTotal' => $total,
      'recordsFiltered' => $filtered,
      'data' => $data,
    ]);
  }

  #[Route('/assign/session/{session}', name: 'assign_session', methods: ['POST'], requirements: ['session' => '\d+'])]
  public function assignSession(Entite $entite, Session $session, Request $req, EM $em): JsonResponse
  {
    // sécurité entité
    if ($session->getEntite()?->getId() !== $entite->getId()) throw $this->createNotFoundException();


    /** @var Utilisateur $user */
    $user = $this->getUser();
    $qcmPreId  = (int) $req->request->get('qcmPreId', 0);
    $qcmPostId = (int) $req->request->get('qcmPostId', 0);

    if (!$qcmPreId || !$qcmPostId) {
      return $this->json(['ok' => false, 'message' => 'Sélectionne un QCM PRE et un QCM POST.'], 400);
    }

    $qcmPre  = $em->getRepository(Qcm::class)->find($qcmPreId);
    $qcmPost = $em->getRepository(Qcm::class)->find($qcmPostId);

    if (!$qcmPre || !$qcmPost) return $this->json(['ok' => false, 'message' => 'QCM introuvable.'], 404);
    if ($qcmPre->getEntite()?->getId() !== $entite->getId() || $qcmPost->getEntite()?->getId() !== $entite->getId()) {
      return $this->json(['ok' => false, 'message' => 'QCM non lié à cette entité.'], 403);
    }

    $created = $this->assigner->assignForSession($session, $qcmPre, $qcmPost, $user, $entite);
    $em->flush();

    return $this->json(['ok' => true, 'created' => $created]);
  }

  #[Route('/{id}/force-attempt', name: 'force_attempt', methods: ['POST'], requirements: ['id' => '\d+'])]
  public function forceAttempt(Entite $entite, QcmAssignment $assignment, EM $em): JsonResponse
  {
    if ($assignment->getSession()?->getEntite()?->getId() !== $entite->getId()) throw $this->createNotFoundException();

    /** @var Utilisateur $user */
    $user = $this->getUser();
    $this->assigner->ensureAttempt($assignment, $user, $entite);
    $em->flush();

    return $this->json(['ok' => true]);
  }
}
