<?php

namespace App\Repository;

use App\Entity\Paiement;
use App\Entity\Entite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Paiement>
 */
class PaiementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Paiement::class);
    }


    public function sumPaidForFacture(int $factureId): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COALESCE(SUM(p.montantCents), 0)')
            ->andWhere('p.facture = :fid')
            ->setParameter('fid', $factureId)
            ->getQuery()
            ->getSingleScalarResult();
    }


    public function sumEncaisseTtcCentsForEntite(Entite $entite, \DateTimeImmutable $from, \DateTimeImmutable $to, string $devise = 'EUR'): int
    {
        $qb = $this->createQueryBuilder('p')
            ->select('COALESCE(SUM(p.montantCents),0)')
            ->andWhere('p.entite = :e')->setParameter('e', $entite)
            ->andWhere('p.devise = :d')->setParameter('d', $devise)
            ->andWhere('p.datePaiement >= :from')->setParameter('from', $from)
            ->andWhere('p.datePaiement < :to')->setParameter('to', $to);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }


    //    /**
    //     * @return Paiement[] Returns an array of Paiement objects
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

    //    public function findOneBySomeField($value): ?Paiement
    //    {
    //        return $this->createQueryBuilder('p')
    //            ->andWhere('p.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
