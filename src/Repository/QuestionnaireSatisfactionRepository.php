<?php
// src/Repository/QuestionnaireSatisfactionRepository.php
namespace App\Repository;

use App\Entity\Session;
use App\Entity\QuestionnaireSatisfaction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class QuestionnaireSatisfactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuestionnaireSatisfaction::class);
    }

    public function countSubmittedForSession(Session $session): int
    {
        return (int)$this->createQueryBuilder('q')
            ->select('COUNT(q.id)')
            ->andWhere('q.session = :s')->setParameter('s', $session)
            ->andWhere('q.submittedAt IS NOT NULL')
            ->getQuery()->getSingleScalarResult();
    }

    public function findSubmittedForSession(Session $session): array
    {
        return $this->createQueryBuilder('q')
            ->andWhere('q.session = :s')->setParameter('s', $session)
            ->andWhere('q.submittedAt IS NOT NULL')
            ->orderBy('q.submittedAt', 'DESC')
            ->getQuery()->getResult();
    }
}
