<?php

declare(strict_types=1);

namespace App\Controller\Administrateur;

use App\Entity\{PositioningChapter, Entite, Utilisateur, PositioningQuestionnaire};
use App\Form\Administrateur\PositioningQuestionnaireType;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use Doctrine\ORM\EntityManagerInterface as EM;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use App\Security\Permission\TenantPermission;


#[Route('/administrateur/{entite}/positionnements/questionnaires', name: 'app_administrateur_positioning_questionnaire_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::POSITIONING_QUESTIONNAIRE_MANAGE, subject: 'entite')]
final class PositioningQuestionnaireController extends AbstractController
{
  public function __construct(
    private UtilisateurEntiteManager $utilisateurEntiteManager,
    private CsrfTokenManagerInterface $csrf,
  ) {}

  #[Route('', name: 'index', methods: ['GET'])]
  public function index(Entite $entite, EM $em): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    $ue = $this->utilisateurEntiteManager->getUserEntiteLink($entite);

    return $this->render('administrateur/positioning/questionnaire/index.html.twig', [
      'entite' => $entite,
      'utilisateurEntite' => $ue,
    ]);
  }

  #[Route('/ajax', name: 'ajax', methods: ['POST'])]
  public function ajax(Entite $entite, Request $req, EM $em): JsonResponse
  {
    $draw   = (int) $req->request->get('draw', 1);
    $start  = max(0, (int) $req->request->get('start', 0));
    $length = (int) $req->request->get('length', 10);
    if ($length <= 0) $length = 10;

    $searchValue = trim((string) (($req->request->all('search')['value'] ?? '') ?: ''));

    $publishedFilter = (string) $req->request->get('publishedFilter', 'all'); // all|yes|no

    /** @var QueryBuilder $qb */
    $qb = $em->createQueryBuilder()
      ->select('q')
      ->from(PositioningQuestionnaire::class, 'q')
      ->andWhere('q.entite = :entite')
      ->setParameter('entite', $entite);

    // Total (sans filtres)
    $recordsTotal = (int) (clone $qb)
      ->select('COUNT(q.id)')
      ->resetDQLPart('orderBy')
      ->getQuery()
      ->getSingleScalarResult();

    // Filtre published
    if ($publishedFilter === 'yes') {
      $qb->andWhere('q.published = true');
    } elseif ($publishedFilter === 'no') {
      $qb->andWhere('q.published = false');
    }

    // Recherche
    if ($searchValue !== '') {
      $s = mb_strtolower($searchValue);
      $qb->andWhere('LOWER(q.title) LIKE :s OR LOWER(COALESCE(q.software, \'\')) LIKE :s')
        ->setParameter('s', '%' . $s . '%');
    }

    // recordsFiltered
    $recordsFiltered = (int) (clone $qb)
      ->select('COUNT(q.id)')
      ->resetDQLPart('orderBy')
      ->getQuery()
      ->getSingleScalarResult();

    // Tri DataTables
    $orderArr = $req->request->all('order');
    $order = is_array($orderArr) ? ($orderArr[0] ?? null) : null;

    $colIndex = isset($order['column']) ? (int) $order['column'] : 3;
    $dir = (isset($order['dir']) && strtolower((string) $order['dir']) === 'asc') ? 'ASC' : 'DESC';

    $orderMap = [
      0 => 'q.title',
      1 => 'q.software',
      2 => 'q.published',
      3 => 'q.createdAt',
      4 => 'q.id',
    ];
    $orderBy = $orderMap[$colIndex] ?? 'q.createdAt';
    $qb->orderBy($orderBy, $dir)->addOrderBy('q.id', 'DESC');

    // Pagination
    $qb->setFirstResult($start)->setMaxResults($length);

    /** @var PositioningQuestionnaire[] $rows */
    $rows = $qb->getQuery()->getResult();

    $data = [];
    foreach ($rows as $q) {
      $published = (bool) $q->isPublished();

      $publishedHtml = $published
        ? '<span class="badge-soft badge-yes"><i class="bi bi-check2-circle"></i> Oui</span>'
        : '<span class="badge-soft badge-no"><i class="bi bi-x-circle"></i> Non</span>';

      // ✅ actions via Twig partial (pile ton design)
      $actionsHtml = $this->renderView('administrateur/positioning/questionnaire/_actions.html.twig', [
        'entite' => $entite,
        'q' => $q,
      ]);

      $data[] = [
        'title'     => $q->getTitle(),
        'software'  => $q->getSoftware() ?: '—',
        'published' => $publishedHtml,
        'createdAt' => $q->getCreatedAt()?->format('d/m/Y H:i') ?? '—',
        'actions'   => $actionsHtml,
      ];
    }

    return new JsonResponse([
      'draw' => $draw,
      'recordsTotal' => $recordsTotal,
      'recordsFiltered' => $recordsFiltered,
      'data' => $data,
    ]);
  }

  #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
  public function new(Entite $entite, Request $req, EM $em): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    $ue = $this->utilisateurEntiteManager->getUserEntiteLink($entite);

    $q = (new PositioningQuestionnaire())
      ->setEntite($entite)
      ->setCreateur($user);

    $form = $this->createForm(PositioningQuestionnaireType::class, $q);
    $form->handleRequest($req);

    if ($form->isSubmitted() && $form->isValid()) {
      $em->persist($q);
      $em->flush();

      $this->addFlash('success', 'Questionnaire créé.');

      return $this->redirectToRoute('app_administrateur_positioning_questionnaire_edit', [
        'entite' => $entite,
        'id' => $q->getId(),
      ]);
    }

    return $this->render('administrateur/positioning/questionnaire/form.html.twig', [
      'entite' => $entite,
      'q' => $q,
      'form' => $form->createView(),
      'is_edit' => false,
      'utilisateurEntite' => $ue,
    ]);
  }

  #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
  public function edit(Entite $entite, PositioningQuestionnaire $q, Request $req, EM $em): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    $ue = $this->utilisateurEntiteManager->getUserEntiteLink($entite);

    if ($q->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException();
    }

    $form = $this->createForm(PositioningQuestionnaireType::class, $q);
    $form->handleRequest($req);

    if ($form->isSubmitted() && $form->isValid()) {
      $em->flush();
      $this->addFlash('success', 'Questionnaire mis à jour.');

      return $this->redirectToRoute('app_administrateur_positioning_questionnaire_edit', [
        'entite' => $entite,
        'id' => $q->getId(),
      ]);
    }

    return $this->render('administrateur/positioning/questionnaire/form.html.twig', [
      'entite' => $entite,
      'q' => $q,
      'form' => $form->createView(),
      'is_edit' => true,
      'utilisateurEntite' => $ue,
    ]);
  }

  #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
  public function delete(Entite $entite, PositioningQuestionnaire $q, Request $req, EM $em): Response
  {
    if ($q->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException();
    }

    if ($this->isCsrfTokenValid('del_q_' . $q->getId(), (string) $req->request->get('_token'))) {
      $em->remove($q);
      $em->flush();
      $this->addFlash('success', 'Questionnaire supprimé.');
    } else {
      $this->addFlash('danger', 'Token CSRF invalide.');
    }

    return $this->redirectToRoute('app_administrateur_positioning_questionnaire_index', [
      'entite' => $entite,
    ]);
  }

  #[Route('/{questionnaire}/chapters/reorder', name: 'chapter_reorder', methods: ['POST'])]
  public function reorderChapters(Request $request, EM $em, Entite $entite, PositioningQuestionnaire $questionnaire): JsonResponse
  {
    $data = json_decode($request->getContent(), true) ?? [];

    $tokenValue = (string)($data['_token'] ?? '');
    if (!$this->csrf->isTokenValid(new CsrfToken('reorder_positioning', $tokenValue))) {
      return new JsonResponse(['ok' => false, 'error' => 'CSRF'], 403);
    }

    if ($questionnaire->getEntite()?->getId() !== $entite->getId()) {
      return new JsonResponse(['ok' => false, 'error' => 'bad_scope'], 400);
    }

    $orderedIds = $data['orderedIds'] ?? [];
    if (!is_array($orderedIds)) return new JsonResponse(['ok' => false], 400);

    $map = [];
    foreach ($questionnaire->getChapters() as $ch) {
      $map[$ch->getId()] = $ch;
    }

    $pos = 1;
    foreach ($orderedIds as $id) {
      $id = (int)$id;
      if (isset($map[$id])) $map[$id]->setPosition($pos++);
    }

    $em->flush();
    return new JsonResponse(['ok' => true]);
  }

  #[Route('/{questionnaire}/chapter/{chapter}/items/reorder', name: 'item_reorder', methods: ['POST'])]
  public function reorderItems(Request $request, EM $em, Entite $entite, PositioningQuestionnaire $questionnaire, PositioningChapter $chapter): JsonResponse
  {
    $data = json_decode($request->getContent(), true) ?? [];

    $tokenValue = (string)($data['_token'] ?? '');
    if (!$this->csrf->isTokenValid(new CsrfToken('reorder_positioning', $tokenValue))) {
      return new JsonResponse(['ok' => false, 'error' => 'CSRF'], 403);
    }

    if ($questionnaire->getEntite()?->getId() !== $entite->getId()) {
      return new JsonResponse(['ok' => false, 'error' => 'bad_scope'], 400);
    }
    if ($chapter->getQuestionnaire()?->getId() !== $questionnaire->getId()) {
      return new JsonResponse(['ok' => false, 'error' => 'bad_scope'], 400);
    }

    $orderedIds = $data['orderedIds'] ?? [];
    if (!is_array($orderedIds)) return new JsonResponse(['ok' => false], 400);

    $map = [];
    foreach ($chapter->getItems() as $it) {
      $map[$it->getId()] = $it;
    }

    $pos = 1;
    foreach ($orderedIds as $id) {
      $id = (int)$id;
      if (isset($map[$id])) $map[$id]->setPosition($pos++);
    }

    $em->flush();
    return new JsonResponse(['ok' => true]);
  }



  #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
  public function show(Entite $entite, PositioningQuestionnaire $q, EM $em): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    $ue = $this->utilisateurEntiteManager->getUserEntiteLink($entite);

    if ($q->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException();
    }

    // On force le chargement trié (ton OrderBy existe déjà côté entity)
    // Si un jour tu veux éviter du lazy, tu peux faire un fetch join repo.
    return $this->render('administrateur/positioning/questionnaire/show.html.twig', [
      'entite' => $entite,
      'q' => $q,
      'chapters' => $q->getChapters(),
      'utilisateurEntite' => $ue,
    ]);
  }
}
