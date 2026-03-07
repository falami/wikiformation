<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PublicHost;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\ORM\QueryBuilder;

final class PublicHostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PublicHost::class);
    }

    public function findActiveByHost(string $host): ?PublicHost
    {
        return $this->createQueryBuilder('ph')
            ->andWhere('LOWER(ph.host) = :host')
            ->andWhere('ph.isActive = 1')
            ->setParameter('host', mb_strtolower(trim($host)))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return PublicHost[]
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('ph')
            ->orderBy('ph.name', 'ASC')
            ->addOrderBy('ph.host', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function createDataTableFilteredQb(
        ?string $search = null,
        string $statusFilter = 'all',
        string $moduleFilter = 'all'
    ): QueryBuilder {
        $qb = $this->createQueryBuilder('ph')
            ->leftJoin('ph.formations', 'f')
            ->addSelect('COUNT(f.id) AS HIDDEN formationsCount')
            ->groupBy('ph.id');

        if ($search !== null && $search !== '') {
            $qb
                ->andWhere('ph.name LIKE :search OR ph.host LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        if ($statusFilter === 'active') {
            $qb->andWhere('ph.isActive = :isActive')->setParameter('isActive', true);
        } elseif ($statusFilter === 'inactive') {
            $qb->andWhere('ph.isActive = :isActive')->setParameter('isActive', false);
        }

        switch ($moduleFilter) {
            case 'catalogue':
                $qb->andWhere('ph.catalogueEnabled = true');
                break;
            case 'calendar':
                $qb->andWhere('ph.calendarEnabled = true');
                break;
            case 'elearning':
                $qb->andWhere('ph.elearningEnabled = true');
                break;
            case 'shop':
                $qb->andWhere('ph.shopEnabled = true');
                break;
            case 'restricted':
                $qb->andWhere('ph.restrictToAssignedFormations = true');
                break;
        }

        return $qb;
    }
}