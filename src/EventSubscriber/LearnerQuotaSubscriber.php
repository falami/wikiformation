<?php

declare(strict_types=1);
namespace App\EventSubscriber;

use App\Entity\{UtilisateurEntite, Inscription};
use App\Entity\Billing\{LearnerYearUsage, EntiteUsageYear};
use App\Entity\Elearning\ElearningEnrollment;
use App\Enum\EnrollmentStatus;
use App\Enum\StatusInscription;
use App\Exception\BillingQuotaExceededException;
use App\Service\Billing\EntitlementService;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class LearnerQuotaSubscriber
{
    public function __construct(private EntitlementService $entitlement) {}
    public function onFlush(OnFlushEventArgs $event, array $limitsByEntity = []): void
    {
        $em=$event->getObjectManager(); $uow=$em->getUnitOfWork();
        // Keep the identity map consistent with the database ON DELETE SET NULL.
        // Otherwise a later flush would try to persist the deleted user again.
        foreach ($uow->getIdentityMap()[LearnerYearUsage::class] ?? [] as $record) {
            if ($record->getUtilisateur() && $uow->isScheduledForDelete($record->getUtilisateur()) && !$uow->isScheduledForDelete($record)) {
                $record->anonymize();
                $uow->recomputeSingleEntityChangeSet($em->getClassMetadata(LearnerYearUsage::class), $record);
            }
        }
        $year=(int)(new \DateTimeImmutable('now',new \DateTimeZone('Europe/Paris')))->format('Y');
        $groups=[];
        foreach ([...$uow->getScheduledEntityInsertions(), ...$uow->getScheduledEntityUpdates()] as $object) {
            $candidate = $this->candidate($object, $em, $uow);
            if ($candidate === null) continue;
            [$entite, $user] = $candidate;
            if (!$user || !$entite || $uow->isScheduledForDelete($entite) || $uow->isScheduledForDelete($user)) continue;
            $key=spl_object_id($entite);
            $groups[$key]['entite']=$entite;
            $groups[$key]['users'][$this->userKey($user)]=$user;
        }
        $prepared=[];
        foreach ($groups as $group) {
            $entite=$group['entite'];
            // A locking scalar read sees the latest committed rows even when the
            // caller opened a MySQL REPEATABLE READ snapshot before this flush.
            $locking = $em->getConnection()->isTransactionActive()
                && !$em->getConnection()->getDatabasePlatform() instanceof SQLitePlatform;
            $existing = [];
            if ($entite->getId()) {
                $query = $em->createQueryBuilder()->select('r.id, IDENTITY(r.utilisateur) AS user')
                    ->from(LearnerYearUsage::class, 'r')->where('r.entite = :entite AND r.year = :year')
                    ->setParameter('entite', $entite)->setParameter('year', $year)->getQuery();
                if ($locking) $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
                $existing = $query->getArrayResult();
            }
            $seen=[];
            foreach ($existing as $record) if ($record['user'] !== null) $seen['id:'.$record['user']]=true;
            $current=count($existing);
            // Preserve records already prepared in this unit of work, including
            // a retry after an exception in another onFlush listener.
            foreach ($uow->getScheduledEntityInsertions() as $record) {
                if (!$record instanceof LearnerYearUsage || $record->getEntite() !== $entite || $record->getYear() !== $year) continue;
                $user = $record->getUtilisateur();
                if ($user) $seen[$this->userKey($user)] = true;
                ++$current;
            }
            $new=[];
            foreach ($group['users'] as $key=>$user) if (!isset($seen[$key])) $new[]=new LearnerYearUsage($entite,$user,$year);
            if (!$new) continue;
            $limits = $limitsByEntity[spl_object_id($entite)] ?? $this->entitlement->limits($entite, $locking);
            $limit = ($limits['max_apprenants_an'] ?? 0) > 0 ? $limits['max_apprenants_an'] : null;
            if ($limit!==null && $current+count($new)>$limit) throw new BillingQuotaExceededException('max_apprenants_an',$current,$limit,sprintf('Quota de stagiaires distincts pour %d atteint (%d/%d).',$year,$current,$limit));
            $usage = null;
            if ($entite->getId()) {
                $query = $em->createQueryBuilder()->select('u.id')->from(EntiteUsageYear::class, 'u')
                    ->where('u.entite = :entite AND u.year = :year')
                    ->setParameter('entite', $entite)->setParameter('year', $year)->getQuery();
                if ($locking) $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
                $id = $query->getOneOrNullResult();
                if ($id) $usage = $em->find(EntiteUsageYear::class, $id['id']);
            }
            if (!$usage) {
                foreach ($uow->getScheduledEntityInsertions() as $pending) {
                    if ($pending instanceof EntiteUsageYear && $pending->getEntite() === $entite && $pending->getYear() === $year) {
                        $usage = $pending;
                        break;
                    }
                }
            }
            $usage ??= new EntiteUsageYear($entite,$year);
            $prepared[]=[$new,$usage,$current+count($new)];
        }
        // Mutate only after every group has passed validation.
        foreach ($prepared as [$new,$usage,$total]) {
            foreach ($new as $record) { $em->persist($record); $uow->computeChangeSet($em->getClassMetadata(LearnerYearUsage::class),$record); }
            $usage->setApprenantsCount($total)->setUpdatedAt(new \DateTimeImmutable());
            if ($usage->getId()===null) { $em->persist($usage); $uow->computeChangeSet($em->getClassMetadata(EntiteUsageYear::class),$usage); }
            else $uow->recomputeSingleEntityChangeSet($em->getClassMetadata(EntiteUsageYear::class),$usage);
        }
    }

    private function userKey(\App\Entity\Utilisateur $user): string
    {
        return $user->getId() !== null ? 'id:'.$user->getId() : 'new:'.spl_object_id($user);
    }

    /** Resolve only changes that can consume a learner, using fresh persisted state. */
    private function candidate(object $object, EntityManagerInterface $em, UnitOfWork $uow): ?array
    {
        if (!$object instanceof UtilisateurEntite && !$object instanceof Inscription && !$object instanceof ElearningEnrollment) return null;
        $member = $object instanceof UtilisateurEntite;
        $userField = $member ? 'utilisateur' : 'stagiaire';
        $changes = $uow->getEntityChangeSet($object);
        $insert = $uow->isScheduledForInsert($object);
        if (!$insert && !array_intersect(array_keys($changes), ['entite', $userField, 'status', ...($member ? ['roles'] : [])])) return null;

        $entite = $object->getEntite();
        $user = $member ? $object->getUtilisateur() : $object->getStagiaire();
        $future = ['status' => $object->getStatus(), 'roles' => $member ? $object->getRoles() : []];
        $previous = null;
        if (!$insert) {
            $selection = 'o.status, IDENTITY(o.entite) AS entiteId, IDENTITY(o.'.$userField.') AS userId';
            if ($member) $selection .= ', o.roles';
            $class = $member ? UtilisateurEntite::class : ($object instanceof Inscription ? Inscription::class : ElearningEnrollment::class);
            $query = $em->createQueryBuilder()->select($selection)->from($class, 'o')
                ->where('o.id = :id')->setParameter('id', $object->getId())->getQuery();
            if ($em->getConnection()->isTransactionActive() && !$em->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
                $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
            }
            $previous = $query->getOneOrNullResult();
            $expectedEntite = $changes['entite'][0] ?? $entite;
            if ($previous === null || (int)$previous['entiteId'] !== $expectedEntite?->getId()) {
                throw new ConflictHttpException('Ce dossier a été déplacé ou supprimé pendant votre modification. Rechargez la page avant de réessayer.');
            }
            // Doctrine writes only changed fields. A concurrent suspension or
            // reassignment must not be overwritten in our projected count.
            if (!array_key_exists('status', $changes)) $future['status'] = $previous['status'];
            if ($member && !array_key_exists('roles', $changes)) $future['roles'] = $previous['roles'];
            if (!array_key_exists($userField, $changes)) {
                $user = $previous['userId'] === null ? null : $em->getReference(\App\Entity\Utilisateur::class, $previous['userId']);
            }
        }
        if (!$user || !$entite || !$this->consumesLearner($object, $future)) return null;
        if ($previous !== null && (int)$previous['userId'] === $user->getId()
            && (int)$previous['entiteId'] === $entite->getId() && $this->consumesLearner($object, $previous)) return null;

        return [$entite, $user];
    }

    private function consumesLearner(object $object, array $state): bool
    {
        $status = $state['status'] instanceof \BackedEnum ? $state['status']->value : $state['status'];
        if ($object instanceof UtilisateurEntite) {
            return $status === UtilisateurEntite::STATUS_ACTIVE && in_array(UtilisateurEntite::TENANT_STAGIAIRE, $state['roles'], true);
        }
        if ($object instanceof Inscription) return $status !== StatusInscription::ANNULE->value;

        return !in_array($status, [EnrollmentStatus::EXPIRED->value, EnrollmentStatus::SUSPENDED->value], true);
    }
}
