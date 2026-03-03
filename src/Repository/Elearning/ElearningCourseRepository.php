<?php

namespace App\Repository\Elearning;

use App\Entity\Elearning\ElearningCourse;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class ElearningCourseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ElearningCourse::class);
    }

    public function slugExistsForEntite(int $entiteId, string $slug, ?int $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('c')
            ->select('c.id')
            ->andWhere('c.entite = :e')->setParameter('e', $entiteId)
            ->andWhere('c.slug = :s')->setParameter('s', $slug)
            ->setMaxResults(1);

        if ($excludeId) {
            $qb->andWhere('c.id != :id')->setParameter('id', $excludeId);
        }

        return (bool) $qb->getQuery()->getOneOrNullResult();
    }
}
