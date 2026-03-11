<?php

namespace App\Service\Billing;

use App\Entity\Billing\EntiteSubscription;
use App\Entity\Entite;
use App\Entity\UtilisateurEntite;
use App\Repository\Billing\AddonRepository;
use App\Repository\Billing\EntiteSubscriptionRepository;
use App\Repository\Billing\PlanRepository;

final class EntitlementService
{
    public function __construct(
        private readonly EntiteSubscriptionRepository $subRepo,
        private readonly AddonRepository $addonRepo,
        private readonly PlanRepository $planRepo,
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

    public function limits(Entite $entite): array
    {
        $sub = $this->getLatestSubscription($entite);

        if (!$sub) {
            return [
                'max_apprenants_an'   => 0,
                'max_utilisateurs'    => 0,
                'max_formateurs'      => 0,
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
                'max_entreprises'     => 1,
                'max_prospects'       => 50,
                'support_prioritaire' => false,
                'seat_limits'         => [],
                'is_trial'            => $sub->getStatus() === EntiteSubscription::STATUS_TRIALING,
            ];
        }

        $extraApprenants = 0;
        foreach (($sub->getAddons() ?? []) as $addonCode) {
            $addon = $this->addonRepo->findOneBy([
                'code' => $addonCode,
                'isActive' => true,
            ]);

            if ($addon?->getExtraApprenantsAn()) {
                $extraApprenants += $addon->getExtraApprenantsAn();
            }
        }

        return [
            'max_apprenants_an'   => $plan->getMaxApprenantsAn() + $extraApprenants,
            'max_utilisateurs'    => $plan->getMaxUtilisateurs(),
            'max_formateurs'      => $plan->getMaxFormateurs(),
            'max_entreprises'     => $plan->getMaxEntreprises(),
            'max_prospects'       => $plan->getMaxProspects(),
            'support_prioritaire' => $plan->isSupportPrioritaire(),
            'seat_limits'         => $plan->getNormalizedSeatLimits(),
            'is_trial'            => $sub->getStatus() === EntiteSubscription::STATUS_TRIALING,
        ];
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