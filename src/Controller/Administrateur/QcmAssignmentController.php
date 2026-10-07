<?php

declare(strict_types=1);

namespace App\Controller\Administrateur;

use App\Service\Filter\ChoiceFilter;


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
  #[Route('/{id}/retirer', name: 'remove', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
  public function remove(Entite $entite, QcmAssignment $assignment, Request $request, EM $em): Response
  {
    if ($assignment->getEntite()?->getId() !== $entite->getId()
        || $assignment->getSession()?->getEntite()?->getId() !== $entite->getId()
        || $assignment->getInscription()?->getEntite()?->getId() !== $entite->getId()) throw $this->createNotFoundException();
    if ($request->isMethod('POST')) {
      if (!$this->isCsrfTokenValid('remove_qcm_assignment_'.$assignment->getId(), $request->request->get('_token'))) throw $this->createAccessDeniedException();
      $sessionId = $assignment->getSession()->getId();
      $assignment->getInscription()->excludeAutomaticQcm($assignment->getPhase());
      $em->remove($assignment);
      $em->flush();
      $this->addFlash('success', 'Affectation retirée. Elle ne sera plus recréée automatiquement pour ce stagiaire et cette phase.');
      return $this->redirectToRoute('app_administrateur_session_show', ['entite' => $entite->getId(), 'id' => $sessionId]);
    }
    return $this->render('administrateur/qcm/assignment/remove.html.twig', ['entite' => $entite, 'assignment' => $assignment]);
  }

  #[Route('/actions-groupees', name: 'batch', methods: ['POST'])]
  public function batch(Entite $entite, Request $request, EM $em): JsonResponse
  {
    if (!$this->isCsrfTokenValid('qcm_batch_'.$entite->getId(), $request->request->get('_token'))) return $this->json(['ok' => false, 'message' => 'Formulaire expiré. Rechargez la page.'], 403);
    $ids = json_decode((string) $request->request->get('ids', '[]'), true);
    $action = $request->request->get('action');
    if (!is_array($ids) || !$ids || count($ids) > 500 || !in_array($action, ['remove', 'attempt'], true)) return $this->json(['ok' => false, 'message' => 'Sélection invalide.'], 400);
    foreach ($ids as $id) if (!is_int($id) || $id <= 0) return $this->json(['ok' => false, 'message' => 'Identifiant invalide.'], 400);
    $ids = array_values(array_unique($ids));
    $count = $em->wrapInTransaction(function () use ($ids, $entite, $action, $em): int {
      $items = $em->getRepository(QcmAssignment::class)->findBy(['id' => $ids, 'entite' => $entite]);
      if (count($items) !== count($ids)) throw $this->createNotFoundException('Une affectation est indisponible. Rechargez la liste.');
      foreach ($items as $item) {
        if ($item->getSession()?->getEntite()?->getId() !== $entite->getId() || $item->getInscription()?->getEntite()?->getId() !== $entite->getId()) throw $this->createNotFoundException();
      }
      $changed = 0;
      foreach ($items as $item) {
        if ($action === 'remove') {
          $item->getInscription()->excludeAutomaticQcm($item->getPhase());
          $em->remove($item);
          ++$changed;
        } elseif (!$item->getAttempt()) {
          $this->assigner->ensureAttempt($item, $this->getUser(), $entite);
          ++$changed;
        }
      }
      return $changed;
    });
    return $this->json(['ok' => true, 'changed' => $count]);
  }

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

    foreach ([[$sessionFilter, 's.id', 'sid'], [$phaseFilter, 'a.phase', 'phase'], [$statusFilter, 'a.status', 'st']] as [$raw, $field, $prefix]) {
      $values = ChoiceFilter::values($raw);
      if ($values === null) continue;
      if (!$values) { $where .= ' AND 1 = 0'; continue; }
      $placeholders = [];
      foreach ($values as $index => $value) {
        $name = $prefix . '_' . $index;
        $placeholders[] = ':' . $name;
        $params[$name] = $value;
      }
      $where .= ' AND ' . $field . ' IN (' . implode(', ', $placeholders) . ')';
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
        t.id AS attempt_id, t.submitted_at AS attempt_submitted_at, t.score_points, t.max_points, t.score_percent
      FROM qcm_assignment a
      INNER JOIN session s ON s.id = a.session_id
      INNER JOIN inscription i ON i.id = a.inscription_id
      INNER JOIN utilisateur u ON u.id = i.stagiaire_id
      INNER JOIN qcm q ON q.id = a.qcm_id
      LEFT JOIN qcm_attempt t ON t.assignment_id = a.id
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
        'assignmentId' => $id,
        'hasAttempt' => (bool) $attemptId,
        'id' => '<span class="mini-chip"><i class="bi bi-hash"></i> ' . $id . '</span>',
        'stagiaire' => htmlspecialchars(trim(($r['prenom'] ?? '') . ' ' . ($r['nom'] ?? '')) ?: '—', ENT_QUOTES),
        'email' => htmlspecialchars((string)($r['email'] ?? '—'), ENT_QUOTES),
        'session' => '<span class="mini-chip"><i class="bi bi-calendar3"></i> #' . ((int)$r['sid']) . '</span>',
        'qcm' => htmlspecialchars((string)($r['qtitre'] ?? ''), ENT_QUOTES),
        'phase' => $phaseHtml,
        'status' => $statusHtml,
        'result' => $r['attempt_submitted_at'] && (int)$r['max_points'] > 0
          ? '<strong>' . number_format((float)$r['score_percent'], 1, ',', ' ') . ' %</strong><div class="small text-muted">' . (int)$r['score_points'] . ' / ' . (int)$r['max_points'] . ' points</div>'
          : '<span class="text-muted">' . ($r['attempt_submitted_at'] ? 'Non noté' : 'À compléter') . '</span>',
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
