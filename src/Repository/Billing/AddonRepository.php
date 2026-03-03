<?php // src/Repository/Billing/AddonRepository.php
namespace App\Repository\Billing;

use App\Entity\Billing\Addon;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class AddonRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Addon::class);
    }

    /** @return Addon[] */
    public function findActiveOrdered(): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.isActive = 1')
            ->orderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return Addon[] */
    public function findByCodes(array $codes): array
    {
        if (!$codes) return [];
        return $this->createQueryBuilder('a')
            ->andWhere('a.code IN (:codes)')
            ->setParameter('codes', $codes)
            ->getQuery()
            ->getResult();
    }
}
