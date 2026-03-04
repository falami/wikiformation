<?php

namespace App\Repository\Billing;

use App\Entity\Billing\StripeCustomerMap;
use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class StripeCustomerMapRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StripeCustomerMap::class);
    }

    public function findFor(string $connectedAccountId, Utilisateur $u): ?StripeCustomerMap
    {
        return $this->findOneBy([
            'connectedAccountId' => $connectedAccountId,
            'utilisateur' => $u,
        ]);
    }
}