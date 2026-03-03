<?php
namespace App\Repository;

use App\Entity\Formation;
use App\Entity\FormationContentNode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class FormationContentNodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FormationContentNode::class);
    }

    /** @return FormationContentNode[] */
    public function rootsForFormation(Formation $formation): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.formation = :f')->setParameter('f', $formation)
            ->andWhere('n.parent IS NULL')
            ->orderBy('n.position', 'ASC')->addOrderBy('n.id','ASC')
            ->getQuery()->getResult();
    }
}
