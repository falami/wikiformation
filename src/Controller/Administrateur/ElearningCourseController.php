<?php
// src/Controller/Administrateur/ElearningCourseAdminController.php
namespace App\Controller\Administrateur;

use App\Entity\Entite;
use App\Entity\Utilisateur;
use App\Entity\Elearning\ElearningOrder;
use App\Entity\Elearning\ElearningCourse;
use App\Form\Administrateur\ElearningCourseType;
use App\Service\Elearning\ElearningEnrollmentManager;
use App\Enum\OrderStatus;
use App\Service\Slug\UniqueSlugger;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Security\Permission\TenantPermission;



#[Route('/administrateur/{entite}/elearning', name: 'app_administrateur_elearning_')]
#[IsGranted(TenantPermission::ELEARNING_COURSE_MANAGE, subject: 'entite')]
final class ElearningCourseController extends AbstractController
{
  public function __construct(
    private UtilisateurEntiteManager $utilisateurEntiteManager
  ) {}

  #[Route('', name: 'index', methods: ['GET'])]
  public function index(Entite $entite, EntityManagerInterface $em): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    // ✅ serverSide => on ne charge PAS courses ici
    return $this->render('administrateur/elearning/index.html.twig', [
      'entite' => $entite,


    ]);
  }

  /**
   * ✅ Endpoint DataTables server-side
   */
  #[Route('/ajax', name: 'ajax', methods: ['POST'])]
  public function ajax(Entite $entite, Request $request, EntityManagerInterface $em): JsonResponse
  {
    $draw   = (int) $request->request->get('draw', 1);
    $start  = max(0, (int) $request->request->get('start', 0));
    $length = (int) $request->request->get('length', 25);
    $length = ($length <= 0) ? 25 : min($length, 500);

    $search = (string) ($request->request->all('search')['value'] ?? '');
    $order  = $request->request->all('order');
    $cols   = $request->request->all('columns');

    // Mapping des index DataTables -> champs DB
    $map = [
      0 => 'c.id',
      1 => 'c.titre',
      2 => 'c.isPublic',
      3 => 'c.isPublished',
      4 => 'c.prixCents',
    ];

    /** @var QueryBuilder $qb */
    $qb = $em->createQueryBuilder()
      ->select('c')
      ->from(ElearningCourse::class, 'c')
      ->andWhere('c.entite = :entite')
      ->setParameter('entite', $entite);

    // Total sans filtre
    $qbTotal = clone $qb;
    $recordsTotal = (int) $qbTotal
      ->select('COUNT(c.id)')
      ->resetDQLPart('orderBy')
      ->getQuery()
      ->getSingleScalarResult();

    // Filtre global (search)
    $search = trim($search);
    if ($search !== '') {
      $qb
        ->andWhere('LOWER(c.titre) LIKE :q OR LOWER(c.slug) LIKE :q')
        ->setParameter('q', '%' . mb_strtolower($search) . '%');
    }

    // Total filtré
    $qbFiltered = clone $qb;
    $recordsFiltered = (int) $qbFiltered
      ->select('COUNT(c.id)')
      ->resetDQLPart('orderBy')
      ->getQuery()
      ->getSingleScalarResult();

    // Order
    $orderColIdx = (int)($order[0]['column'] ?? 0);
    $orderDir    = strtolower((string)($order[0]['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
    $orderField  = $map[$orderColIdx] ?? 'c.id';
    $qb->orderBy($orderField, $orderDir);

    // Pagination
    $qb->setFirstResult($start)->setMaxResults($length);

    /** @var ElearningCourse[] $rows */
    $rows = $qb->getQuery()->getResult();

    // Data -> doit matcher EXACTEMENT le nombre de colonnes DataTables
    $data = [];
    foreach ($rows as $c) {
      $data[] = [
        'id' => $c->getId(),
        'titre' => sprintf(
          '<div class="fw-bold">%s</div><div class="small text-muted">%s</div>',
          htmlspecialchars((string)$c->getTitre(), ENT_QUOTES),
          htmlspecialchars((string)$c->getSlug(), ENT_QUOTES)
        ),
        'public' => $c->isPublic()
          ? '<span class="badge bg-success">Oui</span>'
          : '<span class="badge bg-secondary">Non</span>',
        'published' => $c->isPublished()
          ? '<span class="badge bg-success">Oui</span>'
          : '<span class="badge bg-warning text-dark">Non</span>',
        'prix' => sprintf(
          '<span class="fw-semibold">%s €</span>',
          number_format(((int)($c->getPrixCents() ?? 0)) / 100, 2, ',', ' ')
        ),
        'actions' => $this->renderView('administrateur/elearning/_actions.html.twig', [
          'entite' => $entite,
          'c' => $c,
        ]),
      ];
    }

    return $this->json([
      'draw' => $draw,
      'recordsTotal' => $recordsTotal,
      'recordsFiltered' => $recordsFiltered,
      'data' => $data,
    ]);
  }

  #[Route('/ajouter', name: 'add', methods: ['GET', 'POST'])]
  #[Route('/{id}/modifier', name: 'edit', methods: ['GET', 'POST'])]
  public function addEdit(
    Entite $entite,
    Request $request,
    EntityManagerInterface $em,
    UniqueSlugger $uniqueSlugger,
    ?ElearningCourse $course = null
  ): Response {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    $isEdit = (bool) $course;
    $course ??= (new ElearningCourse())
      ->setEntite($entite)
      ->setCreateur($user);

    $form = $this->createForm(ElearningCourseType::class, $course);
    $form->handleRequest($request);

    if ($form->isSubmitted()) {
      $course->setSlug(
        $uniqueSlugger->uniqueElearningCourseSlug(
          $entite->getId(),
          (string) $course->getTitre(),
          $course->getId()
        )
      );
    }

    if ($form->isSubmitted() && $form->isValid()) {
      $em->persist($course);
      $em->flush();

      return $this->redirectToRoute('app_administrateur_elearning_content_index', [
        'entite' => $entite->getId(),
        'course' => $course->getId(),
      ]);
    }


    return $this->render('administrateur/elearning/form.html.twig', [
      'entite' => $entite,
      'course' => $course,
      'modeEdition' => $isEdit,
      'form' => $form->createView(),
    ]);
  }

  #[Route('/order/{id}/paid', name: 'order_paid', methods: ['POST'])]
  public function markPaid(
    Entite $entite,
    ElearningOrder $newOrder,
    Request $request,
    EntityManagerInterface $em,
    ElearningEnrollmentManager $manager
  ): Response {
    if (!$this->isCsrfTokenValid('order_paid_' . $newOrder->getId(), (string)$request->request->get('_token'))) {
      throw $this->createAccessDeniedException('CSRF invalide.');
    }

    $newOrder->setStatus(OrderStatus::PAID);
    $newOrder->setPaidAt(new \DateTimeImmutable());

    $buyer = $newOrder->getBuyer();
    foreach ($newOrder->getItems() as $it) {
      $manager->assignCourse($entite, $buyer, $it->getCourse(), $this->getUser(), null, null);
    }

    $em->flush();
    $this->addFlash('success', 'Commande payée + accès attribué.');

    /** @var Utilisateur $user */
    $user = $this->getUser();

    return $this->redirectToRoute('app_administrateur_elearning_index', [
      'entite' => $entite->getId(),
    ]);
  }
}
