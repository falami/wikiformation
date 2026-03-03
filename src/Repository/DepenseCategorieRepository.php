<?php

namespace App\Repository;

use App\Entity\DepenseCategorie;
use App\Entity\Entite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DepenseCategorie>
 */
class DepenseCategorieRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DepenseCategorie::class);
    }

    public function qbForEntite(Entite $entite)
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.entite = :e')->setParameter('e', $entite)
            ->andWhere('c.actif = 1')
            ->orderBy('c.libelle', 'ASC');
    }

    public function findOneByEntiteAndLibelle(Entite $entite, string $libelle): ?DepenseCategorie
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.entite = :e')->setParameter('e', $entite)
            ->andWhere('LOWER(c.libelle) = :l')->setParameter('l', mb_strtolower(trim($libelle)))
            ->getQuery()->getOneOrNullResult();
    }

    //    /**
    //     * @return DepenseCategorie[] Returns an array of DepenseCategorie objects
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

    //    public function findOneBySomeField($value): ?DepenseCategorie
    //    {
    //        return $this->createQueryBuilder('d')
    //            ->andWhere('d.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
