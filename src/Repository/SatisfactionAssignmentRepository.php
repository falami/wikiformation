<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SatisfactionAssignment;
use App\Entity\Entite;
use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class SatisfactionAssignmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SatisfactionAssignment::class);
    }

    /** @return SatisfactionAssignment[] */
    public function findForUserInEntite(Utilisateur $user, Entite $entite): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.session', 's')
            ->andWhere('a.stagiaire = :u')->setParameter('u', $user)
            ->andWhere('s.entite = :e')->setParameter('e', $entite)
            ->orderBy('a.id', 'DESC')
            ->getQuery()->getResult();
    }
}
