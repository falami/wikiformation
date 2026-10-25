<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\Entite;
use App\Entity\Inscription;
use App\Entity\UtilisateurEntite;
use App\Entity\Billing\EntiteSubscription;
use App\Entity\Elearning\ElearningEnrollment;
use App\Exception\BillingQuotaExceededException;
use App\Service\Billing\{EntitlementService, QuotaUsage};
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;

#[AsDoctrineListener(event: 'onFlush', priority: -10000)]
#[AsDoctrineListener(event: 'postFlush', priority: 10000)]
#[AsDoctrineListener(event: 'onClear')]
final class BillingQuotaSubscriber
{
    private ?\Doctrine\DBAL\Connection $ownedTransaction = null;
    public function __construct(private QuotaUsage $usage, private EntitlementService $entitlement, private LearnerQuotaSubscriber $learners) {}

    public function postFlush(): void
    {
        if ($this->ownedTransaction) {
            $connection=$this->ownedTransaction;
            $this->ownedTransaction=null;
            $connection->commit();
        }
    }

    public function onClear(): void
    {
        if ($this->ownedTransaction) {
            $connection=$this->ownedTransaction;
            $this->ownedTransaction=null;
            if ($connection->isTransactionActive()) $connection->rollBack();
        }
    }

    public function onFlush(OnFlushEventArgs $event): void
    {
        $em = $event->getObjectManager();
        $uow = $em->getUnitOfWork();
        $entities = [];
        foreach ([...$uow->getScheduledEntityInsertions(), ...$uow->getScheduledEntityUpdates(), ...$uow->getScheduledEntityDeletions()] as $object) {
            foreach ([...QuotaUsage::CLASSES, Inscription::class, ElearningEnrollment::class, EntiteSubscription::class] as $class) {
                if (!$object instanceof $class) continue;
                // A move or removal can also increase usage in the source organism
                // (e.g. removing the suspended membership of a trainer profile).
                foreach ([$object->getEntite(), $uow->getEntityChangeSet($object)['entite'][0] ?? null] as $entite) {
                    if ($entite instanceof Entite && !$uow->isScheduledForDelete($entite)) {
                        $entities[spl_object_id($entite)] = $entite;
                    }
                }
                break;
            }
        }
        $connection=$em->getConnection();
        if (!$entities) { $this->learners->onFlush($event); return; }
        if (!$connection->isTransactionActive()) { $connection->beginTransaction(); $this->ownedTransaction=$connection; }
        try {
            // Serialize concurrent quota-consuming flushes for each organism until SQL writes commit.
            uasort($entities, static fn (Entite $a, Entite $b): int => ($a->getId() ?? PHP_INT_MAX) <=> ($b->getId() ?? PHP_INT_MAX));
            if (!$connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\SQLitePlatform) {
                foreach ($entities as $entite) {
                    if ($entite->getId() !== null) $em->lock($entite, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
                }
            }
            $limitsByEntity = [];
            foreach ($entities as $entite) {
                $limits = $limitsByEntity[spl_object_id($entite)] = $this->entitlement->limits($entite, true);
                $before = $this->usage->counts($entite, false, true);
                $after = $this->usage->counts($entite, true, true);
                foreach (['max_utilisateurs'=>'utilisateurs de gestion', 'max_formateurs'=>'formateurs', 'max_commerciaux'=>'commerciaux', 'max_entreprises'=>'entreprises', 'max_prospects'=>'prospects'] as $key => $label) {
                    $limit = ($limits[$key] ?? 0) > 0 ? $limits[$key] : null;
                    if ($limit !== null && $after[$key] > $before[$key] && $after[$key] > $limit) {
                        throw new BillingQuotaExceededException($key, $before[$key], $limit, sprintf('Limite de %s atteinte (%d/%d). Consultez votre abonnement pour augmenter votre capacité.', $label, $before[$key], $limit));
                    }
                }
                foreach ($after['roles'] as $role => $count) {
                    if ($role === UtilisateurEntite::TENANT_STAGIAIRE) continue;
                    $limit = ($limits['seat_limits'][$role] ?? 0) > 0 ? $limits['seat_limits'][$role] : null;
                    if ($limit !== null && $count > ($before['roles'][$role] ?? 0) && $count > $limit) {
                        throw new BillingQuotaExceededException($role, $before['roles'][$role] ?? 0, $limit, sprintf('Limite de comptes « %s » atteinte (%d/%d).', $this->entitlement->getSeatLabel($role), $before['roles'][$role] ?? 0, $limit));
                    }
                }
            }
            $this->learners->onFlush($event, $limitsByEntity);
        } catch (\Throwable $error) {
            $this->onClear();
            throw $error;
        }
    }
}
