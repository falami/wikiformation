<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SatisfactionAttempt;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class SatisfactionAttemptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SatisfactionAttempt::class);
    }

    public function statsForFormateur(int $formateurId, int $entiteId): array
    {
        return $this->createQueryBuilder('t')
            ->select('COUNT(t.id) as nb, AVG(t.noteFormateur) as avgNote')
            ->join('t.assignment', 'a')
            ->join('a.session', 's')
            ->andWhere('s.formateur = :f')->setParameter('f', $formateurId)
            ->andWhere('s.entite = :e')->setParameter('e', $entiteId)
            ->andWhere('t.submittedAt IS NOT NULL')
            ->andWhere('t.noteFormateur IS NOT NULL')
            ->getQuery()
            ->getSingleResult();
    }


    public function statsForFormation(int $formationId, int $entiteId): array
    {
        return $this->createQueryBuilder('t')
            ->select('COUNT(t.id) as nb, AVG(t.noteContenu) as avgNote')
            ->join('t.assignment', 'a')
            ->join('a.session', 's')
            ->andWhere('s.formation = :fo')->setParameter('fo', $formationId)
            ->andWhere('s.entite = :e')->setParameter('e', $entiteId)
            ->andWhere('t.submittedAt IS NOT NULL')
            ->andWhere('t.noteContenu IS NOT NULL')
            ->getQuery()
            ->getSingleResult();
    }

    public function statsForSite(int $siteId, int $entiteId): array
    {
        return $this->createQueryBuilder('t')
            ->select('COUNT(t.id) as nb, AVG(t.noteSite) as avgNote')
            ->join('t.assignment', 'a')
            ->join('a.session', 's')
            ->andWhere('s.site = :si')->setParameter('si', $siteId)
            ->andWhere('s.entite = :e')->setParameter('e', $entiteId)
            ->andWhere('t.submittedAt IS NOT NULL')
            ->andWhere('t.noteSite IS NOT NULL')
            ->getQuery()
            ->getSingleResult();
    }

    // src/Repository/SatisfactionAttemptRepository.php

    public function npsForEntite(int $entiteId): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('t.recommendationScore AS score, COUNT(t.id) AS nb')
            ->join('t.assignment', 'a')
            ->join('a.session', 's')
            ->andWhere('s.entite = :e')->setParameter('e', $entiteId)
            ->andWhere('t.submittedAt IS NOT NULL')
            ->andWhere('t.recommendationScore IS NOT NULL')
            ->groupBy('t.recommendationScore')
            ->getQuery()->getArrayResult();

        $total = 0;
        $prom = 0;
        $pass = 0;
        $detr = 0;

        foreach ($rows as $r) {
            $score = (int) $r['score'];
            $nb    = (int) $r['nb'];
            $total += $nb;

            if ($score >= 9) $prom += $nb;
            elseif ($score >= 7) $pass += $nb;
            else $detr += $nb;
        }

        $nps = $total > 0 ? (int) round((($prom / $total) - ($detr / $total)) * 100) : null;

        return [
            'total' => $total,
            'promoters' => $prom,
            'passives' => $pass,
            'detractors' => $detr,
            'nps' => $nps, // -100..+100
        ];
    }


    public function npsForFormateur(int $formateurId, int $entiteId): array
    {
        $row = $this->createQueryBuilder('t')
            ->select('COUNT(t.id) as nb')
            ->addSelect('SUM(CASE WHEN t.recommendationScore >= 9 THEN 1 ELSE 0 END) as promoters')
            ->addSelect('SUM(CASE WHEN t.recommendationScore <= 6 THEN 1 ELSE 0 END) as detractors')
            ->join('t.assignment', 'a')
            ->join('a.session', 's')
            ->andWhere('s.formateur = :f')->setParameter('f', $formateurId)
            ->andWhere('s.entite = :e')->setParameter('e', $entiteId)
            ->andWhere('t.submittedAt IS NOT NULL')
            ->andWhere('t.recommendationScore IS NOT NULL')
            ->getQuery()->getSingleResult();

        $nb = (int)($row['nb'] ?? 0);
        $promoters = (int)($row['promoters'] ?? 0);
        $detractors = (int)($row['detractors'] ?? 0);

        $nps = $nb > 0 ? (int) round((($promoters / $nb) * 100) - (($detractors / $nb) * 100)) : null;

        return [
            'nb' => $nb,
            'promoters' => $promoters,
            'detractors' => $detractors,
            'nps' => $nps, // -100..+100
        ];
    }
}
