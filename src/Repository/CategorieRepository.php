<?php

namespace App\Repository;

use App\Entity\Categorie;
use App\Entity\Entite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class CategorieRepository extends ServiceEntityRepository
{
  public function __construct(ManagerRegistry $registry)
  {
    parent::__construct($registry, Categorie::class);
  }

  /** @return Categorie[] */
  public function findByEntiteOrdered(Entite $entite): array
  {
    return $this->createQueryBuilder('c')
      ->leftJoin('c.parent', 'p')->addSelect('p')
      ->andWhere('c.entite = :e')->setParameter('e', $entite)
      ->orderBy('p.nom', 'ASC')
      ->addOrderBy('c.nom', 'ASC')
      ->getQuery()->getResult();
  }


  /**
   * Catégories racines (parent = NULL) affichées sur la home
   * @return Categorie[]
   */
  public function findHomeRoots(): array
  {
    return $this->createQueryBuilder('c')
      ->andWhere('c.parent IS NULL')
      ->andWhere('c.showOnHome = 1')
      ->orderBy('c.nom', 'ASC')
      ->getQuery()
      ->getResult();
  }
}
