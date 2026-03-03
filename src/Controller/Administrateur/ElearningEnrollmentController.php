<?php

namespace App\Controller\Administrateur;

use App\Entity\Entite;
use App\Entity\Utilisateur;
use App\Entity\Elearning\ElearningCourse;
use App\Entity\Elearning\ElearningEnrollment;
use App\Enum\EnrollmentStatus;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use Doctrine\ORM\EntityManagerInterface as EM;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse, RedirectResponse};
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Form\Administrateur\ElearningAssignType;
use App\Service\Elearning\ElearningEnrollmentManager;
use Doctrine\ORM\EntityManagerInterface;
use App\Security\Permission\TenantPermission;



#[Route('/administrateur/{entite}/elearning/{course}/inscriptions', name: 'app_administrateur_elearning_enrollment_')]
#[IsGranted(TenantPermission::ELEARNING_ENROLLMENT_MANAGE, subject: 'entite')]
final class ElearningEnrollmentController extends AbstractController
{
  private const TZ = 'Europe/Paris';

  public function __construct(
    private UtilisateurEntiteManager $utilisateurEntiteManager,
    private EM $em
  ) {}

  #[Route('', name: 'index', methods: ['GET'])]
  public function index(Entite $entite, ElearningCourse $course): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    return $this->render('administrateur/elearning/enrollments.html.twig', [
      'entite' => $entite,
      'course' => $course,


    ]);
  }

  #[Route('/ajax', name: 'ajax', methods: ['POST'])]
  public function ajax(Entite $entite, ElearningCourse $course, Request $request): JsonResponse
  {
    $draw   = (int) $request->request->get('draw', 1);
    $start  = (int) $request->request->get('start', 0);
    $len    = (int) $request->request->get('length', 10);

    $search = trim((string) ($request->request->all('search')['value'] ?? ''));
    $stateFilter = trim((string) $request->request->get('state', ''));


    $orderReq = $request->request->all('order')[0] ?? null;
    $colIndex = isset($orderReq['column']) ? (int) $orderReq['column'] : 0;
    $dir      = strtolower($orderReq['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

    // mapping colonnes DataTables => champs DQL
    $orderMap = [
      0 => 'e.id',
      1 => 'u.email',
      2 => 'e.status',
      3 => 'e.startsAt',
      4 => 'e.endsAt',
      5 => 'e.progressPct',
    ];
    $orderBy = $orderMap[$colIndex] ?? 'e.id';

    $qb = $this->em->createQueryBuilder()
      ->select('e', 'u')
      ->from(ElearningEnrollment::class, 'e')
      ->join('e.stagiaire', 'u')
      ->andWhere('e.entite = :entite')
      ->andWhere('e.course = :course')
      ->setParameter('entite', $entite)
      ->setParameter('course', $course);

    // total
    $qbCount = clone $qb;
    $qbCount->select('COUNT(e.id)');
    $recordsTotal = (int) $qbCount->getQuery()->getSingleScalarResult();

    // filtre recherche
    if ($search !== '') {
      // adapte selon tes champs Utilisateur
      $qb->andWhere('LOWER(u.email) LIKE :q OR LOWER(u.nom) LIKE :q OR LOWER(u.prenom) LIKE :q')
        ->setParameter('q', '%' . mb_strtolower($search) . '%');
    }

    // filtered
    $qbFiltered = clone $qb;
    $qbFiltered->select('COUNT(e.id)');
    $recordsFiltered = (int) $qbFiltered->getQuery()->getSingleScalarResult();

    // paging
    $qb->orderBy($orderBy, $dir)
      ->setFirstResult($start)
      ->setMaxResults($len);

    $paginator = new Paginator($qb->getQuery(), true);

    $now = new \DateTimeImmutable('now', new \DateTimeZone(self::TZ));

    $rows = [];
    foreach ($paginator as $enroll) {
      $state = $enroll->getComputedState($now);

      if ($stateFilter !== '' && $state !== $stateFilter) {
        continue;
      }
      /** @var ElearningEnrollment $enroll */
      $u = $enroll->getStagiaire();

      $fullName = trim((string) (($u->getPrenom() ?? '') . ' ' . ($u->getNom() ?? '')));
      $identity = $fullName !== '' ? $fullName : ($u->getEmail() ?? 'Stagiaire');

      $state = method_exists($enroll, 'getComputedState') ? $enroll->getComputedState($now) : null;
      $stateLabel = method_exists($enroll, 'getComputedStateLabel') ? $enroll->getComputedStateLabel($now) : $enroll->getStatus()->value;

      $statusHtml = $this->renderView('administrateur/elearning/_enrollment_badge.html.twig', [
        'state' => $state,
        'label' => $stateLabel,
      ]);

      $starts = $enroll->getStartsAt()?->format('d/m/Y H:i') ?? '—';
      $ends   = $enroll->getEndsAt()?->format('d/m/Y H:i') ?? '—';

      $progress = (int) $enroll->getProgressPct();
      $progressHtml = $this->renderView('administrateur/elearning/_enrollment_progress.html.twig', [
        'pct' => $progress,
      ]);

      $actionsHtml = $this->renderView('administrateur/elearning/_enrollment_actions.html.twig', [
        'entite' => $entite,
        'course' => $course,
        'enroll' => $enroll,
        'state'  => $state,
      ]);

      $rows[] = [
        'id'        => $enroll->getId(),
        'stagiaire' => $this->renderView('administrateur/elearning/_enrollment_usercell.html.twig', [
          'u' => $u,
          'identity' => $identity,
        ]),
        'status'    => $statusHtml,
        'startsAt'  => $starts,
        'endsAt'    => $ends,
        'progress'  => $progressHtml,
        'actions'   => $actionsHtml,
      ];
    }

    return $this->json([
      'draw' => $draw,
      'recordsTotal' => $recordsTotal,
      'recordsFiltered' => $recordsFiltered,
      'data' => $rows,
    ]);
  }

  #[Route('/update-dates', name: 'update_dates', methods: ['POST'])]
  public function updateDates(Entite $entite, ElearningCourse $course, Request $request): RedirectResponse
  {
    $id = (int) $request->request->get('id', 0);

    /** @var ElearningEnrollment|null $enroll */
    $enroll = $this->em->getRepository(ElearningEnrollment::class)->find($id);
    if (!$enroll || $enroll->getEntite()?->getId() !== $entite->getId() || $enroll->getCourse()?->getId() !== $course->getId()) {
      $this->addFlash('danger', 'Inscription introuvable.');
      return $this->redirectToRoute('app_administrateur_elearning_enrollment_index', [
        'entite' => $entite->getId(),
        'course' => $course->getId()
      ]);
    }

    $tz = new \DateTimeZone(self::TZ);

    $parse = function (?string $s) use ($tz): ?\DateTimeImmutable {
      $s = trim((string) $s);
      if ($s === '') return null;
      $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $s, $tz);
      return $dt ?: null;
    };

    $startsAt = $parse($request->request->get('startsAt'));
    $endsAt   = $parse($request->request->get('endsAt'));

    // option : sécurité si ends < starts
    if ($startsAt && $endsAt && $endsAt < $startsAt) {
      $this->addFlash('warning', 'La date de fin doit être après la date de début.');
      return $this->redirectToRoute('app_administrateur_elearning_enrollment_index', [
        'entite' => $entite->getId(),
        'course' => $course->getId()
      ]);
    }

    $enroll->setStartsAt($startsAt);
    $enroll->setEndsAt($endsAt);
    $this->em->flush();

    $this->addFlash('success', 'Dates mises à jour.');
    return $this->redirectToRoute('app_administrateur_elearning_enrollment_index', [
      'entite' => $entite->getId(),
      'course' => $course->getId()
    ]);
  }

  #[Route('/toggle-status/{enrollment}', name: 'toggle_status', methods: ['POST'])]
  public function toggleStatus(Entite $entite, ElearningCourse $course, ElearningEnrollment $enrollment, Request $request): JsonResponse
  {
    if (!$this->isCsrfTokenValid('toggle_enroll_' . $enrollment->getId(), (string) $request->request->get('_token'))) {
      return $this->json(['ok' => false, 'message' => 'CSRF invalide'], 400);
    }

    if ($enrollment->getEntite()?->getId() !== $entite->getId() || $enrollment->getCourse()?->getId() !== $course->getId()) {
      return $this->json(['ok' => false, 'message' => 'Accès refusé'], 403);
    }

    $enrollment->setStatus(
      $enrollment->getStatus() === EnrollmentStatus::ACTIVE
        ? EnrollmentStatus::SUSPENDED
        : EnrollmentStatus::ACTIVE
    );

    $this->em->flush();

    return $this->json(['ok' => true]);
  }

  #[Route('/delete/{enrollment}', name: 'delete', methods: ['POST'])]
  public function delete(Entite $entite, ElearningCourse $course, ElearningEnrollment $enrollment, Request $request): JsonResponse
  {
    if (!$this->isCsrfTokenValid('del_enroll_' . $enrollment->getId(), (string) $request->request->get('_token'))) {
      return $this->json(['ok' => false, 'message' => 'CSRF invalide'], 400);
    }

    if ($enrollment->getEntite()?->getId() !== $entite->getId() || $enrollment->getCourse()?->getId() !== $course->getId()) {
      return $this->json(['ok' => false, 'message' => 'Accès refusé'], 403);
    }

    $this->em->remove($enrollment);
    $this->em->flush();

    return $this->json(['ok' => true]);
  }


  #[Route('/attribuer', name: 'assign', methods: ['GET', 'POST'])]
  public function assign(
    Entite $entite,
    ElearningCourse $course,
    Request $request,
    EntityManagerInterface $em,
    ElearningEnrollmentManager $manager
  ): Response {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    $form = $this->createForm(ElearningAssignType::class, null, ['entite' => $entite])->handleRequest($request);
    if ($form->isSubmitted() && $form->isValid()) {
      /** @var Utilisateur $stagiaire */
      $stagiaire = $form->get('stagiaire')->getData();
      $startsAt  = $form->get('startsAt')->getData();
      $endsAt    = $form->get('endsAt')->getData();

      $manager->assignCourse($entite, $stagiaire, $course, $user, $startsAt, $endsAt);
      $this->addFlash('success', 'Accès e-learning attribué.');
      return $this->redirectToRoute('app_administrateur_elearning_index', [
        'entite' => $entite->getId(),
      ]);
    }

    return $this->render('administrateur/elearning/assign.html.twig', [
      'entite' => $entite,
      'course' => $course,
      'form' => $form->createView(),
    ]);
  }
}
