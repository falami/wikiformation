<?php
// src/Controller/Administrateur/TvaApiController.php
declare(strict_types=1);

namespace App\Controller\Administrateur;

use App\Entity\{Entite, Depense, LigneFacture, Paiement};
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Security\Permission\TenantPermission;


#[Route('/administrateur/{entite}/tva/api', name: 'app_administrateur_tva_api_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::TVA_API_MANAGE, subject: 'entite')]
final class TvaApiController extends AbstractController
{
  #[Route('/summary', name: 'summary', methods: ['GET'])]
  public function summary(Entite $entite, EM $em, Request $req): JsonResponse
  {
    $tz = new \DateTimeZone('Europe/Paris');

    $start = $req->query->getString('dateStart', '');
    $end   = $req->query->getString('dateEnd', '');
    $regime = $req->query->getString('regime', 'debits'); // debits|encaissements

    $categorieId = $req->query->get('categorie');
    $fournisseurId = $req->query->get('fournisseur');
    $depTvaOnly = $req->query->getString('depTvaOnly', 'all'); // all|deductible|nodeductible

    $rate = $req->query->get('rate'); // "", "20", "10", "55", "0" (optionnel)


    $d1 = $start
      ? \DateTimeImmutable::createFromFormat('Y-m-d', $start, $tz)
      : new \DateTimeImmutable('2000-01-01', $tz);

    $d2 = $end
      ? \DateTimeImmutable::createFromFormat('Y-m-d', $end, $tz)
      : new \DateTimeImmutable('2100-12-31', $tz);
      
    if (!$d1) {
      $d1 = new \DateTimeImmutable('2000-01-01', $tz);
    }

    if (!$d2) {
      $d2 = new \DateTimeImmutable('2100-12-31', $tz);
    }

    /*
    * Date de fin exclusive :
    * permet d'inclure toute la dernière journée,
    * même si les champs sont des datetime.
    */
    $d2Exclusive = $d2
      ->setTime(0, 0)
      ->modify('+1 day');

    $d1 = $d1->setTime(0, 0);

    // =========================
    // 1) TVA collectée (out)
    // =========================
    // Approche robuste : on agrège depuis LigneFacture pour exclure isDebours,
    // sans utiliser ROUND() en DQL.
    //
    // HT net (approx) = qte*pu - remiseMontant - (qte*pu)*(remisePourcent/100)
    // TVA = HTnet * (tvaBp/10000)
    //
    // IMPORTANT : si tu appliques une remise globale facture, elle n’est pas prise en compte ici.
    // (Si tu veux une exactitude parfaite avec remise globale, on peut faire un ratio côté PHP par facture.)
    $qbOut = $em->createQueryBuilder()
      ->from(LigneFacture::class, 'lf')
      ->join('lf.facture', 'f')
      ->select('
        f.id AS factureId,
        f.dateEmission AS dateEmission,
        SUM(
          (
            (lf.qte * lf.puHtCents)
            - COALESCE(lf.remiseMontantCents, 0)
            - ((lf.qte * lf.puHtCents) * (COALESCE(lf.remisePourcent, 0) / 100))
          ) * (lf.tvaBp / 10000)
        ) AS tvaFloat,
        SUM(
          (
            (lf.qte * lf.puHtCents)
            - COALESCE(lf.remiseMontantCents, 0)
            - ((lf.qte * lf.puHtCents) * (COALESCE(lf.remisePourcent, 0) / 100))
          )
        ) AS htFloat
      ')
      ->where('f.entite = :e')
      ->andWhere('f.dateEmission >= :d1')
      ->andWhere('f.dateEmission < :d2')
      ->andWhere('lf.isDebours = 0')
      ->setParameter('e', $entite)
      ->setParameter('d1', $d1)
      ->setParameter('d2', $d2Exclusive);

    if ($rate !== null && $rate !== '') {
      $selectedBp = ((int) $rate === 55)
        ? 550
        : ((int) $rate * 100);

      $qbOut
        ->andWhere('lf.tvaBp = :outBp')
        ->setParameter('outBp', $selectedBp);
    }

    $outRows = $qbOut->groupBy('f.id')->getQuery()->getArrayResult();

    $outTvaCents = 0;
    $outHtCents = 0;
    foreach ($outRows as $r) {
      $outTvaCents += (int) round(((float)$r['tvaFloat']));
      $outHtCents  += (int) round(((float)$r['htFloat']));
    }

    // =========================
    // 2) TVA dépenses (in)
    // =========================
    $qbIn = $em->createQueryBuilder()
      ->from(Depense::class, 'd')
      ->select('
        COUNT(d.id) as cnt,
        SUM(d.montantHtCents) as ht,
        SUM(d.montantTvaCents) as tva,
        SUM(
          CASE
            WHEN d.tvaDeductible = 1 THEN (d.montantTvaCents * (d.tvaDeductiblePct / 100))
            ELSE 0
          END
        ) as tvaDedFloat
      ')
      ->where('d.entite = :e')
      ->andWhere('d.dateDepense >= :d1')
      ->andWhere('d.dateDepense < :d2')
      ->setParameter('e', $entite)
      ->setParameter('d1', $d1)
      ->setParameter('d2', $d2Exclusive);

    if ($categorieId)  $qbIn->andWhere('d.categorie = :cat')->setParameter('cat', (int)$categorieId);
    if ($fournisseurId) $qbIn->andWhere('d.fournisseur = :four')->setParameter('four', (int)$fournisseurId);

    if ($depTvaOnly === 'deductible')   $qbIn->andWhere('d.tvaDeductible = 1');
    if ($depTvaOnly === 'nodeductible') $qbIn->andWhere('d.tvaDeductible = 0');

    if ($rate !== null && $rate !== '') {
      $tauxDepense = ((int) $rate === 55)
        ? 5.5
        : (float) $rate;

      $qbIn
        ->andWhere('d.tauxTva = :tauxDepense')
        ->setParameter('tauxDepense', $tauxDepense);
    }

    $inAgg = $qbIn->getQuery()->getSingleResult();

    $inHtCents = (int)($inAgg['ht'] ?? 0);
    $inTvaCents = (int)($inAgg['tva'] ?? 0);
    $inTvaDedCents = (int) round((float)($inAgg['tvaDedFloat'] ?? 0));
    $inCnt = (int)($inAgg['cnt'] ?? 0);
    $inTvaNoDedCents = max(0, $inTvaCents - $inTvaDedCents);

    // =========================
    // 3) Régime encaissements
    // =========================

    /*
    * En débits :
    * - outTvaCents, outHtCents et outRows viennent des factures.
    *
    * En encaissements :
    * - on remplace ces valeurs par les montants ventilés
    *   sur les paiements de la période.
    */
    $outCount = count($outRows);

    /*
    * Ces tableaux seront aussi utilisés pour :
    * - le graphique mensuel ;
    * - la répartition de TVA par taux.
    */
    $encaissementOutByMonth = [];
    $encaissementOutByRate = [];

    if ($regime === 'encaissements') {
      $qbPay = $em->createQueryBuilder()
        ->from(Paiement::class, 'p')
        ->join('p.facture', 'f')
        ->select('p, f')
        ->where('f.entite = :e')
        ->andWhere('p.datePaiement >= :d1')
        ->andWhere('p.datePaiement < :d2')
        ->setParameter('e', $entite)
        ->setParameter('d1', $d1)
        ->setParameter('d2', $d2Exclusive);

      /** @var Paiement[] $paiements */
      $paiements = $qbPay
        ->getQuery()
        ->getResult();

      $outTvaCents = 0;
      $outHtCents = 0;
      $outCount = 0;

      foreach ($paiements as $paiement) {
        $facture = $paiement->getFacture();

        if (!$facture) {
          continue;
        }

        $montantPaiementCents = max(
          0,
          (int) $paiement->getMontantCents()
        );

        if ($montantPaiementCents <= 0) {
          continue;
        }

        /*
        * Montants hors débours de la facture.
        * Ces méthodes sont déjà utilisées dans ton DataTable.
        */
        $factureTtcHorsDeboursCents = max(
          0,
          (int) $facture->getMontantTtcHorsDeboursCents()
        );

        $factureHtHorsDeboursCents = max(
          0,
          (int) $facture->getMontantHtHorsDeboursCents()
        );

        $factureTvaHorsDeboursCents = max(
          0,
          (int) $facture->getMontantTvaHorsDeboursCents()
        );

        if ($factureTtcHorsDeboursCents <= 0) {
          continue;
        }

        /*
        * On limite à 100 % afin qu'un éventuel trop-perçu
        * ne produise pas plus de TVA que la facture.
        */
        $ratio = $montantPaiementCents
          / $factureTtcHorsDeboursCents;

        $ratio = max(0.0, min(1.0, $ratio));

        /*
        * On utilise la ventilation enregistrée sur le paiement
        * lorsqu'elle existe.
        */
        $tvaEncaisseeCents = 0;
        $htEncaisseCents = 0;

        $selectedBp = null;

        if ($rate !== null && $rate !== '') {
          $selectedBp = ((int) $rate === 55)
            ? 550
            : ((int) $rate * 100);
        }

        /*
        * Aucun taux sélectionné :
        * on utilise les totaux hors débours de la facture.
        */
        if ($selectedBp === null) {
          if (
            method_exists($paiement, 'getVentilationTvaHorsDeboursCents')
            && $paiement->getVentilationTvaHorsDeboursCents() !== null
          ) {
            $tvaEncaisseeCents = max(
              0,
              (int) $paiement->getVentilationTvaHorsDeboursCents()
            );
          } else {
            $tvaEncaisseeCents = (int) round(
              $factureTvaHorsDeboursCents * $ratio
            );
          }

          $htEncaisseCents = (int) round(
            $factureHtHorsDeboursCents * $ratio
          );
        }
        /*
        * Un taux est sélectionné :
        * on recalcule uniquement les lignes de ce taux.
        */
        else {
          foreach ($facture->getLignes() as $ligne) {
            if ($ligne->isDebours()) {
              continue;
            }

            if ($ligne->getTvaBp() !== $selectedBp) {
              continue;
            }

            $ligneHtCents = $ligne->getTotalHtNetCents();
            $ligneTvaCents = $ligne->getTotalTvaCents();

            $htEncaisseCents += (int) round(
              $ligneHtCents * $ratio
            );

            $tvaEncaisseeCents += (int) round(
              $ligneTvaCents * $ratio
            );
          }
        }

        $outTvaCents += $tvaEncaisseeCents;
        $outHtCents += $htEncaisseCents;

        if (
          $selectedBp === null
          || $tvaEncaisseeCents !== 0
          || $htEncaisseCents !== 0
        ) {
          $outCount++;
        }

        /*
        * Ventilation mensuelle selon la date du paiement.
        */
        $ym = $paiement
          ->getDatePaiement()
          ->format('Y-m');

        if (!isset($encaissementOutByMonth[$ym])) {
          $encaissementOutByMonth[$ym] = 0;
        }

        $encaissementOutByMonth[$ym] += $tvaEncaisseeCents;

        /*
        * Ventilation par taux.
        *
        * On récupère les lignes hors débours de la facture
        * afin de répartir le paiement selon les taux présents.
        */
        foreach ($facture->getLignes() as $ligne) {
          if ($ligne->isDebours()) {
            continue;
          }

          $bp = $ligne->getTvaBp();

          if ($rate !== null && $rate !== '') {
            $selectedBp = ((int) $rate === 55)
              ? 550
              : ((int) $rate * 100);

            if ($bp !== $selectedBp) {
              continue;
            }
          }

          $ligneTvaEncaisseeCents = (int) round(
            $ligne->getTotalTvaCents() * $ratio
          );

          if (!isset($encaissementOutByRate[$bp])) {
            $encaissementOutByRate[$bp] = 0;
          }

          $encaissementOutByRate[$bp] +=
            $ligneTvaEncaisseeCents;
        }
      }
    }

    $payable = $outTvaCents - $inTvaDedCents;

    // =========================
    // 4) Charts : trend mensuel
    // =========================
    // on fabrique des buckets YYYY-MM
    $labels = [];
    $cursor = $d1->modify('first day of this month');
    $endMonth = $d2->modify('first day of this month');
    while ($cursor <= $endMonth) {
      $labels[] = $cursor->format('Y-m');
      $cursor = $cursor->modify('+1 month');
    }
    $zeroSeries = array_fill(0, count($labels), 0);

    // out trend : on repart des lignes factures (débits) et on bucketise en PHP
    $outByMonth = array_fill_keys($labels, 0);

    if ($regime === 'encaissements') {
      foreach ($encaissementOutByMonth as $ym => $amount) {
        if (array_key_exists($ym, $outByMonth)) {
          $outByMonth[$ym] = (int) $amount;
        }
      }
    } else {
      foreach ($outRows as $r) {
        $dateEmission = $r['dateEmission'];

        if ($dateEmission instanceof \DateTimeInterface) {
          $ym = $dateEmission->format('Y-m');
        } else {
          $ym = (new \DateTimeImmutable(
            (string) $dateEmission,
            $tz
          ))->format('Y-m');
        }

        if (array_key_exists($ym, $outByMonth)) {
          $outByMonth[$ym] +=
            (int) round((float) $r['tvaFloat']);
        }
      }
    }

    // in trend : agrégation DB par mois (dépenses)
    $qbInTrend = $em->createQueryBuilder()
      ->from(Depense::class, 'd')
      ->select("
        SUBSTRING(d.dateDepense, 1, 7) as ym,
        SUM(
          CASE
            WHEN d.tvaDeductible = 1
              THEN (d.montantTvaCents * (d.tvaDeductiblePct / 100))
            ELSE 0
          END
        ) as tvaDedFloat
      ")
      ->where('d.entite = :e')
      ->andWhere('d.dateDepense >= :d1')
      ->andWhere('d.dateDepense < :d2')
      ->groupBy('ym')
      ->setParameter('e', $entite)
      ->setParameter('d1', $d1)
      ->setParameter('d2', $d2Exclusive);

    if ($categorieId) {
      $qbInTrend
        ->andWhere('d.categorie = :cat')
        ->setParameter('cat', (int) $categorieId);
    }

    if ($fournisseurId) {
      $qbInTrend
        ->andWhere('d.fournisseur = :four')
        ->setParameter('four', (int) $fournisseurId);
    }

    if ($depTvaOnly === 'deductible') {
      $qbInTrend->andWhere('d.tvaDeductible = 1');
    }

    if ($depTvaOnly === 'nodeductible') {
      $qbInTrend->andWhere('d.tvaDeductible = 0');
    }

    if ($rate !== null && $rate !== '') {
      $tauxDepense = ((int) $rate === 55)
        ? 5.5
        : (float) $rate;

      $qbInTrend
        ->andWhere('d.tauxTva = :trendTaux')
        ->setParameter('trendTaux', $tauxDepense);
    }

    $inTrendRows = $qbInTrend->getQuery()->getArrayResult();
    $inByMonth = array_fill_keys($labels, 0);
    foreach ($inTrendRows as $r) {
      $ym = (string)$r['ym'];
      if (isset($inByMonth[$ym])) $inByMonth[$ym] += (int) round((float)$r['tvaDedFloat']);
    }

    $trendOut = [];
    $trendIn  = [];
    $trendPay = [];
    foreach ($labels as $ym) {
      $o = (int)($outByMonth[$ym] ?? 0);
      $i = (int)($inByMonth[$ym] ?? 0);
      $trendOut[] = $o;
      $trendIn[]  = $i;
      $trendPay[] = $o - $i;
    }

    // =========================
    // 5) Breakdown par taux
    // =========================
    // OUT : TVA par tvaBp
    $qbOutRate = $em->createQueryBuilder()
      ->from(LigneFacture::class, 'lf')
      ->join('lf.facture', 'f')
      ->select('lf.tvaBp as bp, SUM(
        (
          (lf.qte * lf.puHtCents)
          - COALESCE(lf.remiseMontantCents, 0)
          - (
            (lf.qte * lf.puHtCents)
            * (COALESCE(lf.remisePourcent, 0) / 100)
          )
        ) * (lf.tvaBp / 10000)
      ) as tvaFloat')
      ->where('f.entite = :e')
      ->andWhere('f.dateEmission >= :d1')
      ->andWhere('f.dateEmission < :d2')
      ->andWhere('lf.isDebours = 0')
      ->groupBy('lf.tvaBp')
      ->setParameter('e', $entite)
      ->setParameter('d1', $d1)
      ->setParameter('d2', $d2Exclusive);

    if ($rate !== null && $rate !== '') {
      // rate "55" => 5.5% => 550 bp
      $bp = ((int)$rate === 55) ? 550 : ((int)$rate * 100);
      $qbOutRate->andWhere('lf.tvaBp = :bp')->setParameter('bp', $bp);
    }

    $outRateRows = $qbOutRate->getQuery()->getArrayResult();
    $outRateLabels = [];
    $outRateValues = [];

    if ($regime === 'encaissements') {
      ksort($encaissementOutByRate);

      foreach ($encaissementOutByRate as $bp => $value) {
        $bp = (int) $bp;

        $label = $bp === 550
          ? '5,5 %'
          : number_format(
              $bp / 100,
              1,
              ',',
              ' '
            ) . ' %';

        $outRateLabels[] = $label;
        $outRateValues[] = (int) $value;
      }
    } else {
      foreach ($outRateRows as $r) {
        $bp = (int) $r['bp'];

        $label = $bp === 550
          ? '5,5 %'
          : number_format(
              $bp / 100,
              1,
              ',',
              ' '
            ) . ' %';

        $outRateLabels[] = $label;
        $outRateValues[] =
          (int) round((float) $r['tvaFloat']);
      }
    }

    // IN : TVA déductible par tauxTva (float)
    $qbInRate = $em->createQueryBuilder()
      ->from(Depense::class, 'd')
      ->select('d.tauxTva as taux, SUM(
        CASE
          WHEN d.tvaDeductible = 1 THEN (d.montantTvaCents * (d.tvaDeductiblePct / 100))
          ELSE 0
        END
      ) as tvaDedFloat')
      ->where('d.entite = :e')
      ->andWhere('d.dateDepense >= :d1')
      ->andWhere('d.dateDepense < :d2')
      ->groupBy('d.tauxTva')
      ->setParameter('e', $entite)->setParameter('d1', $d1)->setParameter('d2', $d2Exclusive);

    if ($categorieId)  $qbInRate->andWhere('d.categorie = :cat')->setParameter('cat', (int)$categorieId);
    if ($fournisseurId) $qbInRate->andWhere('d.fournisseur = :four')->setParameter('four', (int)$fournisseurId);
    if ($rate !== null && $rate !== '') {
      $qbInRate->andWhere('d.tauxTva = :t')->setParameter('t', ((int)$rate === 55) ? 5.5 : (float)$rate);
    }

    if ($depTvaOnly === 'deductible') {
      $qbInRate->andWhere('d.tvaDeductible = 1');
    }

    if ($depTvaOnly === 'nodeductible') {
      $qbInRate->andWhere('d.tvaDeductible = 0');
    }

    $inRateRows = $qbInRate->getQuery()->getArrayResult();
    $inRateLabels = [];
    $inRateValues = [];
    foreach ($inRateRows as $r) {
      $t = (float)$r['taux'];
      $label = ($t === 5.5) ? '5,5 %' : (str_replace('.', ',', rtrim(rtrim((string)$t, '0'), '.')) . ' %');
      $inRateLabels[] = $label;
      $inRateValues[] = (int) round((float)$r['tvaDedFloat']);
    }

    // =========================
    // 6) Top fournisseurs TVA
    // =========================
    // =========================
    // 6) Top fournisseurs TVA (+ couleurs)
    // =========================
    $qbTop = $em->createQueryBuilder()
      ->from(Depense::class, 'd')
      ->leftJoin('d.fournisseur', 'f')
      ->select("
        COALESCE(f.nom, '—') as label,
        COALESCE(f.couleurHex, '') as color,
        SUM(
          CASE
            WHEN d.tvaDeductible = 1
              THEN (
                d.montantTvaCents
                * (d.tvaDeductiblePct / 100)
              )
            ELSE 0
          END
        ) as tva
      ")
      ->where('d.entite = :e')
      ->andWhere('d.dateDepense >= :d1')
      ->andWhere('d.dateDepense < :d2')
      ->setMaxResults(10)
      ->setParameter('e', $entite)
      ->setParameter('d1', $d1)
      ->setParameter('d2', $d2Exclusive);

    // mêmes filtres que chez toi
    if ($categorieId)  $qbTop->andWhere('d.categorie = :cat')->setParameter('cat', (int)$categorieId);
    if ($depTvaOnly === 'deductible')   $qbTop->andWhere('d.tvaDeductible = 1');
    if ($depTvaOnly === 'nodeductible') $qbTop->andWhere('d.tvaDeductible = 0');


    if ($fournisseurId) {
      $qbTop
        ->andWhere('d.fournisseur = :topFour')
        ->setParameter('topFour', (int) $fournisseurId);
    }

    if ($rate !== null && $rate !== '') {
      $topTaux = ((int) $rate === 55)
        ? 5.5
        : (float) $rate;

      $qbTop
        ->andWhere('d.tauxTva = :topTaux')
        ->setParameter('topTaux', $topTaux);
    }

    // IMPORTANT : on groupe par fournisseur (pas par label) pour éviter les collisions
    $qbTop->groupBy('f.id')
      ->orderBy('tva', 'DESC');

    $topRows = $qbTop->getQuery()->getArrayResult();

    $topLabels = [];
    $topValues = [];
    $topColors = [];

    foreach ($topRows as $r) {
      $topLabels[] = (string) ($r['label'] ?? '—');
      $topValues[] = (int) ($r['tva'] ?? 0);

      $hex = (string) ($r['color'] ?? '');
      // fallback si pas de couleur / invalide
      if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $hex)) {
        $hex = '#94a3b8';
      }
      $topColors[] = $hex;
    }


    return $this->json([
      'filters' => [
        'dateStart' => $d1->format('Y-m-d'),
        'dateEnd' => $d2->format('Y-m-d'),
      ],
      'kpis' => [
        'regime' => $regime,
        'out' => [
          'tva' => $outTvaCents,
          'ht' => $outHtCents,
          'cnt' => $outCount,
        ],
        'in'  => [
          'cnt' => $inCnt,
          'ht' => $inHtCents,
          'tva' => $inTvaCents,
          'tvaDed' => $inTvaDedCents,
          'tvaNoDed' => $inTvaNoDedCents
        ],
        'payable' => $payable,
      ],
      'charts' => [
        'trend' => [
          'labels' => $labels,
          'outTva' => $trendOut,
          'inTvaDed' => $trendIn,
          'payable' => $trendPay,
        ],
        'outByRate' => ['labels' => $outRateLabels, 'values' => $outRateValues],
        'inByRate'  => ['labels' => $inRateLabels, 'values' => $inRateValues],
        'topSuppliers' => ['labels' => $topLabels, 'values' => $topValues, 'colors' => $topColors],
      ],
    ]);
  }
}
