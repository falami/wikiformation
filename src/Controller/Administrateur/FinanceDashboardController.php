<?php

namespace App\Controller\Administrateur;

use App\Entity\{
  Entite,
  Utilisateur,
  Depense,
  Facture,
  Devis,
  Avoir,
  Paiement,
  DepenseCategorie,
  DepenseFournisseur
};
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Enum\ModePaiement;
use App\Enum\FactureStatus;
use App\Enum\DevisStatus;
use App\Security\Permission\TenantPermission;


#[Route('/administrateur/{entite}/finance', name: 'app_administrateur_finance_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::FINANCE_DASHBOARD_MANAGE, subject: 'entite')]
final class FinanceDashboardController extends AbstractController
{
  private const TZ = 'Europe/Paris';

  public function __construct(
    private UtilisateurEntiteManager $utilisateurEntiteManager,
  ) {}

  #[Route('', name: 'dashboard', methods: ['GET'])]
  public function dashboard(Entite $entite, EM $em, Request $req): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    // ✅ Année civile par défaut (année en cours), override si ?start=...&end=...
    [$start, $end] = $this->getDefaultCivilYearRange($req, 'start', 'end');

    $categories = $em->getRepository(DepenseCategorie::class)
      ->findBy(['entite' => $entite], ['libelle' => 'ASC']);

    $fournisseurs = $em->getRepository(DepenseFournisseur::class)
      ->findBy(['entite' => $entite], ['nom' => 'ASC']);

    return $this->render('administrateur/finance/dashboard.html.twig', [
      'entite' => $entite,


      'start' => $start->format('Y-m-d'),
      'end'   => $end->format('Y-m-d'),

      'categories' => $categories,
      'fournisseurs' => $fournisseurs,
      'modePaiements'   => ModePaiement::cases(),
      'factureStatuses' => FactureStatus::cases(),
      'devisStatuses'   => DevisStatus::cases(),
    ]);
  }

  /**
   * Endpoint unique : renvoie tout le dashboard en 1 payload JSON
   * (KPI + charts + tops). Ça évite 7 requêtes HTTP.
   */
  #[Route('/api/summary', name: 'api_summary', methods: ['GET'])]
  public function apiSummary(Entite $entite, EM $em, Request $req): JsonResponse
  {
    $f = $this->parseFilters($req);
    $monthsCovered = $this->countMonthsInRange($f['start'], $f['end']);
    $avgMonthly = static fn(int $totalCents) => (int) round($totalCents / max(1, $monthsCovered));




    // --- Dépenses
    // --- Dépenses
    $depBase = $em->createQueryBuilder()
      ->from(Depense::class, 'd')
      ->leftJoin('d.categorie', 'c')
      ->leftJoin('d.fournisseur', 'fo') // ✅ une seule fois
      ->andWhere('d.entite = :e')->setParameter('e', $entite)
      ->andWhere('d.dateDepense BETWEEN :start AND :end')
      ->setParameter('start', $f['start'])
      ->setParameter('end', $f['end']);

    // ✅ Catégories: all|some|none
    if ($f['catMode'] === 'all') {
      // exclusion fiscal/social par défaut
      $depBase->andWhere('(c.id IS NULL OR COALESCE(c.includeInFinanceCharts, 1) = 1)');
    } elseif ($f['catMode'] === 'none') {
      $depBase->andWhere('1=0');
    } else { // some
      if (!empty($f['catIds'])) {
        $depBase->andWhere('c.id IN (:cats)')->setParameter('cats', $f['catIds']);
      } else {
        $depBase->andWhere('1=0');
      }
    }

    // ✅ Fournisseurs: all|some|none
    if ($f['fourMode'] === 'none') {
      $depBase->andWhere('1=0');
    } elseif ($f['fourMode'] === 'some') {
      if (!empty($f['fourIds'])) {
        $depBase->andWhere('fo.id IN (:fours)')->setParameter('fours', $f['fourIds']);
      } else {
        $depBase->andWhere('1=0');
      }
    }


    if ($f['devise'])        $depBase->andWhere('d.devise = :dev')->setParameter('dev', $f['devise']);
    if ($f['tvaOnly'] === 'deductible')   $depBase->andWhere('d.tvaDeductible = 1');
    if ($f['tvaOnly'] === 'nodeductible') $depBase->andWhere('d.tvaDeductible = 0');

    $depKpi = (clone $depBase)
      ->select('COUNT(d.id) as cnt,
                COALESCE(SUM(d.montantHtCents),0) as ht,
                COALESCE(SUM(d.montantTvaCents),0) as tva,
                COALESCE(SUM(d.montantTtcCents),0) as ttc,
                COALESCE(SUM(
                  CASE WHEN d.tvaDeductible = 1
                       THEN (d.montantTvaCents * (d.tvaDeductiblePct / 100))
                       ELSE 0
                  END
                ),0) as tvaDedRaw')
      ->getQuery()->getSingleResult();

    $depKpi['tvaDed'] = (int) round((float) $depKpi['tvaDedRaw']);
    unset($depKpi['tvaDedRaw']);

    // Dépenses / mois (courbe)
    $depMonthlyRows = (clone $depBase)
      ->select('d.dateDepense as dt, d.montantTtcCents as ttc')
      ->getQuery()->getArrayResult();

    $depMonthlyMap = [];
    foreach ($depMonthlyRows as $r) {
      $dt = $r['dt'];
      $ym = ($dt instanceof \DateTimeInterface)
        ? $dt->format('Y-m')
        : (new \DateTimeImmutable((string) $dt))->format('Y-m');

      $depMonthlyMap[$ym] = ($depMonthlyMap[$ym] ?? 0) + (int) $r['ttc'];
    }
    ksort($depMonthlyMap);
    $depMonthly = array_map(
      fn($ym, $sum) => ['ym' => $ym, 'ttc' => $sum],
      array_keys($depMonthlyMap),
      array_values($depMonthlyMap)
    );

    // Top catégories
    $depByCat = (clone $depBase)
      ->select("COALESCE(c.libelle, '—') as label, COALESCE(SUM(d.montantTtcCents),0) as v")
      ->groupBy('c.id, c.libelle')
      ->orderBy('v', 'DESC')
      ->setMaxResults(10)
      ->getQuery()->getArrayResult();



    // Top fournisseurs
    $depByFour = (clone $depBase)
      ->select("
          COALESCE(fo.nom, '—') as label,
          COALESCE(SUM(d.montantTtcCents),0) as v,
          fo.couleurHex as color
      ")
      ->groupBy('fo.id, fo.nom, fo.couleurHex') // ✅ robuste
      ->orderBy('v', 'DESC')
      ->setMaxResults(10)
      ->getQuery()->getArrayResult();


    // --- Factures
    $facBase = $em->createQueryBuilder()
      ->from(Facture::class, 'fa')
      ->andWhere('fa.entite = :e')->setParameter('e', $entite)
      ->andWhere('fa.dateEmission BETWEEN :start AND :end')
      ->setParameter('start', $f['start'])
      ->setParameter('end', $f['end']);

    if ($f['devise']) $facBase->andWhere('fa.devise = :dev')->setParameter('dev', $f['devise']);
    if ($f['factureStatus']) $facBase->andWhere('fa.status = :st')->setParameter('st', $f['factureStatus']);

    $facKpi = (clone $facBase)
      ->select('COUNT(fa.id) as cnt,
                COALESCE(SUM(fa.montantHtCents),0) as ht,
                COALESCE(SUM(fa.montantTvaCents),0) as tva,
                COALESCE(SUM(fa.montantTtcCents),0) as ttc')
      ->getQuery()->getSingleResult();

    $facMonthlyRows = (clone $facBase)
      ->select('fa.dateEmission as dt, fa.montantTtcCents as ttc')
      ->getQuery()->getArrayResult();

    $facMonthlyMap = [];
    foreach ($facMonthlyRows as $r) {
      $dt = $r['dt'];
      $ym = ($dt instanceof \DateTimeInterface)
        ? $dt->format('Y-m')
        : (new \DateTimeImmutable((string)$dt))->format('Y-m');

      $facMonthlyMap[$ym] = ($facMonthlyMap[$ym] ?? 0) + (int)$r['ttc'];
    }
    ksort($facMonthlyMap);
    $facMonthly = array_map(
      fn($ym, $sum) => ['ym' => $ym, 'ttc' => $sum],
      array_keys($facMonthlyMap),
      array_values($facMonthlyMap)
    );

    $facByStatus = (clone $facBase)
      ->select("fa.status as label, COUNT(fa.id) as v")
      ->groupBy('label')
      ->orderBy('v', 'DESC')
      ->getQuery()->getArrayResult();

    // --- Devis
    $devisBase = $em->createQueryBuilder()
      ->from(Devis::class, 'dv')
      ->andWhere('dv.entite = :e')->setParameter('e', $entite)
      ->andWhere('dv.dateEmission BETWEEN :start AND :end')
      ->setParameter('start', $f['start'])
      ->setParameter('end', $f['end']);

    if ($f['devise']) $devisBase->andWhere('dv.devise = :dev')->setParameter('dev', $f['devise']);
    if ($f['devisStatus']) $devisBase->andWhere('dv.status = :dst')->setParameter('dst', $f['devisStatus']);

    $devisKpi = (clone $devisBase)
      ->select('COUNT(dv.id) as cnt,
                COALESCE(SUM(dv.montantHtCents),0) as ht,
                COALESCE(SUM(dv.montantTvaCents),0) as tva,
                COALESCE(SUM(dv.montantTtcCents),0) as ttc')
      ->getQuery()->getSingleResult();

    $devisByStatus = (clone $devisBase)
      ->select("dv.status as label, COUNT(dv.id) as v")
      ->groupBy('label')
      ->orderBy('v', 'DESC')
      ->getQuery()->getArrayResult();

    // --- Paiements
    $payBase = $em->createQueryBuilder()
      ->from(Paiement::class, 'pa')
      ->andWhere('pa.entite = :e')->setParameter('e', $entite)
      ->andWhere('pa.datePaiement BETWEEN :start AND :end')
      ->setParameter('start', $f['start'])
      ->setParameter('end', $f['end']);

    if ($f['devise']) $payBase->andWhere('pa.devise = :dev')->setParameter('dev', $f['devise']);
    if ($f['payMode']) $payBase->andWhere('pa.mode = :m')->setParameter('m', $f['payMode']);

    $payKpi = (clone $payBase)
      ->select('COUNT(pa.id) as cnt, COALESCE(SUM(pa.montantCents),0) as total')
      ->getQuery()->getSingleResult();

    $payMonthlyRows = (clone $payBase)
      ->select('pa.datePaiement as dt, pa.montantCents as total')
      ->getQuery()->getArrayResult();

    $payMonthlyMap = [];
    foreach ($payMonthlyRows as $r) {
      $dt = $r['dt'];
      $ym = ($dt instanceof \DateTimeInterface)
        ? $dt->format('Y-m')
        : (new \DateTimeImmutable((string)$dt))->format('Y-m');

      $payMonthlyMap[$ym] = ($payMonthlyMap[$ym] ?? 0) + (int)$r['total'];
    }
    ksort($payMonthlyMap);
    $payMonthly = array_map(
      fn($ym, $sum) => ['ym' => $ym, 'total' => $sum],
      array_keys($payMonthlyMap),
      array_values($payMonthlyMap)
    );

    $payByMode = (clone $payBase)
      ->select("pa.mode as label, COALESCE(SUM(pa.montantCents),0) as v")
      ->groupBy('label')
      ->orderBy('v', 'DESC')
      ->getQuery()->getArrayResult();

    // --- Avoirs
    $avoBase = $em->createQueryBuilder()
      ->from(Avoir::class, 'av')
      ->andWhere('av.entite = :e')->setParameter('e', $entite)
      ->andWhere('av.dateEmission BETWEEN :start AND :end')
      ->setParameter('start', $f['start'])
      ->setParameter('end', $f['end']);

    $avoKpi = (clone $avoBase)
      ->select('COUNT(av.id) as cnt, COALESCE(SUM(av.montantTtcCents),0) as total')
      ->getQuery()->getSingleResult();

    // --- résultat global
    $net = (int) $facKpi['ttc'] - (int) $depKpi['ttc'];
    $cashGap = (int) $payKpi['total'] - (int) $depKpi['ttc'];




    $depAvgMonthlyTtc = $avgMonthly((int) $depKpi['ttc']);
    $payAvgMonthly    = $avgMonthly((int) $payKpi['total']);
    $facAvgMonthlyTtc = $avgMonthly((int) $facKpi['ttc']);
    $devisAvgMonthlyTtc = $avgMonthly((int) $devisKpi['ttc']);


    return $this->json([
      'filters' => [
        'dateStart' => $f['start']->format('Y-m-d'),
        'dateEnd'   => $f['end']->format('Y-m-d'),
        'monthsCovered' => $monthsCovered, // 👈 pratique pour afficher "sur X mois"
      ],
      'kpis' => [
        'dep' => $depKpi,
        'fac' => $facKpi,
        'devis' => $devisKpi,
        'pay' => $payKpi,
        'avo' => $avoKpi,
        'netTtc' => $net,
        'cashGap' => $cashGap,

        // ✅ nouveaux KPI lissés / mois
        'avgMonthly' => [
          'depTtc' => $depAvgMonthlyTtc,
          'pay' => $payAvgMonthly,
          'facTtc' => $facAvgMonthlyTtc,
          'devisTtc' => $devisAvgMonthlyTtc,
          // optionnel : aussi net/cashgap lissés
          'netTtc' => $avgMonthly($net),
          'cashGap' => $avgMonthly($cashGap),
        ],
      ],
      'charts' => [
        'depMonthly' => $depMonthly,
        'depByCat' => $depByCat,
        'depByFour' => $depByFour,
        'facMonthly' => $facMonthly,
        'facByStatus' => $facByStatus,
        'devisByStatus' => $devisByStatus,
        'payMonthly' => $payMonthly,
        'payByMode' => $payByMode,
      ],
    ]);
  }

  private function parseNullableInt(Request $req, string $key): ?int
  {
    $v = $req->query->get($key);
    if ($v === null) return null;

    $v = trim((string) $v);
    if ($v === '') return null;
    if (!ctype_digit($v)) return null;

    return (int) $v;
  }

  /**
   * ✅ Parsing date safe: attend YYYY-MM-DD
   */
  private function parseDateYmd(?string $v): ?\DateTimeImmutable
  {
    if ($v === null) return null;
    $v = trim($v);
    if ($v === '') return null;

    $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $v, new \DateTimeZone(self::TZ));
    if ($dt instanceof \DateTimeImmutable) {
      // reset warnings/errors
      $errors = \DateTimeImmutable::getLastErrors();
      if (!empty($errors['warning_count']) || !empty($errors['error_count'])) {
        return null;
      }
      return $dt;
    }

    return null;
  }

  /**
   * ✅ Renvoie [start,end] = année civile en cours, override par query params (clés au choix)
   */
  private function getDefaultCivilYearRange(Request $req, string $startKey, string $endKey): array
  {
    $tz = new \DateTimeZone(self::TZ);
    $now = new \DateTimeImmutable('now', $tz);
    $y = (int) $now->format('Y');

    $startDefault = (new \DateTimeImmutable("$y-01-01", $tz))->setTime(0, 0, 0);
    $endDefault   = (new \DateTimeImmutable("$y-12-31", $tz))->setTime(23, 59, 59);

    $start = $this->parseDateYmd($req->query->get($startKey)) ?? $startDefault;
    $end   = $this->parseDateYmd($req->query->get($endKey))   ?? $endDefault;

    // sécurité si inversé
    if ($end < $start) {
      [$start, $end] = [$startDefault, $endDefault];
    }

    return [$start, $end];
  }

  private function parseFilters(Request $req): array
  {
    // ✅ accepte dateStart/dateEnd (API) + start/end (fallback)
    $startStr = $req->query->get('dateStart') ?: $req->query->get('start');
    $endStr   = $req->query->get('dateEnd')   ?: $req->query->get('end');

    $tz = new \DateTimeZone(self::TZ);
    $now = new \DateTimeImmutable('now', $tz);
    $y = (int) $now->format('Y');

    $startDefault = (new \DateTimeImmutable("$y-01-01", $tz))->setTime(0, 0, 0);
    $endDefault   = (new \DateTimeImmutable("$y-12-31", $tz))->setTime(23, 59, 59);

    $start = $this->parseDateYmd($startStr) ?? $startDefault;
    $end   = $this->parseDateYmd($endStr)   ?? $endDefault;

    if ($end < $start) [$start, $end] = [$startDefault, $endDefault];

    // ✅ Multi ids (Symfony: catIds[]=1&catIds[]=2)
    $catIds  = array_values(array_filter(array_map('intval', (array) $req->query->all('catIds')), fn($v) => $v > 0));
    $fourIds = array_values(array_filter(array_map('intval', (array) $req->query->all('fourIds')), fn($v) => $v > 0));

    $catMode  = (string) $req->query->get('catMode', 'all');   // all|some|none
    $fourMode = (string) $req->query->get('fourMode', 'all');  // all|some|none

    // sécurité
    if (!in_array($catMode, ['all', 'some', 'none'], true)) $catMode = 'all';
    if (!in_array($fourMode, ['all', 'some', 'none'], true)) $fourMode = 'all';


    $facSt = trim((string)$req->query->get('factureStatus', ''));
    $devSt = trim((string)$req->query->get('devisStatus', ''));
    $payMd = trim((string)$req->query->get('payMode', ''));

    return [
      'start' => $start->setTime(0, 0, 0),
      'end'   => $end->setTime(23, 59, 59),

      // ✅ nouveaux champs
      'catIds'   => $catIds,
      'fourIds'  => $fourIds,
      'catMode'  => $catMode,
      'fourMode' => $fourMode,

      'devise'        => trim((string)$req->query->get('devise', '')) ?: null,
      'tvaOnly'       => (string)$req->query->get('tvaOnly', 'all'),
      'factureStatus' => $facSt !== '' ? FactureStatus::from($facSt) : null,
      'devisStatus'   => $devSt !== '' ? DevisStatus::from($devSt) : null,
      'payMode'       => $payMd !== '' ? ModePaiement::from($payMd) : null,
    ];
  }



  private function countMonthsInRange(\DateTimeImmutable $start, \DateTimeImmutable $end): int
  {
    // On travaille au niveau "YYYY-MM" (mois civils couverts)
    $aY = (int) $start->format('Y');
    $aM = (int) $start->format('n'); // 1..12
    $bY = (int) $end->format('Y');
    $bM = (int) $end->format('n');

    $aIdx = $aY * 12 + ($aM - 1);
    $bIdx = $bY * 12 + ($bM - 1);

    $months = $bIdx - $aIdx + 1;
    return $months > 0 ? $months : 1;
  }
}
