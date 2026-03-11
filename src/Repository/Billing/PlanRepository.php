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
    
    public function findNextUpgradePlan(?Plan $currentPlan): ?Plan
    {
        $qb = $this->createQueryBuilder('p')
            ->andWhere('p.isActive = true')
            ->orderBy('p.ordre', 'ASC')
            ->setMaxResults(1);

        if ($currentPlan instanceof Plan) {
            $qb->andWhere('p.ordre > :ordre')
               ->setParameter('ordre', $currentPlan->getOrdre() ?? 0);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }
}
