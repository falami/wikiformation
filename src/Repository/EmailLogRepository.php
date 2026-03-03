<?php

namespace App\Repository;

use App\Entity\EmailLog;
use App\Entity\Prospect;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class EmailLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailLog::class);
    }

    /** @return EmailLog[] */
    public function lastForProspect(Prospect $p, int $limit = 20): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.prospect = :p')->setParameter('p', $p)
            ->orderBy('l.sentAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }
}
