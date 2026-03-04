<?php

namespace App\Repository\Billing;

use App\Entity\Billing\FactureCheckout;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class FactureCheckoutRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FactureCheckout::class);
    }

    public function findOneBySessionId(string $sessionId): ?FactureCheckout
    {
        return $this->findOneBy(['stripeCheckoutSessionId' => $sessionId]);
    }

    public function findOneByPaymentIntent(string $pi): ?FactureCheckout
    {
        return $this->findOneBy(['stripePaymentIntentId' => $pi]);
    }
}