<?php

namespace App\Repository;

use App\Entity\Qcm;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Qcm>
 */
class QcmRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Qcm::class);
    }

    public function findLatestActiveForEntite(?int $entiteId): ?Qcm
    {
        $qb = $this->createQueryBuilder('q')
            ->andWhere('q.isActive = 1')
            ->orderBy('q.id', 'DESC')
            ->setMaxResults(1);

        if ($entiteId) {
            $qb->andWhere('q.entite = :e')->setParameter('e', $entiteId);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    //    /**
    //     * @return Qcm[] Returns an array of Qcm objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('q')
    //            ->andWhere('q.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('q.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Qcm
    //    {
    //        return $this->createQueryBuilder('q')
    //            ->andWhere('q.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
