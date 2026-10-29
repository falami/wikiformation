<?php

declare(strict_types=1);

namespace App\Service\Entreprise;

use App\Entity\{Entite, Entreprise, HabilitationDossier, HabilitationRevision};
use Doctrine\ORM\{EntityManagerInterface, QueryBuilder};

/** Read access in the company portal always follows the selected company. */
final class EntrepriseHabilitations
{
    public function __construct(private EntityManagerInterface $em) {}

    public function available(HabilitationDossier $d, Entite $tenant, Entreprise $company): bool
    {
        $i = $d->getInscription();
        return $company->getEntite()?->getId() === $tenant->getId()
            && $d->getEntite()?->getId() === $tenant->getId()
            && $i?->getEntite()?->getId() === $tenant->getId()
            && $i?->getSession()?->getEntite()?->getId() === $tenant->getId()
            && $d->getEntreprise()?->getId() === $company->getId()
            && $i?->getEntreprise()?->getId() === $company->getId()
            && $i?->getStagiaire() !== null && $d->getActive() && !$d->getDeleted()
            && $d->getAvisToken() && in_array($d->getState(), ['awaiting_company', 'published'], true);
    }

    private function matchesIdentity(HabilitationRevision $revision): bool
    {
        $d = $revision->getDossier();
        return $this->matchesSnapshotIdentity($revision->getSnapshot(), $d->getEntreprise()?->getId(), $d->getInscription()?->getStagiaire()?->getId());
    }

    private function matchesSnapshotIdentity(array $snapshot, ?int $companyId, ?int $traineeId): bool
    {
        $identity = $snapshot['identity'] ?? null;
        return $companyId !== null && $traineeId !== null && is_array($identity)
            && is_array($snapshot['data'] ?? null) && is_array($snapshot['schema'] ?? null)
            && is_string($snapshot['signature'] ?? null) && $snapshot['signature'] !== ''
            && ($identity['companyId'] ?? null) === $companyId && ($identity['traineeId'] ?? null) === $traineeId;
    }

    public function current(HabilitationDossier $d, string $kind): ?HabilitationRevision
    {
        $token = match ($kind) { 'avis' => $d->getAvisToken(), 'titre' => $d->getTitreToken(), default => null };
        if (!$token || ($kind === 'titre' && $d->getState() !== 'published')) return null;
        $r = $this->em->getRepository(HabilitationRevision::class)->findOneBy(['dossier'=>$d, 'token'=>$token, 'kind'=>$kind]);
        if (!$r || !$this->matchesIdentity($r)) return null;
        if ($kind === 'titre' && ($r->getSnapshot()['avisToken'] ?? null) !== $d->getAvisToken()) return null;
        return $r;
    }

    private function opinionsQuery(Entite $tenant, Entreprise $company): QueryBuilder
    {
        return $this->em->createQueryBuilder()
            ->from(HabilitationRevision::class, 'r')->join('r.dossier', 'd')
            ->join('d.inscription', 'i')->join('i.session', 's')->join('i.stagiaire', 'u')->leftJoin('s.formation', 'f')
            ->where('d.entite = :tenant AND i.entite = :tenant AND s.entite = :tenant')
            ->andWhere('d.entreprise = :company AND i.entreprise = :company')
            ->andWhere('d.active = true AND d.deleted = false')
            ->andWhere('d.state IN (:states) AND r.token = d.avisToken AND r.kind = :kind')
            ->setParameter('tenant', $tenant)->setParameter('company', $company)
            ->setParameter('states', ['awaiting_company', 'published'])->setParameter('kind', 'avis');
    }

    /**
     * Snapshot identity checks stay portable across database engines. Scan scalar
     * batches without filling Doctrine's identity map with every signed document;
     * only the requested page and its current titles are hydrated as entities.
     *
     * @return array{rows:list<HabilitationRevision>, total:int, filtered:int, titles:array<int,HabilitationRevision>}
     */
    public function opinionPage(Entite $tenant, Entreprise $company, string $search = '', int $start = 0, int $length = 10, int $column = 3, string $direction = 'DESC'): array
    {
        $empty = ['rows'=>[], 'total'=>0, 'filtered'=>0, 'titles'=>[]];
        if ($company->getEntite()?->getId() !== $tenant->getId()) return $empty;
        $start = max(0, $start); $length = max(1, min(100, $length));
        $direction = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';
        $sort = [0=>'person', 1=>'formationLabel', 2=>'sessionCode', 3=>'validationAt'][$column] ?? 'validationAt';
        $terms = preg_split('/\s+/u', mb_strtolower(mb_substr(trim($search), 0, 200)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $base = $this->opinionsQuery($tenant, $company);
        $scan = (clone $base)->select('r.id AS revisionId', 'r.snapshot AS snapshot', 'u.id AS traineeId', 'IDENTITY(d.entreprise) AS companyId',
                "TRIM(CONCAT(CONCAT(COALESCE(u.prenom, ''), ' '), COALESCE(u.nom, ''))) AS person",
                "CASE WHEN s.typeFinancement = :external THEN COALESCE(NULLIF(s.formationIntituleLibre, ''), '—') ELSE COALESCE(NULLIF(f.titre, ''), '—') END AS formationLabel",
                "COALESCE(s.code, '') AS sessionCode", 'r.createdAt AS validationAt')
            ->setParameter('external', \App\Enum\TypeFinancement::OUI->value)
            ->orderBy($sort, $direction)->addOrderBy('r.id', $direction);
        $total = 0; $filtered = 0; $ids = []; $offset = 0; $batchSize = 100;
        do {
            $batch = (clone $scan)->setFirstResult($offset)->setMaxResults($batchSize)->getQuery()->getArrayResult();
            foreach ($batch as $row) {
                $snapshot = $row['snapshot'];
                if (!$this->matchesSnapshotIdentity($snapshot, $row['companyId'], $row['traineeId'])) continue;
                ++$total;
                $signer = is_string($snapshot['signer'] ?? null) ? $snapshot['signer'] : '';
                $haystack = mb_strtolower($row['person'].' '.$row['formationLabel'].' '.$row['sessionCode'].' '.$signer);
                foreach ($terms as $term) if (!str_contains($haystack, $term)) continue 2;
                if ($filtered >= $start && count($ids) < $length) $ids[] = $row['revisionId'];
                ++$filtered;
            }
            $count = count($batch); $offset += $count;
            unset($batch);
        } while ($count === $batchSize);
        if (!$ids) return ['rows'=>[], 'total'=>$total, 'filtered'=>$filtered, 'titles'=>[]];

        $page = (clone $base)->select('r', 'd', 'i', 's', 'u', 'f')
            ->andWhere('r.id IN (:ids)')->setParameter('ids', $ids)->getQuery()->getResult();
        $byId = [];
        foreach ($page as $revision) {
            if ($this->available($revision->getDossier(), $tenant, $company) && $this->matchesIdentity($revision)) $byId[$revision->getId()] = $revision;
        }
        $rows = [];
        foreach ($ids as $id) if (isset($byId[$id])) $rows[] = $byId[$id];
        return ['rows'=>$rows, 'total'=>$total, 'filtered'=>$filtered, 'titles'=>$this->pageTitles($rows)];
    }

    /** @param list<HabilitationRevision> $opinions @return array<int,HabilitationRevision> */
    private function pageTitles(array $opinions): array
    {
        $ids = [];
        foreach ($opinions as $opinion) {
            $d = $opinion->getDossier();
            if ($d->getState() === 'published' && $d->getTitreToken()) $ids[] = $d->getId();
        }
        if (!$ids) return [];
        $revisions = $this->em->createQueryBuilder()->select('r')->from(HabilitationRevision::class, 'r')->join('r.dossier', 'd')
            ->where('d.id IN (:ids) AND r.token = d.titreToken AND r.kind = :kind AND d.state = :state')
            ->setParameter('ids', $ids)->setParameter('kind', 'titre')->setParameter('state', 'published')->getQuery()->getResult();
        $titles = [];
        foreach ($revisions as $revision) {
            $d = $revision->getDossier();
            if ($this->matchesIdentity($revision) && ($revision->getSnapshot()['avisToken'] ?? null) === $d->getAvisToken()) $titles[$d->getId()] = $revision;
        }
        return $titles;
    }
}
