<?php

namespace App\Repository;

use App\Entity\Inscription;
use App\Enum\QcmPhase;
use App\Entity\QcmAssignment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QcmAssignment>
 */
class QcmAssignmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QcmAssignment::class);
    }

    public function findOneByInscriptionAndPhase(Inscription $i, QcmPhase $phase): ?QcmAssignment
    {
        return $this->findOneBy(['inscription' => $i, 'phase' => $phase]);
    }

    /** @return QcmAssignment[] */
    public function findForStagiaire(int $userId): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.inscription', 'ins')
            ->join('ins.stagiaire', 'u')
            ->andWhere('u.id = :uid')->setParameter('uid', $userId)
            ->orderBy('a.assignedAt', 'DESC')
            ->getQuery()->getResult();
    }

    //    /**
    //     * @return QcmAssignment[] Returns an array of QcmAssignment objects
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

    //    public function findOneBySomeField($value): ?QcmAssignment
    //    {
    //        return $this->createQueryBuilder('q')
    //            ->andWhere('q.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
