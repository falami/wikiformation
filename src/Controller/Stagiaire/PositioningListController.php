<?php

declare(strict_types=1);

namespace App\Controller\Stagiaire;

use App\Entity\{PositioningAttempt, Entite, Utilisateur};
use App\Security\Permission\TenantPermission;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use Doctrine\ORM\EntityManagerInterface as EM;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/stagiaire/{entite}/positionnements', name: 'app_stagiaire_positioning_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::STAGIAIRE_POSITIONING_LIST_MANAGE, subject: 'entite')]
final class PositioningListController extends AbstractController
{
  public function __construct(
    private UtilisateurEntiteManager $utilisateurEntiteManager,
  ) {}

  #[Route('', name: 'list', methods: ['GET'])]
  public function list(Entite $entite): Response
  {
    $utilisateurEntite = $this->utilisateurEntiteManager->getUserEntiteLink($entite);

    return $this->render('stagiaire/positioning/list.html.twig', [
      'entite' => $entite,
      'utilisateurEntite' => $utilisateurEntite,
    ]);
  }

  #[Route('/ajax', name: 'ajax', methods: ['POST'])]
  public function ajax(Entite $entite, EM $em, Request $request): JsonResponse
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    $draw   = (int) $request->request->get('draw', 1);
    $start  = max(0, (int) $request->request->get('start', 0));
    $length = (int) $request->request->get('length', 10);
    if ($length <= 0) {
      $length = 10;
    }

    $searchValue = trim((string) (($request->request->all('search')['value'] ?? '') ?: ''));

    $order = $request->request->all('order')[0] ?? null;

    $sortableMap = [
      0 => 'COALESCE(s.id, insS.id)', // Session (directe ou via inscription)
      1 => 'q.title',                // Questionnaire
      2 => 'a.startedAt',            // Début
      3 => 'a.submittedAt',          // Soumis
    ];

    // Total (sans search)
    $baseQb = $this->baseAttemptQb($em, $entite, $user);
    $recordsTotal = (int) (clone $baseQb)
      ->select('COUNT(a.id)')
      ->getQuery()
      ->getSingleScalarResult();

    // Filtré
    $qb = $this->baseAttemptQb($em, $entite, $user);

    if ($searchValue !== '') {
      $needle = '%' . mb_strtolower($searchValue) . '%';

      $qb->andWhere('(
                LOWER(q.title) LIKE :q
                OR CONCAT(\'\', s.id) LIKE :q
                OR CONCAT(\'\', insS.id) LIKE :q
            )')->setParameter('q', $needle);
    }

    $recordsFiltered = (int) (clone $qb)
      ->select('COUNT(a.id)')
      ->getQuery()
      ->getSingleScalarResult();

    // Tri
    if ($order && isset($order['column'], $order['dir'])) {
      $colIndex = (int) $order['column'];
      $dir = strtolower((string) $order['dir']) === 'asc' ? 'ASC' : 'DESC';

      if (isset($sortableMap[$colIndex])) {
        $qb->addOrderBy($sortableMap[$colIndex], $dir);
      }
    } else {
      $qb->addOrderBy('CASE WHEN a.submittedAt IS NULL THEN 0 ELSE 1 END', 'ASC')
        ->addOrderBy('a.startedAt', 'DESC');
    }

    $qb->setFirstResult($start)->setMaxResults($length);

    /** @var PositioningAttempt[] $rows */
    $rows = $qb->getQuery()->getResult();

    $data = [];
    foreach ($rows as $a) {
      $sessionId = $a->getSession()?->getId()
        ?? $a->getInscription()?->getSession()?->getId();

      $isDone = $a->getSubmittedAt() !== null;

      // ✅ correction : isRequired() (à adapter si ton getter diffère)
      $assignment = $a->getAssignment();
      $isRequired = $assignment ? (bool) $assignment->isRequired() : false;

      $sessionHtml = $sessionId
        ? '<span class="badge-pill badge-muted"><i class="bi bi-easel2"></i> #' . (int) $sessionId . '</span>'
        : '<span class="text-muted">Hors session</span>';

      $qTitle = htmlspecialchars((string) $a->getQuestionnaire()?->getTitle(), ENT_QUOTES);

      $chips = [];
      if ($isRequired) $chips[] = '<span class="badge-pill badge-warn"><i class="bi bi-star-fill"></i> Requis</span>';
      $chips[] = $isDone
        ? '<span class="badge-pill badge-success"><i class="bi bi-check2-circle"></i> Soumis</span>'
        : '<span class="badge-pill badge-muted"><i class="bi bi-hourglass-split"></i> À faire</span>';

      $questionnaireHtml = sprintf(
        '<div class="fw-semibold">%s</div><div class="mt-1 d-flex flex-wrap gap-2">%s</div>',
        $qTitle,
        implode('', $chips)
      );

      $startedAtHtml = '<span class="text-muted small"><i class="bi bi-calendar3 me-1"></i>'
        . $a->getStartedAt()->format('d/m/Y H:i') . '</span>';

      $submittedHtml = $a->getSubmittedAt()
        ? '<span class="badge-pill badge-success"><i class="bi bi-check2-circle"></i> Oui</span>'
        . '<span class="text-muted small ms-2">' . $a->getSubmittedAt()->format('d/m/Y H:i') . '</span>'
        : '<span class="badge-pill badge-muted"><i class="bi bi-x-circle"></i> Non</span>';

      $openUrl = $this->generateUrl('app_stagiaire_positioning_fill', [
        'entite' => $entite->getId(),
        'attempt' => $a->getId(),
      ]);

      $actionHtml = '<a class="btn btn-sm btn-action" href="' . htmlspecialchars($openUrl, ENT_QUOTES) . '">'
        . '<i class="bi bi-pencil-square"></i> Ouvrir</a>';

      $data[] = [
        'session' => $sessionHtml,
        'questionnaire' => $questionnaireHtml,
        'startedAt' => $startedAtHtml,
        'submitted' => $submittedHtml,
        'action' => $actionHtml,
        'done' => $isDone ? 1 : 0,
        'required' => $isRequired ? 1 : 0,
      ];
    }

    return new JsonResponse([
      'draw' => $draw,
      'recordsTotal' => $recordsTotal,
      'recordsFiltered' => $recordsFiltered,
      'data' => $data,
    ]);
  }

  private function baseAttemptQb(EM $em, Entite $entite, Utilisateur $user): QueryBuilder
  {
    return $em->getRepository(PositioningAttempt::class)->createQueryBuilder('a')
      ->join('a.questionnaire', 'q')
      ->leftJoin('a.assignment', 'asgn')
      ->leftJoin('a.session', 's')
      ->leftJoin('a.inscription', 'ins')
      ->leftJoin('ins.session', 'insS')
      ->andWhere('a.stagiaire = :u')->setParameter('u', $user)
      ->andWhere('q.entite = :e')->setParameter('e', $entite)
      ->andWhere('(q.isPublished = 1 OR asgn.isRequired = 1)');
  }
}
