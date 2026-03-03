<?php

namespace App\Repository;

use App\Entity\PositioningItem;
use App\Entity\PositioningChapter;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PositioningItem>
 */
class PositioningItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PositioningItem::class);
    }


    // src/Repository/PositioningItemRepository.php

    public function getNextPositionForChapter(PositioningChapter $chapter): int
    {
        $max = $this->createQueryBuilder('i')
            ->select('MAX(i.position)')
            ->andWhere('i.chapter = :c')
            ->setParameter('c', $chapter)
            ->getQuery()
            ->getSingleScalarResult();

        return ((int)($max ?? 0)) + 1;
    }


    //    /**
    //     * @return PositioningItem[] Returns an array of PositioningItem objects
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

    //    public function findOneBySomeField($value): ?PositioningItem
    //    {
    //        return $this->createQueryBuilder('p')
    //            ->andWhere('p.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
