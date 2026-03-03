<?php

namespace App\Repository;

use App\Entity\Entite;
use App\Entity\ProspectInteraction;
use App\Entity\UtilisateurEntite;
use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProspectInteraction>
 */
class ProspectInteractionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProspectInteraction::class);
    }


    /**
     * Récupère les interactions visibles dans une entité pour un utilisateur :
     * - soit rattachées à un prospect de l'entité (prospect.linkedUser)
     * - soit rattachées directement à l'utilisateur (sans prospect)
     */
    public function findForUtilisateurInEntite(Entite $entite, Utilisateur $utilisateur, int $limit = 500): array
    {
        $qb = $this->createQueryBuilder('i')
            ->leftJoin('i.prospect', 'p')
            ->leftJoin('i.utilisateur', 'uDirect') // interactions sans prospect
            ->leftJoin('p.entite', 'pe')
            // sécurise l'appartenance à l'entité pour le cas "uDirect"
            ->leftJoin(UtilisateurEntite::class, 'ue', 'WITH', 'ue.utilisateur = uDirect AND ue.entite = :entite')
            ->addSelect('p')
            ->setParameter('entite', $entite)
            ->setParameter('user', $utilisateur)
            ->andWhere('
                (p.id IS NOT NULL AND pe = :entite AND p.linkedUser = :user)
                OR
                (uDirect = :user AND ue.id IS NOT NULL)
            ')
            ->orderBy('i.occurredAt', 'DESC')
            ->setMaxResults($limit);

        return $qb->getQuery()->getResult();
    }

    //    /**
    //     * @return ProspectInteraction[] Returns an array of ProspectInteraction objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('p')
    //            ->andWhere('p.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('p.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?ProspectInteraction
    //    {
    //        return $this->createQueryBuilder('p')
    //            ->andWhere('p.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
