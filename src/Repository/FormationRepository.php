<?php

namespace App\Repository;

use App\Entity\Formation;
use App\Entity\Categorie;
use App\Entity\Entite;
use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Formation>
 */
class FormationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Formation::class);
    }


    public function findOneForPublicShowBySlug(string $slug): ?Formation
    {
        // On charge tout ce qui est utile à l’affichage public
        return $this->createQueryBuilder('f')
            ->leftJoin('f.photos', 'p')->addSelect('p')
            ->leftJoin('f.formateur', 'sk')->addSelect('sk')
            ->leftJoin('sk.utilisateur', 'u')->addSelect('u')
            ->leftJoin('f.engin', 'b')->addSelect('b')
            ->leftJoin('f.sessions', 's')->addSelect('s')
            ->leftJoin('s.site', 'site')->addSelect('site')
            ->leftJoin('s.reservations', 'r')->addSelect('r')
            ->andWhere('f.slug = :slug')->setParameter('slug', $slug)
            ->getQuery()->getOneOrNullResult();
    }

    public function findPublicCatalogue(): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.isPublic = 1')
            ->orderBy('f.titre', 'ASC')
            ->getQuery()
            ->getResult();
    }



    public function slugExistsForEntite(int $entiteId, string $slug, ?int $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->andWhere('f.entite = :e')->setParameter('e', $entiteId)
            ->andWhere('f.slug = :s')->setParameter('s', $slug);

        if ($excludeId) {
            $qb->andWhere('f.id != :id')->setParameter('id', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }





    /**
     * Formations payées suivies par l'utilisateur (sur une entité donnée).
     * Retourne un tableau de Formation.
     */
    public function findPaidForStagiaire(Utilisateur $user, Entite $entite): array
    {
        // Hypothèse : Inscription -> session -> formation
        //             Facture -> inscription
        //             FactureStatus::PAID
        return $this->createQueryBuilder('f')
            ->innerJoin('f.sessions', 's')              // adapte si ta relation est différente
            ->innerJoin('s.inscriptions', 'i')
            ->innerJoin('i.factures', 'fa')
            ->andWhere('i.stagiaire = :u')
            ->andWhere('f.entite = :e')                 // si Formation a entite
            ->setParameter('u', $user)
            ->setParameter('e', $entite)
            ->addOrderBy('f.titre', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Formations payées pour une catégorie donnée (stagiaire).
     */
    public function findPaidForStagiaireByCategorie(Utilisateur $user, Entite $entite, Categorie $categorie): array
    {
        return $this->createQueryBuilder('f')
            ->innerJoin('f.sessions', 's')
            ->innerJoin('s.inscriptions', 'i')
            ->innerJoin('i.factures', 'fa')
            ->andWhere('i.stagiaire = :u')
            ->andWhere('f.entite = :e')
            ->andWhere('f.categorie = :c')
            ->setParameter('u', $user)
            ->setParameter('e', $entite)
            ->setParameter('c', $categorie)
            ->addOrderBy('f.titre', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Vérifie l'accès à une formation (payée au moins une fois).
     */
    public function isPaidForStagiaire(Utilisateur $user, Entite $entite, Formation $formation): bool
    {
        $qb = $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->innerJoin('f.sessions', 's')
            ->innerJoin('s.inscriptions', 'i')
            ->innerJoin('i.factures', 'fa')
            ->andWhere('f = :f')
            ->andWhere('f.entite = :e')
            ->andWhere('i.stagiaire = :u')
            ->setParameter('f', $formation)
            ->setParameter('e', $entite)
            ->setParameter('u', $user);

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }


    public function findOnePublicBySlug(string $slug): ?\App\Entity\Formation
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.slug = :slug')
            ->andWhere('f.isPublic = 1')
            ->setParameter('slug', $slug)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }



    //    /**
    //     * @return Formation[] Returns an array of Formation objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('f')
    //            ->andWhere('f.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('f.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Formation
    //    {
    //        return $this->createQueryBuilder('f')
    //            ->andWhere('f.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
