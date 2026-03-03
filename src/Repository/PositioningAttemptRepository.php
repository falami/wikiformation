<?php
// src/Repository/PositioningAttemptRepository.php

namespace App\Repository;

use App\Entity\Entite;
use App\Entity\Formateur;
use App\Entity\PositioningAttempt;
use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class PositioningAttemptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PositioningAttempt::class);
    }

    /** @return PositioningAttempt[] */
    public function findForStagiaire(Entite $entite, Utilisateur $user): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.session', 's')
            ->join('s.entite', 'e')
            ->andWhere('e = :entite')->setParameter('entite', $entite)
            ->andWhere('a.stagiaire = :u')->setParameter('u', $user)
            ->orderBy('a.startedAt', 'DESC')
            ->getQuery()->getResult();
    }

    /** @return PositioningAttempt[] */
    public function findForFormateur(Entite $entite, Formateur $formateur): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.session', 's')
            ->join('s.entite', 'e')
            ->andWhere('e = :entite')->setParameter('entite', $entite)
            ->andWhere('a.assignedFormateur = :f')->setParameter('f', $formateur)
            ->orderBy('a.submittedAt', 'DESC')
            ->addOrderBy('a.startedAt', 'DESC')
            ->getQuery()->getResult();
    }

    /** @return PositioningAttempt[] */
    public function findForAdmin(Entite $entite): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.session', 's')
            ->join('s.entite', 'e')
            ->andWhere('e = :entite')->setParameter('entite', $entite)
            ->orderBy('a.startedAt', 'DESC')
            ->getQuery()->getResult();
    }
    public function findSubmittedAssignedToEvaluator(Entite $entite, Utilisateur $evaluator): array
    {
        return $this->createQueryBuilder('t')
            ->join('t.questionnaire', 'q')
            ->join('t.assignment', 'a')
            ->andWhere('q.entite = :e')->setParameter('e', $entite)
            ->andWhere('t.submittedAt IS NOT NULL')
            ->andWhere('a.evaluator = :ev')->setParameter('ev', $evaluator)
            ->addOrderBy('t.submittedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }


    
}
