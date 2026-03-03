<?php

namespace App\Repository;

use App\Entity\Emargement;
use App\Enum\DemiJournee;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use App\Entity\Session;
use App\Entity\Utilisateur;

/**
 * @extends ServiceEntityRepository<Emargement>
 */
class EmargementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Emargement::class);
    }


    public function findForUserAndDay(Session $session, Utilisateur $user, \DateTimeImmutable $day): ?Emargement
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.session = :s')->setParameter('s', $session)
            ->andWhere('e.utilisateur = :u')->setParameter('u', $user)
            ->andWhere('e.dateJour = :d')->setParameter('d', $day->setTime(0, 0))
            ->getQuery()->getOneOrNullResult();
    }

    public function countSignedByDay(Session $session, \DateTimeImmutable $day): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.session = :s')->setParameter('s', $session)
            ->andWhere('e.dateJour = :d')->setParameter('d', $day->setTime(0, 0))
            ->getQuery()->getSingleScalarResult();
    }

    public function findForUserDayAndPeriode(Session $s, Utilisateur $u, \DateTimeImmutable $day, DemiJournee $periode): ?Emargement
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.session = :s')->setParameter('s', $s)
            ->andWhere('e.utilisateur = :u')->setParameter('u', $u)
            ->andWhere('e.dateJour = :d')->setParameter('d', $day->setTime(0, 0))
            ->andWhere('e.periode = :p')->setParameter('p', $periode)
            ->getQuery()->getOneOrNullResult();
    }


    public function signedPeriodsForUser(Session $session, Utilisateur $user): array
    {
        $rows = $this->createQueryBuilder('e')
            ->select('e.dateJour AS d, e.periode AS p')
            ->andWhere('e.session = :s')->setParameter('s', $session)
            ->andWhere('e.utilisateur = :u')->setParameter('u', $user)
            ->getQuery()
            ->getArrayResult();

        $map = [];

        foreach ($rows as $r) {
            $d = $r['d'] ?? null;
            if (!$d instanceof \DateTimeInterface) {
                continue; // sécurité
            }

            $key = $d->format('Y-m-d');

            // p peut être une string (selon hydration) ou un enum DemiJournee
            $pRaw = $r['p'] ?? null;
            if ($pRaw instanceof \BackedEnum) {
                $p = strtoupper((string) $pRaw->value);
            } else {
                $p = strtoupper((string) $pRaw);
            }

            // on ne garde que AM/PM (ou adapte si ton enum a d'autres valeurs)
            if ($p !== 'AM' && $p !== 'PM') {
                continue;
            }

            $map[$key][$p] = true;
        }

        return $map;
    }


    //    /**
    //     * @return Emargement[] Returns an array of Emargement objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('e')
    //            ->andWhere('e.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('e.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Emargement
    //    {
    //        return $this->createQueryBuilder('e')
    //            ->andWhere('e.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
