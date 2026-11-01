<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\{Entite, FactureBrouillon};
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<FactureBrouillon> */
final class FactureBrouillonRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, FactureBrouillon::class); }

    /** @return list<FactureBrouillon> */
    public function findActiveForEntite(Entite $entite, int $limit = 100): array
    {
        return $this->createQueryBuilder('draft')
            ->andWhere('draft.entite = :entite')->setParameter('entite', $entite)
            ->andWhere('draft.archivedAt IS NULL')->andWhere('draft.factureEmise IS NULL')
            ->orderBy('draft.updatedAt', 'DESC')->addOrderBy('draft.id', 'DESC')
            ->setMaxResults(max(1, $limit))->getQuery()->getResult();
    }

    public function countActiveForEntite(Entite $entite): int
    {
        return $this->count(['entite' => $entite, 'archivedAt' => null, 'factureEmise' => null]);
    }
}
