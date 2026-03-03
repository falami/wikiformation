<?php

namespace App\Repository;

use App\Entity\Entite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Entite>
 */
class EntiteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Entite::class);
    }

    public function searchAll(?string $q = null, int $limit = 200): array
    {
        $qb = $this->createQueryBuilder('e')
            ->orderBy('e.nom', 'ASC')
            ->setMaxResults($limit);

        if ($q && trim($q) !== '') {
            $q = mb_strtolower(trim($q));
            $qb->andWhere('LOWER(e.nom) LIKE :q OR LOWER(e.email) LIKE :q OR LOWER(e.ville) LIKE :q OR LOWER(e.siret) LIKE :q')
                ->setParameter('q', '%' . $q . '%');
        }

        return $qb->getQuery()->getResult();
    }

}
