<?php

namespace App\Repository;

use App\Entity\PositioningChapter;
use App\Entity\PositioningQuestionnaire;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PositioningChapter>
 */
class PositioningChapterRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PositioningChapter::class);
    }

    // src/Repository/PositioningChapterRepository.php

    public function getNextPositionForQuestionnaire(PositioningQuestionnaire $questionnaire): int
    {
        $max = $this->createQueryBuilder('c')
            ->select('MAX(c.position)')
            ->andWhere('c.questionnaire = :q')
            ->setParameter('q', $questionnaire)
            ->getQuery()
            ->getSingleScalarResult();

        return ((int)($max ?? 0)) + 1;
    }


    //    /**
    //     * @return PositioningChapter[] Returns an array of PositioningChapter objects
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

    //    public function findOneBySomeField($value): ?PositioningChapter
    //    {
    //        return $this->createQueryBuilder('p')
    //            ->andWhere('p.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
