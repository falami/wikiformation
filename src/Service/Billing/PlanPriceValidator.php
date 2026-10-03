<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\Entity\Billing\Plan;

/** Prevent a misconfigured Stripe identifier from charging a different price than advertised. */
final class PlanPriceValidator
{
    public static function assertMatches(Plan $plan, string $interval, array $price): void
    {
        if (!$plan->isCheckoutConfigured($interval)
            || ($price['active'] ?? false) !== true
            || ($price['currency'] ?? '') !== 'eur'
            || ($price['unit_amount'] ?? null) !== $plan->getPriceFor($interval)
            || ($price['type'] ?? '') !== 'recurring'
            || ($price['recurring']['interval'] ?? '') !== $interval
            || ($price['recurring']['interval_count'] ?? 0) !== 1
            || ($price['tax_behavior'] ?? '') !== 'exclusive') {
            throw new \DomainException('Le tarif Stripe ne correspond pas au montant HT et à la périodicité de cette offre.');
        }
    }
}
