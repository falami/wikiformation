<?php

namespace App\Controller\Administrateur;

use App\Entity\{FormateurSatisfactionTemplate, Entite, Utilisateur};
use App\Enum\SatisfactionQuestionType;
use App\Form\FormateurSatisfaction\Admin\FormateurSatisfactionTemplateType;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use App\Security\Permission\TenantPermission;





#[Route('/administrateur/{entite}/formateur-satisfaction/templates', name: 'app_administrateur_formateur_satisfaction_template_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::FORMATEUR_SATISFACTION_TEMPLATE_MANAGE, subject: 'entite')]
final class FormateurSatisfactionTemplateController extends AbstractController
{
  public function __construct(private UtilisateurEntiteManager $uem) {}

  #[Route('/', name: 'index', methods: ['GET'])]
  public function index(Entite $entite, EM $em): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();
    $ue = $this->uem->getUserEntiteLink($entite);
    if (!$ue) throw $this->createAccessDeniedException();

    $templates = $em->getRepository(FormateurSatisfactionTemplate::class)->findBy(
      ['entite' => $entite],
      ['id' => 'DESC']
    );

    return $this->render('administrateur/formateur/satisfaction/template/index.html.twig', [
      'entite' => $entite,
      'utilisateurEntite' => $ue,
      'templates' => $templates,
    ]);
  }

  #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
  public function new(Entite $entite, Request $request, EM $em): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();
    $ue = $this->uem->getUserEntiteLink($entite);
    if (!$ue) throw $this->createAccessDeniedException();

    $t = (new FormateurSatisfactionTemplate())->setEntite($entite)->setCreateur($user);
    $form = $this->createForm(FormateurSatisfactionTemplateType::class, $t, [
      'entite' => $entite,
    ])->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      $this->normalizeTemplate($t);

      // positions fallback
      $posC = 1;
      foreach ($t->getChapters() as $c) {
        if (!$c->getPosition()) $c->setPosition($posC);
        $posQ = 1;
        foreach ($c->getQuestions() as $q) {
          if (!$q->getPosition()) $q->setPosition($posQ);
          $posQ++;
        }
        $posC++;
      }

      $em->persist($t);
      $em->flush();

      $this->addFlash('success', 'Template formateur créé.');
      return $this->redirectToRoute('app_administrateur_formateur_satisfaction_template_index', [
        'entite' => $entite->getId()
      ]);
    }

    return $this->render('administrateur/formateur/satisfaction/template/form.html.twig', [
      'entite' => $entite,
      'utilisateurEntite' => $ue,
      'template' => $t,
      'form' => $form->createView(),
      'title' => 'Nouveau questionnaire',
    ]);
  }

  #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
  public function edit(Entite $entite, FormateurSatisfactionTemplate $t, Request $request, EM $em): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();
    $ue = $this->uem->getUserEntiteLink($entite);
    if (!$ue) throw $this->createAccessDeniedException();

    if ($t->getEntite()?->getId() !== $entite->getId()) throw $this->createNotFoundException();



    $form = $this->createForm(FormateurSatisfactionTemplateType::class, $t, [
      'entite' => $entite,
    ])->handleRequest($request);



    if ($form->isSubmitted() && $form->isValid()) {
      $t->touch();
      $this->normalizeTemplate($t);
      $em->flush();

      $this->addFlash('success', 'Template formateur mis à jour.');
      return $this->redirectToRoute('app_administrateur_formateur_satisfaction_template_index', [
        'entite' => $entite->getId()
      ]);
    }

    return $this->render('administrateur/formateur/satisfaction/template/form.html.twig', [
      'entite' => $entite,
      'utilisateurEntite' => $ue,
      'template' => $t,
      'form' => $form->createView(),
      'title' => 'Modifier template formateur',
    ]);
  }

  #[Route('/{id}', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
  public function delete(Entite $entite, FormateurSatisfactionTemplate $t, Request $request, EM $em): Response
  {
    if ($t->getEntite()?->getId() !== $entite->getId()) throw $this->createNotFoundException();

    if (!$this->isCsrfTokenValid('del_fsat_tpl_' . $t->getId(), (string)$request->request->get('_token'))) {
      throw $this->createAccessDeniedException();
    }

    $em->remove($t);
    $em->flush();
    $this->addFlash('success', 'Template supprimé.');

    return $this->redirectToRoute('app_administrateur_formateur_satisfaction_template_index', [
      'entite' => $entite->getId()
    ]);
  }

  #[Route('/ajax', name: 'ajax', methods: ['POST'])]
  public function ajax(Entite $entite, Request $request, EM $em, CsrfTokenManagerInterface $csrf): JsonResponse
  {
    $draw   = (int)$request->request->get('draw', 1);
    $start  = max(0, (int)$request->request->get('start', 0));
    $length = max(10, (int)$request->request->get('length', 10));

    $search = trim((string)($request->request->all('search')['value'] ?? ''));
    $activeFilter = (string)$request->request->get('activeFilter', 'all');

    $columns = $request->request->all('columns') ?? [];
    $order   = $request->request->all('order')[0] ?? [];
    $orderColIdx = isset($order['column']) ? (int)$order['column'] : 0;
    $orderDir = (isset($order['dir']) && strtolower($order['dir']) === 'desc') ? 'DESC' : 'ASC';
    $orderName = $columns[$orderColIdx]['name'] ?? 'title';

    $repo = $em->getRepository(FormateurSatisfactionTemplate::class);

    // QB principal ONLY_FULL_GROUP_BY friendly
    $qb = $repo->createQueryBuilder('t')
      ->leftJoin('t.chapters', 'c')
      ->andWhere('t.entite = :e')->setParameter('e', $entite)
      ->addSelect('COUNT(DISTINCT c.id) AS HIDDEN chaptersCount')
      ->groupBy('t.id');

    if ($search !== '') {
      $qb->leftJoin('t.formations', 'f_search');
    }

    if ($activeFilter === 'yes') $qb->andWhere('t.isActive = 1');
    elseif ($activeFilter === 'no') $qb->andWhere('t.isActive = 0');

    if ($search !== '') {
      $qb->andWhere('LOWER(t.titre) LIKE :q OR LOWER(f_search.titre) LIKE :q')
        ->setParameter('q', '%' . mb_strtolower($search) . '%');
    }

    // counts
    $recordsTotal = (int)$repo->createQueryBuilder('t')
      ->select('COUNT(t.id)')
      ->andWhere('t.entite = :e')->setParameter('e', $entite)
      ->getQuery()->getSingleScalarResult();

    $countFilteredQb = $repo->createQueryBuilder('t')
      ->select('COUNT(DISTINCT t.id)')
      ->andWhere('t.entite = :e')->setParameter('e', $entite);

    if ($search !== '') $countFilteredQb->leftJoin('t.formations', 'f_search');
    if ($activeFilter === 'yes') $countFilteredQb->andWhere('t.isActive = 1');
    elseif ($activeFilter === 'no') $countFilteredQb->andWhere('t.isActive = 0');
    if ($search !== '') {
      $countFilteredQb->andWhere('LOWER(t.titre) LIKE :q OR LOWER(f_search.titre) LIKE :q')
        ->setParameter('q', '%' . mb_strtolower($search) . '%');
    }
    $recordsFiltered = (int)$countFilteredQb->getQuery()->getSingleScalarResult();

    $map = [
      'title' => 't.titre',
      'active' => 't.isActive',
      'chapters' => 'chaptersCount',
    ];
    $qb->addOrderBy($map[$orderName] ?? 't.titre', $orderDir)->addOrderBy('t.id', 'DESC');
    $qb->setFirstResult($start)->setMaxResults($length);

    /** @var FormateurSatisfactionTemplate[] $rows */
    $rows = $qb->getQuery()->getResult();

    // charger formations (2e requête)
    $ids = array_values(array_filter(array_map(fn($t) => $t->getId(), $rows)));
    $formationsByTid = [];
    if ($ids) {
      $pairs = $repo->createQueryBuilder('t')
        ->select('t.id AS tid, f.id AS fid, f.titre AS ftitre')
        ->leftJoin('t.formations', 'f')
        ->andWhere('t.id IN (:ids)')->setParameter('ids', $ids)
        ->getQuery()->getArrayResult();

      foreach ($pairs as $p) {
        if (empty($p['fid'])) continue;
        $formationsByTid[(int)$p['tid']][] = (string)$p['ftitre'];
      }
    }

    $data = [];
    foreach ($rows as $t) {
      $tid = (int)$t->getId();
      $formationsHtml = '— (générique)';
      $fItems = $formationsByTid[$tid] ?? [];
      if ($fItems) {
        $formationsHtml = '';
        foreach ($fItems as $ft) {
          $formationsHtml .= '<span class="badge bg-light text-dark me-1 mb-1"><i class="bi bi-mortarboard"></i> '
            . htmlspecialchars($ft, ENT_QUOTES) . '</span>';
        }
      }

      $activeHtml = $t->isActive()
        ? '<span class="badge bg-success"><i class="bi bi-check2-circle"></i> Actif</span>'
        : '<span class="badge bg-secondary"><i class="bi bi-slash-circle"></i> Inactif</span>';

      $editUrl = $this->generateUrl('app_administrateur_formateur_satisfaction_template_edit', [
        'entite' => $entite->getId(),
        'id' => $t->getId(),
      ]);
      $delUrl = $this->generateUrl('app_administrateur_formateur_satisfaction_template_delete', [
        'entite' => $entite->getId(),
        'id' => $t->getId(),
      ]);
      $token = $csrf->getToken('del_fsat_tpl_' . $t->getId())->getValue();

      $actions = '
              <a class="btn btn-sm btn-outline-primary" href="' . $editUrl . '"><i class="bi bi-pencil"></i> Modifier</a>
              <form method="post" class="d-inline" action="' . $delUrl . '" onsubmit="return confirm(\'Supprimer ce template ?\');">
                <input type="hidden" name="_token" value="' . $token . '">
                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
              </form>';

      $data[] = [
        'title' => htmlspecialchars($t->getTitre(), ENT_QUOTES),
        'formations' => $formationsHtml,
        'active' => $activeHtml,
        'chapters' => $t->getChapters()->count(),
        'actions' => $actions,
      ];
    }

    return $this->json([
      'draw' => $draw,
      'recordsTotal' => $recordsTotal,
      'recordsFiltered' => $recordsFiltered,
      'data' => $data,
    ]);
  }

  private function normalizeTemplate(FormateurSatisfactionTemplate $t): void
  {
    foreach ($t->getChapters() as $c) {
      foreach ($c->getQuestions() as $q) {

        // parse textarea choices "1 par ligne"
        $choices = $q->getChoices();
        if (is_string($choices)) {
          $lines = array_values(array_filter(array_map('trim', preg_split("/\R/", $choices))));
          $q->setChoices($lines);
        }

        // sécurité KPI : seulement scale (comme ton code stagiaire)
        if ($q->getType()->value === 'scale') {
          if ($q->getMinValue() === null) $q->setMinValue(0);
          if ($q->getMaxValeur() === null) $q->setMaxValeur(10);
          if ($q->getMetricMax() === null) $q->setMetricMax(10);
        } else {
          $q->setMetricKey(null);
          $q->setMetricMax(null);
        }

        // interdit stars legacy -> scale
        if ($q->getType()->value === 'stars') {
          $q->setType(SatisfactionQuestionType::SCALE);
          $q->setMinValue(0);
          $q->setMaxValeur(10);
          $q->setMetricMax(10);
        }
      }
    }
  }
}
