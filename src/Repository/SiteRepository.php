<?php
// src/Repository/SiteRepository.php

namespace App\Repository;

use App\Entity\Site;
use App\Entity\Entite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class SiteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Site::class);
    }

    /**
     * Retourne des destinations ayant au moins une session,
     * sous forme: [['id'=>1,'nom'=>'Lorient (56)'], ...]
     *
     * @return array<int, array{id:int, nom:string}>
     */
    public function findDistinctDestinationsHavingSessions(): array
    {
        return $this->createQueryBuilder('site')
            ->select(
                'DISTINCT site.id AS id',
                // quotes simples en DQL
                "CONCAT(COALESCE(site.ville, site.nom), COALESCE(CONCAT(' (', site.departement, ')'), '')) AS nom"
            )
            ->innerJoin('site.sessions', 's')
            ->orderBy('nom', 'ASC')   // 👈 on trie sur l’alias présent dans le SELECT
            ->getQuery()
            ->getArrayResult();
    }


    // src/Repository/SiteRepository.php

    public function findDuplicateForEntite(
        Entite $entite,
        ?string $nom,
        ?string $adresse,
        ?string $codePostal,
        ?string $ville
    ): ?Site {
        $norm = static function (?string $s): string {
            $s = mb_strtolower(trim((string) $s));
            $s = preg_replace('/\s+/', ' ', $s);
            // optionnel : enlève ponctuation “faible”
            $s = preg_replace('/[.,;:()\-\/]/', ' ', $s);
            $s = preg_replace('/\s+/', ' ', $s);
            return trim($s);
        };

        $nomN  = $norm($nom);
        $adrN  = $norm($adresse);
        $cpN   = $norm($codePostal);
        $villeN = $norm($ville);

        if ($nomN === '') {
            return null; // on ne peut pas comparer proprement
        }

        // On compare sur NOM + (CP+VILLE) si présents, + ADRESSE si présente.
        // (Tu peux durcir/assouplir selon ta donnée.)
        $qb = $this->createQueryBuilder('s')
            ->andWhere('s.entite = :entite')
            ->setParameter('entite', $entite)
            ->setMaxResults(1);

        // Comparaison “LIKE” sur champs normalisés côté PHP -> on va faire simple côté SQL :
        // exact sur cp/ville si fournis, sinon ignore.
        // nom : exact (après trim/lower) -> on fait LOWER() et TRIM() en DQL.
        $qb->andWhere('LOWER(TRIM(s.nom)) = :nom')
            ->setParameter('nom', $nomN);

        if ($cpN !== '') {
            $qb->andWhere('TRIM(s.codePostal) = :cp')->setParameter('cp', $codePostal);
        }
        if ($villeN !== '') {
            $qb->andWhere('LOWER(TRIM(s.ville)) = :ville')->setParameter('ville', $villeN);
        }
        if ($adrN !== '') {
            $qb->andWhere('LOWER(TRIM(s.adresse)) = :adresse')->setParameter('adresse', $adrN);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }
}
