<?php

namespace App\Service\Finance;

use App\Entity\{Entite, Facture, Paiement};
use Doctrine\ORM\{EntityManagerInterface, QueryBuilder};

/** The accounting and activity dashboards use the same revenue basis. */
final class RevenueQueries
{
    public static function invoices(EntityManagerInterface $em, Entite $entite, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): QueryBuilder
    {
        return self::period($em->createQueryBuilder()->from(Facture::class, 'fa')
            ->where('fa.entite = :e')->setParameter('e', $entite), 'fa.dateEmission', $from, $to);
    }

    public static function payments(EntityManagerInterface $em, Entite $entite, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): QueryBuilder
    {
        return self::period($em->createQueryBuilder()->from(Paiement::class, 'pa')
            ->where('pa.entite = :e')->setParameter('e', $entite), 'pa.datePaiement', $from, $to);
    }

    private static function period(QueryBuilder $qb, string $field, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): QueryBuilder
    {
        if ($from) $qb->andWhere($field.' >= :start')->setParameter('start', $from);
        if ($to) $qb->andWhere($field.' <= :end')->setParameter('end', $to);
        return $qb;
    }

    public static function billed(QueryBuilder $qb): int
    {
        return (int) (clone $qb)->select('COALESCE(SUM(fa.montantHtCents), 0)')->getQuery()->getSingleScalarResult();
    }

    public static function received(QueryBuilder $qb): int
    {
        return (int) (clone $qb)->select('COALESCE(SUM(pa.ventilationHtHorsDeboursCents), 0)')->getQuery()->getSingleScalarResult();
    }
}
