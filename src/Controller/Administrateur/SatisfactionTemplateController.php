<?php
// src/Controller/Administrateur/SatisfactionTemplateController.php
namespace App\Controller\Administrateur;

use App\Entity\{SatisfactionTemplate, Entite, Utilisateur, SatisfactionAttempt};
use App\Enum\NiveauFormation;
use App\Enum\SatisfactionQuestionType;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\Form\Satisfaction\SatisfactionTemplateType;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\HttpFoundation\{Request, Response};
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Twig\Environment as Twig;
use App\Security\Permission\TenantPermission;




#[Route('/administrateur/{entite}/satisfaction/templates', name: 'app_administrateur_satisfaction_template_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::SATISFACTION_TEMPLATE_MANAGE, subject: 'entite')]
class SatisfactionTemplateController extends AbstractController
{

  public function __construct(
    private UtilisateurEntiteManager $utilisateurEntiteManager,
  ) {}


  #[Route('/', name: 'index', methods: ['GET'])]
  public function index(Entite $entite, EM $em): Response
  {

    /** @var Utilisateur $user */
    $user = $this->getUser();
    $templates = $em->getRepository(SatisfactionTemplate::class)->findBy(
      ['entite' => $entite],
      ['id' => 'DESC']
    );

    return $this->render('administrateur/satisfaction/template/index.html.twig', [
      'entite' => $entite,
      'templates' => $templates,

    ]);
  }

  #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
  public function new(Entite $entite, Request $request, EM $em): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();
    $t = (new SatisfactionTemplate())->setEntite($entite)
      ->setCreateur($user);

    $form = $this->createForm(SatisfactionTemplateType::class, $t, [
      'entite' => $entite,
    ]);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      // positions fallback si vides
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


      foreach ($t->getChapters() as $c) {
        foreach ($c->getQuestions() as $q) {

          if ($q->getType()->value === 'scale') {
            $q->setMinValue(0);
            $q->setMaxValeur(10);
            $q->setMetricMax(10);
          } else {
            // ✅ interdit KPI si pas scale
            $q->setMetricKey(null);
            $q->setMetricMax(null); // optionnel si tu veux être strict
          }

          if ($q->getType()->value === 'stars') {
            // sécurité si jamais une vieille donnée traine
            $q->setType(SatisfactionQuestionType::SCALE);
            $q->setMinValue(0);
            $q->setMaxValeur(10);
            $q->setMetricMax(10);
          }
        }
      }


      foreach ($t->getChapters() as $c) {
        $c->setEntite($c->getEntite() ?? $entite);
        $c->setCreateur($c->getCreateur() ?? $user);

        foreach ($c->getQuestions() as $q) {
          $q->setEntite($q->getEntite() ?? $entite);
          $q->setCreateur($q->getCreateur() ?? $user);
        }
      }

      $em->persist($t);
      $em->flush();

      $this->addFlash('success', 'Template de satisfaction créé.');
      return $this->redirectToRoute('app_administrateur_satisfaction_template_index', ['entite' => $entite->getId()]);
    }

    return $this->render('administrateur/satisfaction/template/form.html.twig', [
      'entite' => $entite,
      'template' => $t,
      'form' => $form->createView(),
      'title' => 'Nouveau template',
    ]);
  }

  #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
  public function edit(Entite $entite, SatisfactionTemplate $t, Request $request, EM $em): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();
    $form = $this->createForm(SatisfactionTemplateType::class, $t, [
      'entite' => $entite,
    ]);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      $t->touch();


      foreach ($t->getChapters() as $c) {
        foreach ($c->getQuestions() as $q) {

          if ($q->getType()->value === 'scale') {
            $q->setMinValue(0);
            $q->setMaxValeur(10);
            $q->setMetricMax(10);
          } else {
            // ✅ interdit KPI si pas scale
            $q->setMetricKey(null);
            $q->setMetricMax(null); // optionnel si tu veux être strict
          }

          if ($q->getType()->value === 'stars') {
            // sécurité si jamais une vieille donnée traine
            $q->setType(SatisfactionQuestionType::SCALE);
            $q->setMinValue(0);
            $q->setMaxValeur(10);
            $q->setMetricMax(10);
          }
        }
      }


      foreach ($t->getChapters() as $c) {
        $c->setEntite($c->getEntite() ?? $entite);
        $c->setCreateur($c->getCreateur() ?? $user);

        foreach ($c->getQuestions() as $q) {
          $q->setEntite($q->getEntite() ?? $entite);
          $q->setCreateur($q->getCreateur() ?? $user);
        }
      }



      $em->flush();

      $this->addFlash('success', 'Template mis à jour.');
      return $this->redirectToRoute('app_administrateur_satisfaction_template_index', [
        'entite' => $entite->getId()
      ]);
    }

    return $this->render('administrateur/satisfaction/template/form.html.twig', [
      'entite' => $entite,
      'template' => $t,
      'form' => $form->createView(),
      'title' => 'Modifier template',

    ]);
  }

  #[Route('/{id}', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
  public function delete(Entite $entite, SatisfactionTemplate $t, Request $request, EM $em): Response
  {
    if (!$this->isCsrfTokenValid('del_sat_tpl_' . $t->getId(), (string)$request->request->get('_token'))) {
      throw $this->createAccessDeniedException();
    }

    $em->remove($t);
    $em->flush();

    $this->addFlash('success', 'Template supprimé.');
    return $this->redirectToRoute('app_administrateur_satisfaction_template_index', [
      'entite' => $entite->getId()
    ]);
  }





  #[Route('/ajax', name: 'ajax', methods: ['POST'])]
  public function ajax(Entite $entite, Request $request, EM $em, CsrfTokenManagerInterface $csrf, Twig $twig): JsonResponse
  {
    $draw   = (int)$request->request->get('draw', 1);
    $start  = (int)$request->request->get('start', 0);
    $length = (int)$request->request->get('length', 10);

    $search = trim((string)($request->request->all('search')['value'] ?? ''));
    $activeFilter = (string)$request->request->get('activeFilter', 'all');

    $columns = $request->request->all('columns') ?? [];
    $order   = $request->request->all('order')[0] ?? [];
    $orderColIdx = isset($order['column']) ? (int)$order['column'] : 0;
    $orderDir = (isset($order['dir']) && strtolower($order['dir']) === 'desc') ? 'DESC' : 'ASC';
    $orderName = $columns[$orderColIdx]['name'] ?? 'title';

    $repo = $em->getRepository(SatisfactionTemplate::class);

    // ✅ QB principal : pas de addSelect('f') (ONLY_FULL_GROUP_BY friendly)
    $qb = $repo->createQueryBuilder('t')
      ->leftJoin('t.chapters', 'c')
      ->andWhere('t.entite = :entite')->setParameter('entite', $entite)
      ->addSelect('COUNT(DISTINCT c.id) AS HIDDEN chaptersCount')
      ->groupBy('t.id');

    // ✅ si recherche, join formations pour filtrer (sans addSelect)
    if ($search !== '') {
      $qb->leftJoin('t.formations', 'f_search');
    }

    // Filtre actif
    if ($activeFilter === 'yes') {
      $qb->andWhere('t.isActive = 1');
    } elseif ($activeFilter === 'no') {
      $qb->andWhere('t.isActive = 0');
    }

    // Recherche (titre template + titre formations)
    if ($search !== '') {
      $qb->andWhere('LOWER(t.titre) LIKE :q OR LOWER(f_search.titre) LIKE :q')
        ->setParameter('q', '%' . mb_strtolower($search) . '%');
    }

    // recordsFiltered
    $countFilteredQb = $repo->createQueryBuilder('t')
      ->select('COUNT(DISTINCT t.id)')
      ->andWhere('t.entite = :entite')->setParameter('entite', $entite);

    if ($search !== '') {
      $countFilteredQb->leftJoin('t.formations', 'f_search');
    }
    if ($activeFilter === 'yes') {
      $countFilteredQb->andWhere('t.isActive = 1');
    } elseif ($activeFilter === 'no') {
      $countFilteredQb->andWhere('t.isActive = 0');
    }
    if ($search !== '') {
      $countFilteredQb->andWhere('LOWER(t.titre) LIKE :q OR LOWER(f_search.titre) LIKE :q')
        ->setParameter('q', '%' . mb_strtolower($search) . '%');
    }

    $recordsFiltered = (int)$countFilteredQb->getQuery()->getSingleScalarResult();

    // recordsTotal
    $recordsTotal = (int)$repo->createQueryBuilder('t')
      ->select('COUNT(t.id)')
      ->andWhere('t.entite = :entite')->setParameter('entite', $entite)
      ->getQuery()->getSingleScalarResult();

    // Tri
    $map = [
      'title'    => 't.titre',
      'active'   => 't.isActive',
      'chapters' => 'chaptersCount',
      // 'notes' => (non triable ici, on calcule en requête séparée)
    ];
    $qb->addOrderBy($map[$orderName] ?? 't.titre', $orderDir)
      ->addOrderBy('t.id', 'DESC');

    // Pagination
    $qb->setFirstResult($start)->setMaxResults($length);

    /** @var SatisfactionTemplate[] $rows */
    $rows = $qb->getQuery()->getResult();

    // =========================
    // ✅ 2e requête : charger les formations pour les templates affichés
    // =========================
    $templateIds = array_values(array_filter(array_map(fn($t) => $t->getId(), $rows)));

    $chaptersCountByTemplateId = [];
    if ($templateIds) {
      $rowsCount = $repo->createQueryBuilder('t')
        ->select('t.id AS tid', 'COUNT(DISTINCT c.id) AS cnt')
        ->leftJoin('t.chapters', 'c')
        ->andWhere('t.id IN (:ids)')->setParameter('ids', $templateIds)
        ->groupBy('t.id')
        ->getQuery()->getArrayResult();

      foreach ($rowsCount as $r) {
        $chaptersCountByTemplateId[(int)$r['tid']] = (int)$r['cnt'];
      }
    }


    $formationsByTemplateId = [];
    if (count($templateIds) > 0) {
      $pairs = $repo->createQueryBuilder('t')
        ->select(
          't.id AS tid',
          'f.id AS fid',
          'f.titre AS ftitre',
          'f.niveau AS fniveau',
          'f.duree AS fduree'
        )
        ->leftJoin('t.formations', 'f')
        ->andWhere('t.id IN (:ids)')->setParameter('ids', $templateIds)
        ->getQuery()->getArrayResult();

      foreach ($pairs as $p) {
        if (empty($p['fid'])) continue;

        $niveau = $p['fniveau'] ?? null;
        if ($niveau instanceof NiveauFormation) {
          $niveau = $niveau->label();
        } elseif ($niveau instanceof \BackedEnum) {
          $niveau = $niveau->value;
        } elseif (is_string($niveau)) {
          $niveau = $niveau;
        } else {
          $niveau = null;
        }

        $formationsByTemplateId[(int)$p['tid']][] = [
          'titre'  => (string)($p['ftitre'] ?? ''),
          'niveau' => $niveau,
          'duree'  => $p['fduree'] ?? null,
        ];
      }
    }

    // =========================
    // ✅ 3e requête : moyennes des notes (via Attempt -> Assignment -> Template)
    // =========================
    $notesByTemplateId = [];

    if (count($templateIds) > 0) {
      $attemptRepo = $em->getRepository(SatisfactionAttempt::class);

      $avgRows = $attemptRepo->createQueryBuilder('a')
        ->select(
          't.id AS tid',
          'AVG(a.noteGlobale) AS avgOverall',
          'AVG(a.noteFormateur) AS avgTrainer',
          'AVG(a.noteSite) AS avgSite',
          'AVG(a.noteContenu) AS avgContent',
          'AVG(a.noteOrganisme) AS avgOrganisme',
          'AVG(a.recommendationScore) AS avgReco',
          'COUNT(a.id) AS cnt'
        )
        ->innerJoin('a.assignment', 'ass')
        ->innerJoin('ass.template', 't')
        ->andWhere('t.id IN (:ids)')->setParameter('ids', $templateIds)
        ->andWhere('a.submittedAt IS NOT NULL')
        ->groupBy('t.id')
        ->getQuery()->getArrayResult();


      foreach ($avgRows as $r) {
        $tid = (int)($r['tid'] ?? 0);
        if ($tid <= 0) continue;

        $notesByTemplateId[$tid] = [
          'cnt' => (int) ($r['cnt'] ?? 0),
          'overall'   => $r['avgOverall'] !== null ? (float)$r['avgOverall'] : null,
          'trainer'   => $r['avgTrainer'] !== null ? (float)$r['avgTrainer'] : null,
          'site'      => $r['avgSite'] !== null ? (float)$r['avgSite'] : null,
          'content'   => $r['avgContent'] !== null ? (float)$r['avgContent'] : null,
          'organisme' => $r['avgOrganisme'] !== null ? (float)$r['avgOrganisme'] : null,
          'reco'      => $r['avgReco'] !== null ? (float)$r['avgReco'] : null,
        ];
      }
    }

    // =========================
    // Data
    // =========================
    $data = [];

    $fmt = static function (?float $v): ?int {
      if ($v === null) return null;
      return (int) round($v);
    };

    $chipClass = static function (?int $v): string {
      if ($v === null) return '';
      if ($v >= 8) return 'good';
      if ($v >= 6) return 'mid';
      return 'bad';
    };

    foreach ($rows as $t) {
      $tid = (int)$t->getId();

      // Formations HTML
      // Formations HTML (limité + "plus")
      $formationsHtml = '';
      $items = $formationsByTemplateId[$tid] ?? [];

      $maxChips = 3;

      if (count($items) > 0) {
        $shown = array_slice($items, 0, $maxChips);
        $rest  = array_slice($items, $maxChips);
        $remaining = count($rest);

        // chips visibles
        foreach ($shown as $it) {
          $titre  = htmlspecialchars($it['titre'], ENT_QUOTES);
          $niveau = $it['niveau'] ? htmlspecialchars((string)$it['niveau'], ENT_QUOTES) : '—';
          $duree  = $it['duree'] ? ((int)$it['duree'] . ' j') : '—';

          $formationsHtml .= sprintf(
            '<div class="f-chip-line">
              <span class="f-chip" title="%s — %s — %s">
                <i class="bi bi-mortarboard"></i>
                <span class="me-1">%s • %s • %s</span>
              </span>
            </div>',
            $titre,
            $niveau,
            $duree,
            $titre,
            $niveau,
            $duree
          );
        }

        // chip "+X" si besoin
        if ($remaining > 0) {
          // tooltip texte (1 ligne par formation restante)
          $lines = [];
          foreach ($rest as $it) {
            $titleRest = (string)($it['titre'] ?? '');
            $n         = $it['niveau'] ? (string)$it['niveau'] : '—';
            $d         = $it['duree'] ? ((int)$it['duree'] . ' j') : '—';
            $lines[]   = $titleRest . ' — ' . $n . ' — ' . $d;
          }


          // title HTML-safe (et support des retours à la ligne)
          $moreTitle = htmlspecialchars(implode("\n", $lines), ENT_QUOTES);

          $formationsHtml .= sprintf(
            '<span class="f-chip f-more" title="%s">+%d</span>',
            $moreTitle,
            $remaining
          );
        }
      } else {
        $formationsHtml = '<span class="text-muted">— (générique)</span>';
      }


      // Active badge
      $activeHtml = $t->isActive()
        ? '<span class="badge-soft badge-yes"><i class="bi bi-check2-circle"></i> Actif</span>'
        : '<span class="badge-soft badge-no"><i class="bi bi-slash-circle"></i> Inactif</span>';


      // Notes HTML (moyennes)
      // =========================
      // NOTES (affichage type "Avancement")
      // =========================
      $avg = $notesByTemplateId[$tid] ?? null;
      $cnt = (int)($avg['cnt'] ?? 0);

      // helpers "avancement-like"
      $badge = static fn(string $class, string $html) => '<span class="badge ' . $class . '">' . $html . '</span>';

      $line = static fn(string $left, string $right = '') =>
      '<div class="d-flex justify-content-between align-items-center gap-2">'
        . '<div class="text-start">' . $left . '</div>'
        . '<div class="text-end">' . $right . '</div>'
        . '</div>';

      $fmt1 = static function (?float $v): ?float {
        return $v === null ? null : round((float)$v, 1);
      };

      $chipClass10 = static function (?float $v): string {
        if ($v === null) return '';
        if ($v >= 8) return 'good';
        if ($v >= 6) return 'mid';
        return 'bad';
      };

      $progress = static function (?float $v, int $max = 10): string {
        if ($v === null) return '<div class="progress" style="height:7px;width:110px"><div class="progress-bar" style="width:0%"></div></div>';
        $pct = (int) max(0, min(100, round(($v / $max) * 100)));
        return '<div class="progress" style="height:7px;width:110px">
            <div class="progress-bar" role="progressbar" style="width:' . $pct . '%"></div>
          </div>';
      };

      if ($cnt <= 0) {
        $notesHtml =
          '<div class="d-flex flex-column gap-2 text-start">'
          . '<div class="d-flex justify-content-between align-items-center">'
          . '<strong class="small text-muted text-uppercase" style="letter-spacing:.06em">Notes</strong>'
          . $badge('bg-light text-muted', '—')
          . '</div>'
          . '<div class="text-muted small">Aucune réponse pour ce template.</div>'
          . '</div>';
      } else {
        $overall   = $fmt1($avg['overall'] ?? null);
        $trainer   = $fmt1($avg['trainer'] ?? null);
        $organisme = $fmt1($avg['organisme'] ?? null);
        $content   = $fmt1($avg['content'] ?? null);
        $site      = $fmt1($avg['site'] ?? null);
        $reco      = $fmt1($avg['reco'] ?? null); // NPS /10 chez toi

        // Badge global (en tête)
        $globalBadge = $overall === null
          ? $badge('bg-light text-muted', '—')
          : '<span class="note-chip ' . $chipClass10($overall) . '"><i class="bi bi-star-fill"></i> Globale <b>'
          . htmlspecialchars((string)$overall, ENT_QUOTES)
          . '</b><span class="muted">/10</span></span>';

        // Lignes détaillées (comme "Documents" dans avancement)
        $lOverall   = $line('<i class="bi bi-star-fill me-1"></i> Globale',   $progress($overall)   . ' <span class="ms-2 small text-muted">' . ($overall   ?? '—') . '/10</span>');
        $lTrainer   = $line('<i class="bi bi-person-badge me-1"></i> Formateur', $progress($trainer) . ' <span class="ms-2 small text-muted">' . ($trainer   ?? '—') . '/10</span>');
        $lOrg       = $line('<i class="bi bi-building me-1"></i> Organisme', $progress($organisme) . ' <span class="ms-2 small text-muted">' . ($organisme ?? '—') . '/10</span>');
        $lContent   = $line('<i class="bi bi-mortarboard me-1"></i> Contenu', $progress($content) . ' <span class="ms-2 small text-muted">' . ($content   ?? '—') . '/10</span>');
        $lSite      = $line('<i class="bi bi-geo-alt me-1"></i> Site',       $progress($site)     . ' <span class="ms-2 small text-muted">' . ($site      ?? '—') . '/10</span>');

        // NPS en plus (sans progress si tu veux, mais on peut aussi le mettre)
        $lReco = $line(
          '<i class="bi bi-graph-up-arrow me-1"></i>Recommandation',
          $progress($reco) . ' <span class="ms-2 small text-muted">' . ($reco ?? '—') . '/10</span>'
        );

        $notesHtml =
          '<div class="d-flex flex-column gap-2 text-start">'
          . '<div class="d-flex justify-content-between align-items-center">'
          . '<strong class="small text-muted text-uppercase" style="letter-spacing:.06em">Notes</strong>'
          . $globalBadge
          . '</div>'
          . '<div class="d-flex flex-column gap-1">'
          . $lOverall
          . $lTrainer
          . $lOrg
          . $lContent
          . $lSite
          . $lReco
          . '</div>'
          . '<div class="small text-muted">(' . $cnt . ' réponse' . ($cnt > 1 ? 's' : '') . ')</div>'
          . '</div>';
      }


      $editUrl = $this->generateUrl('app_administrateur_satisfaction_template_edit', [
        'entite' => $entite->getId(),
        'id' => $t->getId(),
      ]);
      $delUrl = $this->generateUrl('app_administrateur_satisfaction_template_delete', [
        'entite' => $entite->getId(),
        'id' => $t->getId(),
      ]);
      $token = $csrf->getToken('del_sat_tpl_' . $t->getId())->getValue();

      $actions = $twig->render('administrateur/satisfaction/template/_actions.html.twig', [
        'entite'  => $entite,
        't'       => $t,
        'editUrl' => $editUrl,
        'delUrl'  => $delUrl,
        'token'   => $token,
      ]);



      $data[] = [
        'title'      => htmlspecialchars($t->getTitre(), ENT_QUOTES),
        'formations' => $formationsHtml,
        'active'     => $activeHtml,
        'notes'      => $notesHtml,
        'chapters' => $chaptersCountByTemplateId[$tid] ?? 0,
        'actions'    => $actions,
      ];
    }

    return $this->json([
      'draw' => $draw,
      'recordsTotal' => $recordsTotal,
      'recordsFiltered' => $recordsFiltered,
      'data' => $data,
    ]);
  }
}
