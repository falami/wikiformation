<?php // src/Repository/Billing/EntiteSubscriptionRepository.php
namespace App\Repository\Billing;

use App\Entity\Billing\EntiteSubscription;
use App\Entity\Entite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class EntiteSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EntiteSubscription::class);
    }

    public function findLatestForEntite(Entite $entite): ?EntiteSubscription
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.entite = :e')
            ->setParameter('e', $entite)
            ->orderBy('s.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByStripeCustomer(string $customerId): ?EntiteSubscription
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.stripeCustomerId = :c')
            ->setParameter('c', $customerId)
            ->orderBy('s.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByStripeSubscription(string $subId): ?EntiteSubscription
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.stripeSubscriptionId = :sid')
            ->setParameter('sid', $subId)
            ->orderBy('s.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    // src/Repository/Billing/EntiteSubscriptionRepository.php

    public function entiteHasConsumedTrial(Entite $entite): bool
    {
        // Si une souscription a déjà eu un trialEndsAt enregistré => essai déjà consommé
        $qb = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.entite = :e')
            ->andWhere('s.trialEndsAt IS NOT NULL')
            ->setParameter('e', $entite);

        return (int)$qb->getQuery()->getSingleScalarResult() > 0;
    }
}
