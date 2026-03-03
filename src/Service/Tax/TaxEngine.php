<?php

namespace App\Service\Tax;

use App\Entity\{Entite, Facture, TaxRule, Paiement, LigneFacture, Depense};
use App\Enum\TaxBase;
use App\Repository\TaxRuleRepository;
use Doctrine\ORM\EntityManagerInterface as EM;

/**
 * TaxEngine = calcule un "preview" de charges/taxes à partir de règles.
 * - Robuste : s'adapte si certaines colonnes n'existent pas encore (method_exists)
 * - Compatible multi-entité
 * - Retourne un tableau exploitable par Twig (cards + breakdown)
 */
final class TaxEngine
{
  public function __construct(
    private EM $em,
    private TaxRuleRepository $taxRuleRepo,
  ) {}

  /**
   * @return array{
   *   currency:string,
   *   period: array{from:\DateTimeImmutable,to:\DateTimeImmutable},
   *   baseTotals: array<string,int>,
   *   totalCents:int,
   *   byKind: array<string,int>,
   *   items: array<int, array{
   *      ruleId:int|null,
   *      code:string,
   *      label:string,
   *      kind:string,
   *      base:string,
   *      rate:float|null,
   *      flatCents:int|null,
   *      baseCents:int,
   *      amountCents:int,
   *      meta:array|null
   *   }>
   * }
   */
  public function estimate(Entite $entite, \DateTimeImmutable $from, \DateTimeImmutable $to, string $currency = 'EUR'): array
  {
    $rules = $this->taxRuleRepo->findActiveForPeriod($entite, $from, $to);

    // Base totals : on calcule chaque base au max 1x, puis on réutilise
    $baseTotals = [];
    $items = [];
    $byKindCents = [];
    $grandTotalCents = 0;

    foreach ($rules as $r) {
      $baseKey = $r->getBase()->value;

      if (!isset($baseTotals[$baseKey])) {
        $baseTotals[$baseKey] = (int) $this->computeBaseCents($entite, $r->getBase(), $from, $to);
      }

      $baseCents = (int) $baseTotals[$baseKey];

      if (!$this->passesConditions($r, $baseCents, $currency)) {
        continue;
      }

      $amountCents = (int) $this->computeAmountCents($r, $baseCents);
      $grandTotalCents += $amountCents;

      $kindKey = $r->getKind()->value;
      $byKindCents[$kindKey] = ($byKindCents[$kindKey] ?? 0) + $amountCents;

      // ✅ Montants exacts en cents + ✅ montants "à payer" arrondis à l'euro supérieur
      $items[] = [
        'ruleId'      => $r->getId(),
        'code'        => (string) $r->getCode(),
        'label'       => (string) $r->getLabel(),
        'kind'        => $kindKey,
        'base'        => $baseKey,
        'rate'        => $r->getRate(),
        'flatCents'   => (int) ($r->getFlatCents() ?? 0),
        'baseCents'   => $baseCents,
        'amountCents' => $amountCents,

        // 👇 nouveaux champs (à utiliser pour KPI/graph/table "charges à payer")
        'baseEur'     => $this->ceilEur($baseCents),
        'amountEur'   => $this->ceilEur($amountCents),

        'meta'        => $r->getMeta(),
      ];
    }

    // tri : montant desc (sur la charge "à payer" si tu veux un rendu cohérent UI)
    usort($items, fn($a, $b) => ($b['amountEur'] <=> $a['amountEur']) ?: ($b['amountCents'] <=> $a['amountCents']));

    // ✅ totaux arrondis à l'euro supérieur (pour KPI/graph/table)
    $byKindEur = [];
    foreach ($byKindCents as $k => $cents) {
      $byKindEur[$k] = $this->ceilEur((int) $cents);
    }

    return [
      'currency'     => strtoupper($currency ?: 'EUR'),
      'period'       => ['from' => $from, 'to' => $to],

      // ✅ bases exactes en cents (utile si besoin) + optionnel arrondi en €
      'baseTotals'   => $baseTotals,
      'baseTotalsEur' => array_map(fn($c) => $this->ceilEur((int) $c), $baseTotals),

      // ✅ exact (cents)
      'totalCents'   => $grandTotalCents,
      'byKindCents'  => $byKindCents,

      // ✅ "à payer" (arrondi euro supérieur)
      'totalEur'     => $this->ceilEur($grandTotalCents),
      'byKind'       => $byKindEur,

      'items'        => $items,
    ];
  }

  /**
   * Arrondi à l'euro supérieur (charges à payer).
   * 1000.00€ => 1000 ; 1000.01€ => 1001
   */
  private function ceilEur(int $cents): int
  {
    if ($cents <= 0) return 0;
    return (int) ceil($cents / 100);
  }


  private function computeAmountCents(TaxRule $r, int $baseCents): int
  {
    // flat prioritaire si présent
    $flat = $r->getFlatCents();
    if ($flat !== null && $flat > 0) {
      return (int)$flat;
    }

    $rate = (float)($r->getRate() ?? 0.0);
    if ($rate <= 0) return 0;

    return (int) round($baseCents * ($rate / 100));
  }

  /**
   * Conditions (optionnel) : structure libre.
   * Exemple:
   * conditions: {
   *   "minBaseCents": 10000,
   *   "maxBaseCents": 999999999,
   *   "currency": "EUR"
   * }
   */
  private function passesConditions(TaxRule $r, int $baseCents, string $currency): bool
  {
    $c = $r->getConditions() ?: [];
    if (!$c) return true;

    if (isset($c['currency']) && strtoupper((string)$c['currency']) !== strtoupper($currency)) {
      return false;
    }
    if (isset($c['minBaseCents']) && $baseCents < (int)$c['minBaseCents']) {
      return false;
    }
    if (isset($c['maxBaseCents']) && $baseCents > (int)$c['maxBaseCents']) {
      return false;
    }

    return true;
  }

  /**
   * BaseCents en fonction du "TaxBase".
   * On fait des requêtes DQL robustes (method_exists fallback).
   */
  private function computeBaseCents(Entite $entite, TaxBase $base, \DateTimeImmutable $from, \DateTimeImmutable $to): int
  {
    return match ($base) {
      TaxBase::CA_FACTURE_HT  => $this->sumFacturesHtHorsDebours($entite, $from, $to),
      TaxBase::CA_FACTURE_TTC => $this->sumFacturesTtcHorsDebours($entite, $from, $to),
      TaxBase::TVA_COLLECTEE  => $this->sumFacturesTvaHorsDebours($entite, $from, $to),


      TaxBase::CA_ENCAISSE_TTC => $this->sumEncaisseTtcHorsDebours($entite, $from, $to),

      TaxBase::CA_ENCAISSE_HT  => $this->sumEncaisseHtHorsDebours($entite, $from, $to),

      TaxBase::TVA_DEDUCTIBLE  => $this->sumDepenses($entite, $from, $to, 'montantTvaCents'),
    };
  }

  private function sumFacturesTtcHorsDebours(Entite $entite, \DateTimeImmutable $from, \DateTimeImmutable $to): int
  {
    $ht  = $this->sumFacturesHtHorsDebours($entite, $from, $to);
    $tva = $this->sumFacturesTvaHorsDebours($entite, $from, $to);
    return $ht + $tva;
  }


  private function sumFactures(Entite $entite, \DateTimeImmutable $from, \DateTimeImmutable $to, string $field): int
  {
    $factureClass = Facture::class;
    if (!class_exists($factureClass)) return 0;

    try {
      $qb = $this->em->createQueryBuilder()
        ->select("COALESCE(SUM(f.$field),0)")
        ->from($factureClass, 'f')
        ->andWhere('f.entite = :e')->setParameter('e', $entite)
        ->andWhere('f.dateEmission >= :from')->setParameter('from', $from)
        ->andWhere('f.dateEmission < :to')->setParameter('to', $to);

      if ($this->hasField($factureClass, 'isDebours')) {
        $qb->andWhere('f.isDebours = 0');
      }

      return (int) $qb->getQuery()->getSingleScalarResult();
    } catch (\Throwable) {
      return 0;
    }
  }


  private function sumPaiementsEncaisse(Entite $entite, \DateTimeImmutable $from, \DateTimeImmutable $to): int
  {
    $paiementClass = Paiement::class;
    if (!class_exists($paiementClass)) return 0;

    try {
      $qb = $this->em->createQueryBuilder()
        ->select('COALESCE(SUM(p.montantCents),0)')
        ->from($paiementClass, 'p')
        ->leftJoin('p.facture', 'f')
        ->andWhere('p.entite = :e')->setParameter('e', $entite)
        ->andWhere('p.datePaiement >= :from')->setParameter('from', $from)
        ->andWhere('p.datePaiement < :to')->setParameter('to', $to);

      if ($this->hasField(Facture::class, 'isDebours')) {
        $qb->andWhere('(f.id IS NULL OR f.isDebours = 0)');
      }

      return (int) $qb->getQuery()->getSingleScalarResult();
    } catch (\Throwable) {
      return 0;
    }
  }




  private function sumDepenses(Entite $entite, \DateTimeImmutable $from, \DateTimeImmutable $to, string $field): int
  {
    $depenseClass = Depense::class;
    if (!class_exists($depenseClass)) return 0;

    $dateFieldCandidates = ['dateDepense', 'datePaiement', 'dateCreation', 'createdAt'];

    foreach ($dateFieldCandidates as $dateField) {
      try {
        $qb = $this->em->createQueryBuilder()
          ->select("COALESCE(SUM(d.$field),0)")
          ->from($depenseClass, 'd')
          ->andWhere('d.entite = :e')->setParameter('e', $entite)
          ->andWhere("d.$dateField >= :from")->setParameter('from', $from)
          ->andWhere("d.$dateField < :to")->setParameter('to', $to);

        if ($this->hasField($depenseClass, 'isDebours')) {
          $qb->andWhere('d.isDebours = 0');
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
      } catch (\Throwable) {
        // try next date field
      }
    }

    return 0;
  }



  private function sumEncaisseHtProrata(Entite $entite, \DateTimeImmutable $from, \DateTimeImmutable $to): int
  {
    $paiementClass = Paiement::class;
    if (!class_exists($paiementClass)) return 0;

    try {
      $qb = $this->em->createQueryBuilder()
        ->select('COALESCE(SUM( (p.montantCents * f.montantHtCents) / NULLIF(f.montantTtcCents, 0) ), 0)')
        ->from($paiementClass, 'p')
        ->innerJoin('p.facture', 'f')
        ->andWhere('p.entite = :e')->setParameter('e', $entite)
        ->andWhere('p.datePaiement >= :from')->setParameter('from', $from)
        ->andWhere('p.datePaiement < :to')->setParameter('to', $to);

      if ($this->hasField(Facture::class, 'isDebours')) {
        $qb->andWhere('f.isDebours = 0');
      }

      $raw = $qb->getQuery()->getSingleScalarResult();
      return (int) round((float) $raw);
    } catch (\Throwable) {
      return 0;
    }
  }



  private function sumEncaisseTvaProrata(Entite $entite, \DateTimeImmutable $from, \DateTimeImmutable $to): int
  {
    $paiementClass = Paiement::class;
    if (!class_exists($paiementClass)) return 0;

    try {
      $raw = $this->em->createQueryBuilder()
        ->select('COALESCE(SUM( (p.montantCents * f.montantTvaCents) / NULLIF(f.montantTtcCents, 0) ), 0)')
        ->from($paiementClass, 'p')
        ->innerJoin('p.facture', 'f')
        ->andWhere('p.entite = :e')->setParameter('e', $entite)
        ->andWhere('p.datePaiement >= :from')->setParameter('from', $from)
        ->andWhere('p.datePaiement <= :to')->setParameter('to', $to)
        ->getQuery()->getSingleScalarResult();

      return (int) round((float) $raw);
    } catch (\Throwable) {
      return 0;
    }
  }


  private function hasField(string $class, string $field): bool
  {
    try {
      return $this->em->getClassMetadata($class)->hasField($field);
    } catch (\Throwable) {
      return false;
    }
  }


  private function sumFacturesHtHorsDebours(Entite $entite, \DateTimeImmutable $from, \DateTimeImmutable $to): int
  {
    try {
      return (int)$this->em->createQueryBuilder()
        ->select('COALESCE(
          SUM(
            CASE WHEN lf.isDebours = 0 THEN
              (lf.qte * lf.puHtCents) * (1 - (COALESCE(lf.remisePourcent,0)/100))
            ELSE 0 END
          ),
        0)
        ')
        ->from(LigneFacture::class, 'lf')
        ->innerJoin('lf.facture', 'f')
        ->andWhere('f.entite = :e')->setParameter('e', $entite)
        ->andWhere('f.dateEmission >= :from')->setParameter('from', $from)
        ->andWhere('f.dateEmission < :to')->setParameter('to', $to)
        ->andWhere('lf.isDebours = 0')
        ->getQuery()->getSingleScalarResult();
    } catch (\Throwable) {
      return 0;
    }
  }


  private function sumFacturesTvaHorsDebours(Entite $entite, \DateTimeImmutable $from, \DateTimeImmutable $to): int
  {
    try {
      return (int)$this->em->createQueryBuilder()
        ->select('COALESCE(SUM(
                CASE WHEN lf.isDebours = 0 THEN
                    ROUND(
                        ((lf.qte * lf.puHtCents) * (1 - (COALESCE(lf.remisePourcent,0)/100))) * (lf.tvaBp / 10000),
                    0)
                ELSE 0 END
            ),0)')
        ->from(LigneFacture::class, 'lf')
        ->innerJoin('lf.facture', 'f')
        ->andWhere('f.entite = :e')->setParameter('e', $entite)
        ->andWhere('f.dateEmission >= :from')->setParameter('from', $from)
        ->andWhere('f.dateEmission < :to')->setParameter('to', $to)
        ->getQuery()->getSingleScalarResult();
    } catch (\Throwable) {
      return 0;
    }
  }


  private function mapFactureNonDeboursHtCents(Entite $entite, \DateTimeImmutable $from, \DateTimeImmutable $toExclusive): array
  {
    try {
      $rows = $this->em->createQueryBuilder()
        ->select('IDENTITY(lf.facture) AS factureId')
        ->addSelect('
                COALESCE(
                    SUM(
                        CASE WHEN lf.isDebours = 0 THEN
                            (lf.qte * lf.puHtCents) * (1 - (COALESCE(lf.remisePourcent,0) / 100))
                        ELSE 0 END
                    ),
                0
                ) AS htNonDebours
            ')
        ->from(LigneFacture::class, 'lf')
        ->innerJoin('lf.facture', 'f')
        ->andWhere('f.entite = :e')->setParameter('e', $entite)
        ->andWhere('f.dateEmission >= :from')->setParameter('from', $from)
        ->andWhere('f.dateEmission < :to')->setParameter('to', $toExclusive)
        ->groupBy('lf.facture')
        ->getQuery()
        ->getArrayResult();

      $map = [];
      foreach ($rows as $r) {
        $map[(int)$r['factureId']] = (int) round((float) ($r['htNonDebours'] ?? 0));
      }
      return $map;
    } catch (\Throwable) {
      return [];
    }
  }


  private function mapFactureNonDeboursParts(Entite $entite, \DateTimeImmutable $from, \DateTimeImmutable $toExclusive): array
  {
    try {
      $rows = $this->em->createQueryBuilder()
        ->select('f.id AS factureId')
        ->addSelect('COALESCE(SUM(
                CASE WHEN lf.isDebours = 0 THEN
                    (lf.qte * lf.puHtCents) * (1 - (COALESCE(lf.remisePourcent,0)/100))
                ELSE 0 END
            ),0) AS htNonDeb')
        ->addSelect('COALESCE(SUM(
                CASE WHEN lf.isDebours = 0 THEN
                    ((lf.qte * lf.puHtCents) * (1 - (COALESCE(lf.remisePourcent,0)/100))) * (lf.tvaBp / 10000)
                ELSE 0 END
            ),0) AS tvaNonDeb')
        ->from(Paiement::class, 'p')
        ->innerJoin('p.facture', 'f')
        ->innerJoin(LigneFacture::class, 'lf', 'WITH', 'lf.facture = f')
        ->andWhere('f.entite = :e')->setParameter('e', $entite)
        ->andWhere('p.datePaiement >= :from')->setParameter('from', $from)
        ->andWhere('p.datePaiement < :to')->setParameter('to', $toExclusive)
        ->groupBy('f.id')
        ->getQuery()->getArrayResult();

      $map = [];
      foreach ($rows as $r) {
        $ht  = (int) round((float) ($r['htNonDeb'] ?? 0));
        $tva = (int) round((float) ($r['tvaNonDeb'] ?? 0));
        $map[(int)$r['factureId']] = [
          'ht'  => $ht,
          'tva' => $tva,
          'ttc' => $ht + $tva,
        ];
      }
      return $map;
    } catch (\Throwable $e) {
      dd('ERR mapFactureNonDeboursParts', $e->getMessage());
    }
  }





  private function sumEncaisseTtcHorsDebours(Entite $entite, \DateTimeImmutable $from, \DateTimeImmutable $toExclusive): int
  {
    $map = $this->mapFactureNonDeboursParts($entite, $from, $toExclusive);

    try {
      $rows = $this->em->createQueryBuilder()
        ->select('p.montantCents AS payCents, f.id AS factureId, f.montantTtcCents AS fTtc')
        ->from(Paiement::class, 'p')
        ->innerJoin('p.facture', 'f')
        ->andWhere('f.entite = :e')->setParameter('e', $entite)
        ->andWhere('p.datePaiement >= :from')->setParameter('from', $from)
        ->andWhere('p.datePaiement < :to')->setParameter('to', $toExclusive)
        ->getQuery()->getArrayResult();

      $sum = 0.0;
      foreach ($rows as $r) {
        $id = (int)$r['factureId'];
        $pay = (int)$r['payCents'];
        $fTtc = (int)$r['fTtc'];

        $ttcNonDeb = (int)($map[$id]['ttc'] ?? 0);

        if ($fTtc > 0 && $ttcNonDeb > 0) {
          $ratio = min(1, max(0, $ttcNonDeb / $fTtc)); // ✅ clamp sécurité
          $sum += $pay * $ratio;
        }
      }
      return (int) round($sum);
    } catch (\Throwable) {
      return 0;
    }
  }

  private function sumEncaisseHtHorsDebours(Entite $entite, \DateTimeImmutable $from, \DateTimeImmutable $toExclusive): int
  {
    $map = $this->mapFactureNonDeboursParts($entite, $from, $toExclusive);

    try {
      $rows = $this->em->createQueryBuilder()
        ->select('p.montantCents AS payCents, f.id AS factureId, f.montantTtcCents AS fTtc')
        ->from(Paiement::class, 'p')
        ->innerJoin('p.facture', 'f')
        ->andWhere('f.entite = :e')->setParameter('e', $entite)
        ->andWhere('p.datePaiement >= :from')->setParameter('from', $from)
        ->andWhere('p.datePaiement < :to')->setParameter('to', $toExclusive)
        ->getQuery()->getArrayResult();



      $sum = 0.0;
      foreach ($rows as $r) {
        $id = (int)$r['factureId'];
        $pay = (int)$r['payCents'];
        $fTtc = (int)$r['fTtc'];

        $htNonDeb = (int)($map[$id]['ht'] ?? 0);

        if ($fTtc > 0 && $htNonDeb > 0) {
          $ratio = min(1, max(0, $htNonDeb / $fTtc)); // ✅ clamp sécurité
          $sum += $pay * $ratio;
        }
      }
      return (int) round($sum);
    } catch (\Throwable) {
      return 0;
    }
  }

  private function sumPaiementsEncaisseHorsDebours(Entite $entite, \DateTimeImmutable $from, \DateTimeImmutable $toExclusive): int
  {
    // Alias explicite pour TaxBase::CA_ENCAISSE_TTC
    return $this->sumEncaisseTtcHorsDebours($entite, $from, $toExclusive);
  }


  public function caEncaisseTtcHorsDebours(Entite $entite, \DateTimeImmutable $from, \DateTimeImmutable $toExclusive): int
  {
    return $this->sumEncaisseTtcHorsDebours($entite, $from, $toExclusive);
  }

  public function caEncaisseHtHorsDebours(Entite $entite, \DateTimeImmutable $from, \DateTimeImmutable $toExclusive): int
  {
    return $this->sumEncaisseHtHorsDebours($entite, $from, $toExclusive);
  }
}
