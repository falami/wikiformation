<?php

namespace App\Service\Billing;

use App\Entity\Billing\EntiteSubscription;
use App\Entity\Billing\Plan;
use App\Entity\Entite;
use App\Entity\UtilisateurEntite;
use App\Repository\Billing\AddonRepository;
use App\Repository\Billing\EntiteSubscriptionRepository;
use App\Repository\Billing\PlanRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class EntitlementService
{
    public function __construct(
        private readonly EntiteSubscriptionRepository $subRepo,
        private readonly AddonRepository $addonRepo,
        private readonly PlanRepository $planRepo,
        private readonly EntityManagerInterface $em,
    ) {}

    public function getLatestSubscription(Entite $entite): ?EntiteSubscription
    {
        return $this->subRepo->findLatestForEntite($entite);
    }

    public function isEntiteActive(Entite $entite): bool
    {
        $sub = $this->subRepo->findLatestForEntite($entite);
        if (!$sub) {
            return false;
        }

        $now = new \DateTimeImmutable();

        if ($sub->getStatus() === EntiteSubscription::STATUS_ACTIVE) {
            $end = $sub->getCurrentPeriodEnd();
            if ($end instanceof \DateTimeImmutable && $end < $now) {
                return false;
            }
            return true;
        }

        if ($sub->getStatus() === EntiteSubscription::STATUS_TRIALING) {
            $ends = $sub->getTrialEndsAt();
            return $ends instanceof \DateTimeImmutable && $ends > $now;
        }

        return false;
    }

    public function limits(Entite $entite, bool $lock = false): array
    {
        $sub = $lock ? $this->subscriptionForFlush($entite) : $this->getLatestSubscription($entite);

        if (!$sub) {
            return [
                'max_apprenants_an'   => 0,
                'max_utilisateurs'    => 0,
                'max_formateurs'      => 0,
                'max_commerciaux'     => 0,
                'max_entreprises'     => 0,
                'max_prospects'       => 0,
                'support_prioritaire' => false,
                'seat_limits'         => [],
                'is_trial'            => false,
            ];
        }

        $plan = $sub->getPlan();

        if (!$plan) {
            return [
                'max_apprenants_an'   => 50,
                'max_utilisateurs'    => 1,
                'max_formateurs'      => 1,
                'max_commerciaux'     => 1,
                'max_entreprises'     => 1,
                'max_prospects'       => 50,
                'support_prioritaire' => false,
                'seat_limits'         => [],
                'is_trial'            => $sub->getStatus() === EntiteSubscription::STATUS_TRIALING,
            ];
        }

        $extraApprenants = 0;
        // Normalize historical JSON too: an add-on is a selected pack, not a
        // quantity that can be multiplied by repeating its code in a request.
        $addonCodes = array_values(array_unique(array_filter(
            array_map('trim', array_filter($sub->getAddons() ?? [], 'is_string')),
            static fn (string $code): bool => $code !== '',
        )));
        foreach ($addonCodes as $addonCode) {
            if ($lock) {
                $query = $this->addonRepo->createQueryBuilder('a')->select('a.extraApprenantsAn')
                    ->where('a.code = :code AND a.isActive = true')->setParameter('code', $addonCode)->getQuery();
                $extraApprenants += (int) ($this->lockingRead($query, false)->getOneOrNullResult()['extraApprenantsAn'] ?? 0);
            } else {
                $addon = $this->addonRepo->findOneBy(['code' => $addonCode, 'isActive' => true]);
                $extraApprenants += $addon?->getExtraApprenantsAn() ?? 0;
            }
        }

        return [
            'max_apprenants_an'   => ($plan->getMaxApprenantsAn() === 0 ? 0 : $plan->getMaxApprenantsAn() + $extraApprenants),
            'max_utilisateurs'    => $plan->getMaxUtilisateurs(),
            'max_formateurs'      => $plan->getMaxFormateurs(),
            'max_commerciaux'     => $plan->getMaxCommerciaux(),
            'max_entreprises'     => $plan->getMaxEntreprises(),
            'max_prospects'       => $plan->getMaxProspects(),
            'support_prioritaire' => $plan->isSupportPrioritaire(),
            'seat_limits'         => $plan->getNormalizedSeatLimits(),
            'is_trial'            => $sub->getStatus() === EntiteSubscription::STATUS_TRIALING,
        ];
    }

    /**
     * Resolve the post-flush subscription without refreshing managed entities:
     * refreshing would discard a pending plan change, while the identity map or
     * a consistent read can retain a concurrently replaced subscription/plan.
     */
    private function subscriptionForFlush(Entite $entite): ?EntiteSubscription
    {
        $uow = $this->em->getUnitOfWork();
        $rows = [];
        if ($entite->getId() !== null) {
            $query = $this->subRepo->createQueryBuilder('s')
                ->select('s.id, s.status, s.addons, IDENTITY(s.plan) AS planId, IDENTITY(s.entite) AS entiteId')
                ->where('s.entite = :entite')->setParameter('entite', $entite)->getQuery();
            foreach ($this->lockingRead($query)->getArrayResult() as $row) {
                $rows[$row['id']] = $row;
            }
        }

        foreach ([...$uow->getScheduledEntityUpdates(), ...$uow->getScheduledEntityDeletions()] as $object) {
            if (!$object instanceof EntiteSubscription) continue;
            $id = $object->getId();
            $changes = $uow->getEntityChangeSet($object);
            $expectedTenant = $changes['entite'][0] ?? $uow->getOriginalEntityData($object)['entite'] ?? $object->getEntite();
            if ($expectedTenant !== $entite && $object->getEntite() !== $entite) continue;
            // A subscription can also be reassigned into this organism.
            if (!isset($rows[$id])) {
                $query = $this->subRepo->createQueryBuilder('s')
                    ->select('s.id, s.status, s.addons, IDENTITY(s.plan) AS planId, IDENTITY(s.entite) AS entiteId')
                    ->where('s.id = :id')->setParameter('id', $id)->getQuery();
                $row = $this->lockingRead($query)->getOneOrNullResult();
                if (!$row) continue;
                $rows[$id] = $row;
            }
            if ($rows[$id]['entiteId'] !== $expectedTenant?->getId()) {
                throw new ConflictHttpException('Cet abonnement a été déplacé vers un autre organisme. Rechargez la page avant de réessayer.');
            }
            if ($uow->isScheduledForDelete($object) || $object->getEntite() !== $entite) {
                unset($rows[$id]);
                continue;
            }
            foreach ($changes as $field => [, $value]) {
                if ($field === 'plan') $rows[$id]['pendingPlan'] = $value;
                if (in_array($field, ['status', 'addons'], true)) $rows[$id][$field] = $value;
            }
        }
        krsort($rows, SORT_NUMERIC);
        $row = reset($rows) ?: null;
        // New subscriptions will have the highest generated ID after insertion.
        foreach ($uow->getScheduledEntityInsertions() as $object) {
            if ($object instanceof EntiteSubscription && $object->getEntite() === $entite) {
                $row = ['status' => $object->getStatus(), 'addons' => $object->getAddons(), 'pendingPlan' => $object->getPlan()];
            }
        }
        if ($row === null) return null;

        $plan = array_key_exists('pendingPlan', $row)
            ? $row['pendingPlan']
            : ($row['planId'] === null ? null : $this->em->getReference(Plan::class, $row['planId']));

        return (new EntiteSubscription())->setEntite($entite)->setStatus($row['status'])
            ->setAddons($row['addons'])->setPlan($plan ? $this->planForFlush($plan) : null);
    }

    private function planForFlush(Plan $plan): Plan
    {
        if ($plan->getId() === null) return $plan;
        $query = $this->planRepo->createQueryBuilder('p')->where('p.id = :id')
            ->setParameter('id', $plan->getId())->getQuery();
        $changes = $this->em->getUnitOfWork()->getEntityChangeSet($plan);
        $row = $this->lockingRead($query, $changes !== [])->getOneOrNullResult(Query::HYDRATE_ARRAY);
        if (!$row) throw new \LogicException('Le plan de cet abonnement est introuvable.');
        foreach ($changes as $field => [, $value]) {
            $row[$field] = $value;
        }
        return (new Plan())->setMaxApprenantsAn($row['maxApprenantsAn'])
            ->setMaxUtilisateurs($row['maxUtilisateurs'])->setMaxFormateurs($row['maxFormateurs'])
            ->setMaxEntreprises($row['maxEntreprises'])->setMaxProspects($row['maxProspects'])
            ->setSupportPrioritaire($row['supportPrioritaire'])->setSeatLimits($row['seatLimits']);
    }

    private function lockingRead(Query $query, bool $write = true): Query
    {
        if (!$this->em->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            $query->setLockMode($write ? LockMode::PESSIMISTIC_WRITE : LockMode::PESSIMISTIC_READ);
        }
        return $query;
    }

    public function limit(string $key, Entite $entite): ?int
    {
        $limits = $this->limits($entite);
        $v = $limits[$key] ?? null;

        if ($v === 0) {
            return null; // illimité
        }

        return is_int($v) ? $v : null;
    }

    public function getSeatLimit(Entite $entite, string $tenantRole): ?int
    {
        $limits = $this->limits($entite);
        $seatLimits = $limits['seat_limits'] ?? [];
        $v = $seatLimits[$tenantRole] ?? null;

        if ($v === 0) {
            return null; // illimité
        }

        return is_int($v) ? $v : null;
    }

    public function getSeatLabel(string $tenantRole): string
    {
        return UtilisateurEntite::tenantRoleLabels()[$tenantRole] ?? $tenantRole;
    }

    public function getNextUpgradePlan(Entite $entite)
    {
        $currentPlan = $this->getLatestSubscription($entite)?->getPlan();
        return $this->planRepo->findNextUpgradePlan($currentPlan);
    }
}
