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
    
    /** Commercial catalogue only; historical plans remain available on existing subscriptions. @return Plan[] */
    public function findPublicOffers(): array
    {
        $plans = $this->findActiveOrdered();
        $plans = array_values(array_filter($plans, static fn(Plan $p): bool => $p->isAvailableForNewSubscription('month')));
        usort($plans, static fn(Plan $a, Plan $b): int => array_search(strtoupper($a->getCode()), Plan::PUBLIC_CODES, true) <=> array_search(strtoupper($b->getCode()), Plan::PUBLIC_CODES, true));
        return $plans;
    }

    public function findNextUpgradePlan(?Plan $currentPlan): ?Plan
    {
        $currentPrice = $currentPlan?->getPriceMonthlyCents() ?? 0;
        foreach ($this->findPublicOffers() as $plan) {
            if ($plan->getId() !== $currentPlan?->getId() && ($plan->getPriceMonthlyCents() ?? 0) > $currentPrice) return $plan;
        }
        return null;
    }
}
