<?php
// src/Repository/SatisfactionTemplateRepository.php
namespace App\Repository;

use App\Entity\Entite;
use App\Entity\Formation;
use App\Entity\SatisfactionTemplate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class SatisfactionTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SatisfactionTemplate::class);
    }

    public function findActiveForFormationOrEntite(Entite $entite, ?Formation $formation): ?SatisfactionTemplate
    {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.entite = :e')
            ->andWhere('t.isActive = 1')
            ->setParameter('e', $entite)
            ->setMaxResults(1);

        if ($formation) {
            // templates ciblant la formation OU génériques (aucune formation sélectionnée)
            $qb->leftJoin('t.formations', 'f')
                ->andWhere('(f = :formation OR f.id IS NULL)')
                ->setParameter('formation', $formation)
                ->orderBy('CASE WHEN f = :formation THEN 0 ELSE 1 END', 'ASC')
                ->addOrderBy('t.id', 'DESC');
        } else {
            // si pas de formation : uniquement les templates génériques
            $qb->leftJoin('t.formations', 'f')
                ->andWhere('f.id IS NULL')
                ->orderBy('t.id', 'DESC');
        }

        return $qb->getQuery()->getOneOrNullResult();
    }
}
