<?php // src/Repository/Billing/PlanRepository.php
namespace App\Repository\Billing;

use App\Entity\Billing\Plan;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class PlanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Plan::class);
    }

    /** @return Plan[] */
    // src/Repository/Billing/PlanRepository.php
    public function findActiveOrdered(): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.isActive = true')
            // ordre ASC, et si null => à la fin
            ->addOrderBy('CASE WHEN p.ordre IS NULL THEN 1 ELSE 0 END', 'ASC')
            ->addOrderBy('p.ordre', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
