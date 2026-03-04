<?php

namespace App\Repository\Billing;

use App\Entity\Billing\FactureCheckout;
use App\Entity\Facture;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class FactureCheckoutRepository extends ServiceEntityRepository
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

    /**
     * ✅ Récupère un checkout "en cours" pour éviter de recréer des sessions
     * (created + url non nulle) – le plus récent.
     */
    public function findLatestCreatedForFacture(Facture $facture): ?FactureCheckout
    {
        return $this->createQueryBuilder('fc')
            ->andWhere('fc.facture = :f')
            ->andWhere('fc.status = :st')
            ->andWhere('fc.checkoutUrl IS NOT NULL')
            ->setParameter('f', $facture)
            ->setParameter('st', FactureCheckout::STATUS_CREATED)
            ->orderBy('fc.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * ✅ Si tu veux être strict : “created” récents uniquement (anti vieux liens)
     * Ex: ne réutiliser que les sessions des 2 dernières heures.
     */
    public function findLatestCreatedForFactureSince(Facture $facture, \DateTimeImmutable $since): ?FactureCheckout
    {
        return $this->createQueryBuilder('fc')
            ->andWhere('fc.facture = :f')
            ->andWhere('fc.status = :st')
            ->andWhere('fc.checkoutUrl IS NOT NULL')
            ->andWhere('fc.createdAt >= :since')
            ->setParameter('f', $facture)
            ->setParameter('st', FactureCheckout::STATUS_CREATED)
            ->setParameter('since', $since)
            ->orderBy('fc.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}