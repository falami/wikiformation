<?php

namespace App\Controller\Stagiaire;

use App\Entity\Entite;
use App\Entity\Utilisateur;
use App\Entity\Elearning\ElearningEnrollment;
use Doctrine\ORM\EntityManagerInterface as EM;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse};
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use App\Entity\Elearning\ElearningCourse;
use App\Entity\Elearning\ElearningNode;
use App\Entity\Elearning\ElearningNodeProgress;
use App\Service\Elearning\ElearningProgressCalculator;
use App\Security\Permission\TenantPermission;


#[Route('/stagiaire/{entite}/elearning', name: 'app_stagiaire_elearning_')]
#[IsGranted(TenantPermission::STAGIAIRE_ELEARNING_MANAGE, subject: 'entite')]
final class ElearningStagiaireController extends AbstractController
{
  private const TZ = 'Europe/Paris';

  public function __construct(
    private UtilisateurEntiteManager $utilisateurEntiteManager,
    private EM $em

  ) {}

  #[Route('', name: 'index', methods: ['GET'])]
  public function index(Entite $entite): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();
    return $this->render('stagiaire/elearning/index.html.twig', [
      'entite' => $entite,


    ]);
  }

  #[Route('/ajax', name: 'ajax', methods: ['POST'])]
  public function ajax(Entite $entite, Request $request): JsonResponse
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    $draw  = (int) $request->request->get('draw', 1);
    $start = (int) $request->request->get('start', 0);
    $len   = (int) $request->request->get('length', 10);

    $search = trim((string) ($request->request->all('search')['value'] ?? ''));

    $qb = $this->em->createQueryBuilder()
      ->select('e', 'c')
      ->from(ElearningEnrollment::class, 'e')
      ->join('e.course', 'c')
      ->andWhere('e.entite = :entite')
      ->andWhere('e.stagiaire = :u')
      ->setParameter('entite', $entite)
      ->setParameter('u', $user);

    $qbCount = clone $qb;
    $qbCount->select('COUNT(e.id)');
    $recordsTotal = (int) $qbCount->getQuery()->getSingleScalarResult();

    if ($search !== '') {
      $qb->andWhere('LOWER(c.titre) LIKE :q')
        ->setParameter('q', '%' . mb_strtolower($search) . '%');
    }

    $qbFiltered = clone $qb;
    $qbFiltered->select('COUNT(e.id)');
    $recordsFiltered = (int) $qbFiltered->getQuery()->getSingleScalarResult();

    $qb->orderBy('e.id', 'DESC')->setFirstResult($start)->setMaxResults($len);

    $p = new Paginator($qb->getQuery(), true);

    $now = new \DateTimeImmutable('now', new \DateTimeZone(self::TZ));
    $rows = [];

    foreach ($p as $enroll) {
      /** @var ElearningEnrollment $enroll */
      $course = $enroll->getCourse();

      $state = method_exists($enroll, 'getComputedState') ? $enroll->getComputedState($now) : null;
      $label = method_exists($enroll, 'getComputedStateLabel') ? $enroll->getComputedStateLabel($now) : $enroll->getStatus()->value;

      $statusHtml = $this->renderView('stagiaire/elearning/_badge.html.twig', [
        'state' => $state,
        'label' => $label,
      ]);

      $progressHtml = $this->renderView('stagiaire/elearning/_progress.html.twig', [
        'pct' => (int) $enroll->getProgressPct(),
      ]);

      $rows[] = [
        'course' => $this->renderView('stagiaire/elearning/_course_cell.html.twig', [
          'course' => $course,
        ]),
        'status' => $statusHtml,
        'window' => $this->renderView('stagiaire/elearning/_window.html.twig', [
          'startsAt' => $enroll->getStartsAt(),
          'endsAt'   => $enroll->getEndsAt(),
        ]),
        'progress' => $progressHtml,
        'actions' => $this->renderView('stagiaire/elearning/_actions.html.twig', [
          'entite' => $entite,
          'course' => $course,
          'state'  => $state,
        ]),
      ];
    }

    return $this->json([
      'draw' => $draw,
      'recordsTotal' => $recordsTotal,
      'recordsFiltered' => $recordsFiltered,
      'data' => $rows,
    ]);
  }


  #[Route('/course/{course}', name: 'course_show', methods: ['GET'], requirements: ['course' => '\d+'])]
  public function courseShow(Entite $entite, ElearningCourse $course): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    // Sécurité : le cours doit appartenir à l'entité
    if ($course->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException('Cours introuvable.');
    }

    // Sécurité : le stagiaire doit avoir une inscription
    $enroll = $this->em->getRepository(ElearningEnrollment::class)->findOneBy([
      'entite'    => $entite,
      'course'    => $course,
      'stagiaire' => $user,
    ]);

    if (!$enroll) {
      throw $this->createAccessDeniedException("Vous n'avez pas accès à ce cours.");
    }

    // Pour un rendu premium, on précharge nodes + children + blocks (évite N+1)
    $qb = $this->em->createQueryBuilder()
      ->select('c', 'n', 'ch', 'b', 'q')
      ->from(ElearningCourse::class, 'c')
      ->leftJoin('c.nodes', 'n')
      ->leftJoin('n.children', 'ch')
      ->leftJoin('n.blocks', 'b')
      ->leftJoin('b.quiz', 'q')
      ->andWhere('c.id = :cid')
      ->setParameter('cid', $course->getId());

    /** @var ElearningCourse $courseFull */
    $courseFull = $qb->getQuery()->getOneOrNullResult();

    if (!$courseFull) {
      throw $this->createNotFoundException('Cours introuvable.');
    }


    // Liste des node_id complétés pour cet enrollment
    $completedNodeIds = $this->em->createQueryBuilder()
      ->select('IDENTITY(pr.node) AS nid')
      ->from(ElearningNodeProgress::class, 'pr')
      ->andWhere('pr.enrollment = :e')
      ->andWhere('pr.completedAt IS NOT NULL')
      ->setParameter('e', $enroll)
      ->getQuery()
      ->getScalarResult();

    $completedNodeIds = array_map(static fn($r) => (int) $r['nid'], $completedNodeIds);


    return $this->render('stagiaire/elearning/course_show.html.twig', [
      'entite' => $entite,
      'course' => $courseFull,
      'enroll' => $enroll,
      'completedNodeIds' => $completedNodeIds,
      'progressPct' => (int) $enroll->getProgressPct(),


    ]);
  }


  #[Route('/course/{course}/node/{node}/toggle-complete', name: 'node_toggle_complete', methods: ['POST'], requirements: ['course' => '\d+', 'node' => '\d+'])]
  public function toggleNodeComplete(
    Entite $entite,
    ElearningCourse $course,
    ElearningNode $node,
    Request $request,
    ElearningProgressCalculator $calc
  ): JsonResponse {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    // Sécurité entité/cours/node
    if ($course->getEntite()?->getId() !== $entite->getId()) {
      return $this->json(['ok' => false, 'message' => 'Cours invalide.'], 404);
    }
    if ($node->getCourse()?->getId() !== $course->getId()) {
      return $this->json(['ok' => false, 'message' => 'Chapitre invalide.'], 404);
    }

    // Enrollment
    $enroll = $this->em->getRepository(ElearningEnrollment::class)->findOneBy([
      'entite'    => $entite,
      'course'    => $course,
      'stagiaire' => $user,
    ]);
    if (!$enroll) {
      return $this->json(['ok' => false, 'message' => 'Accès refusé.'], 403);
    }

    // CSRF
    $token = (string) $request->request->get('_token', '');
    if (!$this->isCsrfTokenValid('toggle_node_' . $node->getId(), $token)) {
      return $this->json(['ok' => false, 'message' => 'CSRF invalide.'], 400);
    }

    // Progress record
    $repo = $this->em->getRepository(ElearningNodeProgress::class);

    /** @var ElearningNodeProgress|null $pr */
    $pr = $repo->findOneBy(['enrollment' => $enroll, 'node' => $node]);

    if (!$pr) {
      $pr = new ElearningNodeProgress();
      $pr->setEnrollment($enroll);
      $pr->setNode($node);
      $this->em->persist($pr);
    }

    $completed = !$pr->isCompleted();
    if ($completed) {
      $pr->markCompleted();
    } else {
      $pr->markIncomplete();
    }

    // Recalc %
    $calc->recomputeEnrollmentProgress($enroll);

    $this->em->flush();

    return $this->json([
      'ok' => true,
      'completed' => $completed,
      'progressPct' => (int) $enroll->getProgressPct(),
    ]);
  }
}
