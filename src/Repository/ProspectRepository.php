<?php

namespace App\Repository;

use App\Entity\Entite;
use App\Enum\ProspectSource;
use App\Entity\Prospect;
use App\Enum\ProspectStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class ProspectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Prospect::class);
    }

    public function kpis(Entite $entite, ?string $status = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->select('COUNT(p.id) as total')
            ->addSelect('SUM(CASE WHEN p.nextActionAt IS NOT NULL AND p.nextActionAt <= :now THEN 1 ELSE 0 END) as due')
            ->addSelect('SUM(CASE WHEN p.status IN (:hot) THEN 1 ELSE 0 END) as hot')
            ->addSelect('SUM(CASE WHEN p.status = :won THEN 1 ELSE 0 END) as won')
            ->addSelect('SUM(COALESCE(p.estimatedValueCents,0)) as pipeline')
            ->andWhere('p.entite = :e')->setParameter('e', $entite)
            ->andWhere('p.isActive = true')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('hot', [ProspectStatus::QUALIFIED, ProspectStatus::PROPOSAL_SENT, ProspectStatus::NEGOTIATION])
            ->setParameter('won', ProspectStatus::WON);

        if ($status && $status !== 'all') {
            $qb->andWhere('p.status = :st')->setParameter('st', ProspectStatus::from($status));
        }

        $r = $qb->getQuery()->getSingleResult();

        return [
            'total' => (int)($r['total'] ?? 0),
            'due' => (int)($r['due'] ?? 0),
            'hot' => (int)($r['hot'] ?? 0),
            'won' => (int)($r['won'] ?? 0),
            'pipelineCents' => (int)($r['pipeline'] ?? 0),
        ];
    }

    /**
     * Renvoie: [rows, recordsTotal, recordsFiltered]
     */
    public function datatable(Entite $entite, array $dt, array $filters): array
    {
        $start = max(0, (int)($dt['start'] ?? 0));
        $len   = min(500, max(10, (int)($dt['length'] ?? 25)));
        $draw  = (int)($dt['draw'] ?? 1);

        $searchValue = trim((string)($dt['search']['value'] ?? ''));

        $base = $this->createQueryBuilder('p')
            ->andWhere('p.entite = :e')->setParameter('e', $entite);

        // total (sans filtres search)
        $recordsTotal = (int)(clone $base)->select('COUNT(p.id)')->getQuery()->getSingleScalarResult();

        // filtres
        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $base->andWhere('p.status = :st')->setParameter('st', ProspectStatus::from($filters['status']));
        }
        if (!empty($filters['source']) && $filters['source'] !== 'all') {
            $base->andWhere('p.source = :so')->setParameter('so', ProspectSource::from($filters['source']));
        }
        if (!empty($filters['active']) && in_array($filters['active'], ['0', '1'], true)) {
            $base->andWhere('p.isActive = :a')->setParameter('a', $filters['active'] === '1');
        }
        if (!empty($filters['next']) && $filters['next'] === 'due') {
            $base->andWhere('p.nextActionAt IS NOT NULL AND p.nextActionAt <= :now')->setParameter('now', new \DateTimeImmutable());
        }

        if ($searchValue !== '') {
            $base->andWhere('LOWER(p.nom) LIKE :q OR LOWER(p.prenom) LIKE :q OR LOWER(p.email) LIKE :q')
                ->setParameter('q', '%' . mb_strtolower($searchValue) . '%');
        }

        $filteredQb = (clone $base)->select('COUNT(p.id)');
        $recordsFiltered = (int)$filteredQb->getQuery()->getSingleScalarResult();

        // order DT
        $orderCol = (int)($dt['order'][0]['column'] ?? 0);
        $orderDir = strtolower((string)($dt['order'][0]['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $map = [
            0 => 'p.id',
            1 => 'p.updatedAt',
            2 => 'p.nom',
            3 => 'p.status',
            4 => 'p.score',
            5 => 'p.nextActionAt',
        ];

        $base->orderBy($map[$orderCol] ?? 'p.id', $orderDir)
            ->setFirstResult($start)
            ->setMaxResults($len);

        $rows = $base->getQuery()->getResult();

        return [$rows, $recordsTotal, $recordsFiltered, $draw];
    }
}
