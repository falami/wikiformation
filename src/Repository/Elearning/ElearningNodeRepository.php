<?php

namespace App\Repository\Elearning;

use App\Entity\Elearning\ElearningNode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ElearningNode>
 */
class ElearningNodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ElearningNode::class);
    }

    // src/Repository/CourseContentNodeRepository.php

    public function slugExistsForCourse(int $courseId, string $slug, ?int $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->andWhere('n.course = :courseId')

            ->andWhere('n.isPublished = true')
            ->andWhere('n.slug = :slug')
            ->setParameter('courseId', $courseId)
            ->setParameter('slug', $slug);

        if ($excludeId) {
            $qb->andWhere('n.id <> :excludeId')->setParameter('excludeId', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }



    //    /**
    //     * @return ElearningNode[] Returns an array of ElearningNode objects
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

    //    public function findOneBySomeField($value): ?ElearningNode
    //    {
    //        return $this->createQueryBuilder('e')
    //            ->andWhere('e.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
