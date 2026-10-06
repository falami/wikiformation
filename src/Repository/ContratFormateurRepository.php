<?php

namespace App\Repository;

use App\Entity\ContratFormateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContratFormateur>
 */
class ContratFormateurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContratFormateur::class);
    }

    /** @return ContratFormateur[] */
    public function findAwaitingTrainerSignature(\App\Entity\Entite $entite, \App\Entity\Formateur $trainer): array
    {
        return $this->createQueryBuilder('c')
            ->innerJoin('c.formateur', 'trainer')
            ->innerJoin('c.session', 'session')->addSelect('session')
            ->leftJoin('session.formation', 'formation')->addSelect('formation')
            ->andWhere('c.entite = :entite AND trainer.entite = :entite AND session.entite = :entite')
            ->andWhere('c.formateur = :trainer')
            ->andWhere('c.status IN (:statuses)')
            ->andWhere('c.signatureAt IS NULL')
            ->andWhere("c.signatureDataUrl IS NULL OR c.signatureDataUrl = ''")
            ->setParameter('entite', $entite)->setParameter('trainer', $trainer)
            ->setParameter('statuses', ['BROUILLON', 'ENVOYE'])
            ->orderBy('c.dateCreation', 'ASC')->addOrderBy('c.id', 'ASC')
            ->getQuery()->getResult();
    }

    //    /**
    //     * @return ContratFormateur[] Returns an array of ContratFormateur objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('c')
    //            ->andWhere('c.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('c.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?ContratFormateur
    //    {
    //        return $this->createQueryBuilder('c')
    //            ->andWhere('c.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
