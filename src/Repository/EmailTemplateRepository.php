<?php

namespace App\Repository;

use App\Entity\EmailTemplate;
use App\Entity\Entite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class EmailTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailTemplate::class);
    }

    /** @return EmailTemplate[] */
    public function findActiveForProspects(Entite $entite): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.entite = :e')->setParameter('e', $entite)
            ->andWhere('t.isActive = true')
            ->andWhere('t.category = :c')->setParameter('c', 'prospect')
            ->orderBy('t.name', 'ASC')
            ->getQuery()->getResult();
    }
}
