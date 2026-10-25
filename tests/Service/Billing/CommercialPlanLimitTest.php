<?php

declare(strict_types=1);

namespace App\Tests\Service\Billing;

use App\Entity\Billing\Plan;
use App\Entity\UtilisateurEntite;
use PHPUnit\Framework\TestCase;

final class CommercialPlanLimitTest extends TestCase
{
    public function testDedicatedLimitOverridesLegacyCapacityIncludingUnlimited(): void
    {
        $plan = (new Plan())->setMaxUtilisateurs(3)->setSeatLimits(['admins' => 10]);
        self::assertSame(10, $plan->getMaxCommerciaux());
        $plan->setSeatLimits(['admins' => 10, 'commerciaux' => 5]);
        self::assertSame(5, $plan->getMaxCommerciaux());
        $plan->setMaxCommerciaux(0);
        self::assertSame(0, $plan->getMaxCommerciaux());
        self::assertSame(0, $plan->getSeatLimitFor(UtilisateurEntite::TENANT_COMMERCIAL));
        self::assertSame('Comptes commerciaux illimités', $plan->getMaxCommerciauxLabel());
    }

    public function testPlanWithoutSeatJsonUsesExistingManagementCapacityAsLegacyDefault(): void
    {
        $plan = (new Plan())->setMaxUtilisateurs(1);
        self::assertSame(1, $plan->getMaxCommerciaux());
        self::assertSame('1 compte commercial', $plan->getMaxCommerciauxLabel());
    }

    public function testExplicitRoleCapsRemainEnforcedWhileLegacyAliasesDoNotContradictPublishedTotals(): void
    {
        $plan = (new Plan())->setMaxUtilisateurs(0)->setMaxFormateurs(0)
            ->setSeatLimits(['admins' => 10, 'formateurs' => 20, UtilisateurEntite::TENANT_FORMATEUR => 7]);
        self::assertSame(0, $plan->getSeatLimitFor(UtilisateurEntite::TENANT_ADMIN));
        self::assertSame(0, $plan->getSeatLimitFor(UtilisateurEntite::TENANT_DIRIGEANT));
        self::assertSame(7, $plan->getSeatLimitFor(UtilisateurEntite::TENANT_FORMATEUR));
        self::assertSame(10, $plan->getMaxCommerciaux());
    }
}
