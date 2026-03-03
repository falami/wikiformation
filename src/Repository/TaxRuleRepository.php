<?php

namespace App\Repository;

use App\Entity\Entite;
use App\Entity\TaxRule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class TaxRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaxRule::class);
    }

    public function findActiveForPeriod(Entite $entite, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.entite = :e')->setParameter('e', $entite)
            ->andWhere('(r.validFrom IS NULL OR r.validFrom < :to)')->setParameter('to', $to)
            ->andWhere('(r.validTo IS NULL OR r.validTo >= :from)')->setParameter('from', $from)
            ->orderBy('r.kind', 'ASC')
            ->addOrderBy('r.code', 'ASC')
            ->getQuery()->getResult();
    }
}
