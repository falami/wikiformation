<?php

namespace App\Repository;

use App\Entity\DepenseFournisseur;
use App\Entity\Entite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DepenseFournisseur>
 */
class DepenseFournisseurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DepenseFournisseur::class);
    }

    public function qbForEntite(Entite $entite)
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.entite = :e')->setParameter('e', $entite)
            ->andWhere('f.actif = 1')
            ->orderBy('f.nom', 'ASC');
    }

    public function findOneByEntiteAndNom(Entite $entite, string $nom): ?DepenseFournisseur
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.entite = :e')->setParameter('e', $entite)
            ->andWhere('LOWER(f.nom) = :n')->setParameter('n', mb_strtolower(trim($nom)))
            ->getQuery()->getOneOrNullResult();
    }

    //    /**
    //     * @return DepenseFournisseur[] Returns an array of DepenseFournisseur objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('d')
    //            ->andWhere('d.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('d.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?DepenseFournisseur
    //    {
    //        return $this->createQueryBuilder('d')
    //            ->andWhere('d.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
