<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\Entity\{Entite, UtilisateurEntite, Formateur, Entreprise, Prospect};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** The same snapshot is used by the dashboard and by the flush-time quota check. */
final class QuotaUsage
{
    public const BILLABLE_ROLES = [UtilisateurEntite::TENANT_ADMIN, UtilisateurEntite::TENANT_DIRIGEANT];
    public const CLASSES = [UtilisateurEntite::class, Formateur::class, Entreprise::class, Prospect::class];

    public function __construct(private EntityManagerInterface $em) {}

    public function counts(Entite $entite, bool $pending = false, bool $lock = false): array
    {
        $members = $this->rows(UtilisateurEntite::class, $entite, $pending, $lock);
        $users = []; $trainers = []; $roles = []; $inactive = [];
        foreach ($members as $row) {
            if ($row['status'] !== UtilisateurEntite::STATUS_ACTIVE) { $inactive[$row['user']] = true; continue; }
            foreach ($row['roles'] as $role) $roles[$role][$row['user']] = true;
            if (array_intersect(self::BILLABLE_ROLES, $row['roles'])) $users[$row['user']] = true;
            if (in_array(UtilisateurEntite::TENANT_FORMATEUR, $row['roles'], true)) $trainers[$row['user']] = true;
        }
        foreach ($this->rows(Formateur::class, $entite, $pending, $lock) as $id => $row) {
            if (!isset($inactive[$row['user']])) $trainers[$row['user'] ?: 'profile:'.$id] = true;
        }
        return [
            'max_utilisateurs' => count($users),
            'max_formateurs' => count($trainers),
            'max_commerciaux' => count($roles[UtilisateurEntite::TENANT_COMMERCIAL] ?? []),
            'max_entreprises' => count($this->rows(Entreprise::class, $entite, $pending, $lock)),
            'max_prospects' => count($this->rows(Prospect::class, $entite, $pending, $lock)),
            'roles' => array_map('count', $roles),
        ];
    }

    private function rows(string $class, Entite $entite, bool $pending, bool $lock): array
    {
        $selection = 'o.id, IDENTITY(o.entite) AS entiteId';
        if ($class === UtilisateurEntite::class) $selection .= ', o.status, o.roles, IDENTITY(o.utilisateur) AS user';
        if ($class === Formateur::class) $selection .= ', IDENTITY(o.utilisateur) AS user';
        $data = [];
        if ($entite->getId() !== null) {
            $query = $this->em->createQueryBuilder()->select($selection)->from($class, 'o')
                ->where('o.entite = :entite')->setParameter('entite', $entite)->getQuery();
            // A locking read sees the latest committed state even when a caller
            // already opened a REPEATABLE READ transaction before this flush.
            if ($lock && !$this->em->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
                $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
            }
            $data = $query->getArrayResult();
        }
        $rows = [];
        foreach ($data as $row) { if (isset($row['user'])) $row['user'] = 'id:'.$row['user']; $rows[(string)$row['id']] = $row; }
        if (!$pending) return $rows;
        $uow = $this->em->getUnitOfWork();
        foreach ([...$uow->getScheduledEntityInsertions(), ...$uow->getScheduledEntityUpdates(), ...$uow->getScheduledEntityDeletions()] as $object) {
            if (!$object instanceof $class) continue;
            $id = $object->getId() !== null ? (string)$object->getId() : 'new:'.spl_object_id($object);
            $existing = $rows[$id] ?? null;
            unset($rows[$id]);
            $changes = $uow->getEntityChangeSet($object);
            $insert = $uow->isScheduledForInsert($object);
            if (!$insert && $existing === null) {
                // Moving into this organism must use the source row's current
                // roles/status, not attributes cached before another request.
                $query = $this->em->createQueryBuilder()->select($selection)->from($class, 'o')
                    ->where('o.id = :id')->setParameter('id', $object->getId())->getQuery();
                if ($lock && !$this->em->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
                    $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
                }
                $existing = $query->getOneOrNullResult();
                if ($existing === null) continue;
                if (isset($existing['user'])) $existing['user'] = 'id:'.$existing['user'];
            }
            if (!$insert) {
                $expectedTenant = $changes['entite'][0] ?? $uow->getOriginalEntityData($object)['entite'] ?? $object->getEntite();
                if ($existing['entiteId'] !== $expectedTenant?->getId()) {
                    // A stale request must not edit or delete a record that has
                    // since moved into an organism whose quota it did not lock.
                    throw new ConflictHttpException('Ce compte ou cette fiche a été déplacé vers un autre organisme. Rechargez la page avant de réessayer.');
                }
            }
            if ($uow->isScheduledForDelete($object)) continue;
            if ($insert || array_key_exists('entite', $changes)) {
                if ($object->getEntite() !== $entite) continue;
            } elseif ($existing['entiteId'] !== $entite->getId()) {
                continue;
            }
            $row = $existing ?? ['id' => $id];
            if ($object instanceof UtilisateurEntite || $object instanceof Formateur) {
                if ($existing === null || array_key_exists('utilisateur', $changes)) {
                    $user = $object->getUtilisateur();
                    $row['user'] = $user ? ($user->getId() ? 'id:'.$user->getId() : 'new:'.spl_object_id($user)) : null;
                }
            }
            if ($object instanceof UtilisateurEntite) {
                if ($existing === null || array_key_exists('roles', $changes)) $row['roles'] = $object->getRoles();
                if ($existing === null || array_key_exists('status', $changes)) $row['status'] = $object->getStatus();
            }
            $rows[$id] = $row;
        }
        return $rows;
    }
}
