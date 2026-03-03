<?php
// src/Service/Tax/TaxBaseResolver.php

namespace App\Service\Tax;

use App\Entity\Entite;
use App\Entity\Facture;
use App\Entity\Paiement;
use App\Entity\Depense;
use App\Enum\TaxBase;
use Doctrine\ORM\EntityManagerInterface as EM;

final class TaxBaseResolver
{
  public function __construct(private EM $em) {}

  /**
   * Retourne une base en CENTIMES selon le TaxBase.
   * from inclusive, to exclusive (comme tu fais déjà “first day of next month 00:00”).
   */
  public function resolve(Entite $entite, \DateTimeImmutable $from, \DateTimeImmutable $to, TaxBase $base): int
  {
    return match ($base) {
      TaxBase::CA_ENCAISSE_TTC => $this->sumPaiementsCents($entite, $from, $to),
      TaxBase::CA_ENCAISSE_HT  => $this->estimateEncaisseHtFromFactures($entite, $from, $to),

      TaxBase::CA_FACTURE_TTC  => $this->sumFacturesTtcCents($entite, $from, $to),
      TaxBase::CA_FACTURE_HT   => $this->sumFacturesHtCents($entite, $from, $to),

      TaxBase::TVA_COLLECTEE   => $this->sumFacturesTvaCents($entite, $from, $to),
      TaxBase::TVA_DEDUCTIBLE  => $this->sumDepensesTvaDeductibleCents($entite, $from, $to),
    };
  }

  private function sumFacturesTtcCents(Entite $entite, \DateTimeImmutable $from, \DateTimeImmutable $to): int
  {
    return (int)$this->em->createQueryBuilder()
      ->select('COALESCE(SUM(f.montantTtcCents),0)')
      ->from(Facture::class, 'f')
      ->andWhere('f.entite = :e')->setParameter('e', $entite)
      ->andWhere('f.dateEmission >= :from')->setParameter('from', $from)
      ->andWhere('f.dateEmission < :to')->setParameter('to', $to)
      ->getQuery()->getSingleScalarResult();
  }

  private function sumFacturesHtCents(Entite $entite, \DateTimeImmutable $from, \DateTimeImmutable $to): int
  {
    return (int)$this->em->createQueryBuilder()
      ->select('COALESCE(SUM(f.montantHtCents),0)')
      ->from(Facture::class, 'f')
      ->andWhere('f.entite = :e')->setParameter('e', $entite)
      ->andWhere('f.dateEmission >= :from')->setParameter('from', $from)
      ->andWhere('f.dateEmission < :to')->setParameter('to', $to)
      ->getQuery()->getSingleScalarResult();
  }

  private function sumFacturesTvaCents(Entite $entite, \DateTimeImmutable $from, \DateTimeImmutable $to): int
  {
    return (int)$this->em->createQueryBuilder()
      ->select('COALESCE(SUM(f.montantTvaCents),0)')
      ->from(Facture::class, 'f')
      ->andWhere('f.entite = :e')->setParameter('e', $entite)
      ->andWhere('f.dateEmission >= :from')->setParameter('from', $from)
      ->andWhere('f.dateEmission < :to')->setParameter('to', $to)
      ->getQuery()->getSingleScalarResult();
  }

  private function sumPaiementsCents(Entite $entite, \DateTimeImmutable $from, \DateTimeImmutable $to): int
  {
    // Paiements liés à des factures de l’entité
    return (int)$this->em->createQueryBuilder()
      ->select('COALESCE(SUM(p.montantCents),0)')
      ->from(Paiement::class, 'p')
      ->innerJoin('p.facture', 'f')
      ->andWhere('f.entite = :e')->setParameter('e', $entite)
      ->andWhere('p.datePaiement >= :from')->setParameter('from', $from)
      ->andWhere('p.datePaiement < :to')->setParameter('to', $to)
      ->getQuery()->getSingleScalarResult();
  }

  /**
   * Encaisse HT : si tu n’as que “paiements TTC”, on estime un HT “proportionnel”
   * via ratio HT/TTC sur factures émises sur la période (fallback propre).
   */
  private function estimateEncaisseHtFromFactures(Entite $entite, \DateTimeImmutable $from, \DateTimeImmutable $to): int
  {
    $encaisseTtc = $this->sumPaiementsCents($entite, $from, $to);

    $ttcFact = $this->sumFacturesTtcCents($entite, $from, $to);
    $htFact  = $this->sumFacturesHtCents($entite, $from, $to);

    if ($ttcFact <= 0 || $htFact <= 0) {
      // fallback : si aucune facture sur période, on considère TTC≈HT (ou 0)
      return $encaisseTtc;
    }

    $ratio = $htFact / $ttcFact; // float
    return (int) round($encaisseTtc * $ratio);
  }

  private function sumDepensesTvaDeductibleCents(Entite $entite, \DateTimeImmutable $from, \DateTimeImmutable $to): int
  {
    // Adapte ici selon TON modèle Depense:
    // - montantTvaCents ?
    // - tvaDeductibleCents ?
    // - dateDepense ?
    // Je mets une version “courante”: Depense.montantTvaCents et Depense.tvaDeductible = true
    return (int)$this->em->createQueryBuilder()
      ->select('COALESCE(SUM(d.montantTvaCents),0)')
      ->from(Depense::class, 'd')
      ->andWhere('d.entite = :e')->setParameter('e', $entite)
      ->andWhere('d.dateDepense >= :from')->setParameter('from', $from)
      ->andWhere('d.dateDepense < :to')->setParameter('to', $to)
      ->andWhere('d.tvaDeductible = 1')
      ->getQuery()->getSingleScalarResult();
  }
}
