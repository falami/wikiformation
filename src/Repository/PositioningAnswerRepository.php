<?php

namespace App\Repository;

use App\Entity\{PositioningAnswer, PositioningChapter, PositioningAttempt, PositioningItem};
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use App\Enum\KnowledgeLevel;

/**
 * @extends ServiceEntityRepository<PositioningAnswer>
 */
class PositioningAnswerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PositioningAnswer::class);
    }

    /**
     * @return PositioningAnswer[]
     */
    public function findForAttemptOrdered(PositioningAttempt $attempt): array
    {
        return $this->createQueryBuilder('a')
            ->addSelect('i', 'c', 'q')
            ->join('a.item', 'i')
            ->join('i.chapter', 'c')
            ->join('c.questionnaire', 'q')
            ->andWhere('a.attempt = :attempt')->setParameter('attempt', $attempt)
            ->orderBy('c.position', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->addOrderBy('i.position', 'ASC')
            ->addOrderBy('i.id', 'ASC')
            ->getQuery()
            ->getResult();
    }


    /**
     * Règle:
     * - si tous les items level 1 sont "connus" (knowledge != NONE) => propose 2
     * - si tous level 1 & 2 connus => propose 3
     * - si tous level 1 & 2 & 3 connus => propose 4
     *
     * @param int[] $attemptIds
     * @return array<int,int> [attemptId => level(1..4)]
     */
    public function computeLevelByAttemptIds(array $attemptIds): array
    {
        $attemptIds = array_values(array_unique(array_map('intval', $attemptIds)));
        $attemptIds = array_filter($attemptIds, fn($v) => $v > 0);
        if (!$attemptIds) return [];

        $em = $this->getEntityManager();

        // On part de PositioningItem (total items par niveau), et on left join les réponses du stagiaire
        $rows = $em->createQueryBuilder()
            ->from(PositioningAttempt::class, 't')
            ->select('t.id AS attemptId')
            ->addSelect('i.level AS lvl')
            ->addSelect('COUNT(i.id) AS totalItems')
            ->addSelect('SUM(CASE WHEN a.knowledge IS NOT NULL AND a.knowledge != :none THEN 1 ELSE 0 END) AS knownItems')
            ->join('t.questionnaire', 'q')
            ->join(PositioningChapter::class, 'c', 'WITH', 'c.questionnaire = q')
            ->join(PositioningItem::class, 'i', 'WITH', 'i.chapter = c')
            ->leftJoin(PositioningAnswer::class, 'a', 'WITH', 'a.attempt = t AND a.item = i')
            ->andWhere('t.id IN (:ids)')->setParameter('ids', $attemptIds)
            ->setParameter('none', KnowledgeLevel::NONE)
            ->groupBy('t.id, i.level')
            ->getQuery()
            ->getArrayResult();

        // Indexation: $stats[attemptId][level] = ['total'=>x,'known'=>y]
        $stats = [];
        foreach ($rows as $r) {
            $aid = (int) $r['attemptId'];
            $lvl = (int) $r['lvl'];
            $stats[$aid][$lvl] = [
                'total' => (int) $r['totalItems'],
                'known' => (int) $r['knownItems'],
            ];
        }

        $result = [];
        foreach ($attemptIds as $aid) {
            $byLvl = $stats[$aid] ?? [];

            // helper: niveau validé si total>0 ET known==total
            $isValidated = function (int $lvl) use ($byLvl): bool {
                if (!isset($byLvl[$lvl])) return false;
                return $byLvl[$lvl]['total'] > 0 && $byLvl[$lvl]['known'] >= $byLvl[$lvl]['total'];
            };

            // On cherche le plus haut palier "complet" en partant de 1
            $validated = 0;
            for ($lvl = 1; $lvl <= 4; $lvl++) {
                if ($isValidated($lvl)) $validated = $lvl;
                else break; // dès qu'un niveau n'est pas complet, on s'arrête
            }

            // Proposition = niveau suivant (cap à 4), ou 1 si rien validé
            if ($validated >= 1) {
                $proposed = min(4, $validated + 1);
            } else {
                $proposed = 1;
            }

            $result[$aid] = $proposed;
        }

        return $result;
    }

    //    /**
    //     * @return PositioningAnswer[] Returns an array of PositioningAnswer objects
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

    //    public function findOneBySomeField($value): ?PositioningAnswer
    //    {
    //        return $this->createQueryBuilder('p')
    //            ->andWhere('p.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
