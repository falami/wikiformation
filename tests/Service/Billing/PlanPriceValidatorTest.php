<?php

namespace App\Tests\Service\Billing;

use App\Entity\Billing\Plan;
use App\Service\Billing\{PlanPriceValidator, StripeBillingService};
use PHPUnit\Framework\TestCase;

final class PlanPriceValidatorTest extends TestCase
{
    private function plan(): Plan
    {
        return (new Plan())->setCode('SOLO')->setName('Solo')->setPriceMonthlyCents(2900)->setStripePriceMonthlyId('price_solo_test');
    }

    private function price(): array
    {
        return ['active' => true, 'currency' => 'eur', 'unit_amount' => 2900, 'type' => 'recurring', 'tax_behavior' => 'exclusive', 'recurring' => ['interval' => 'month', 'interval_count' => 1]];
    }

    public function testSoloIsMonthlyAndHasNoUnconfiguredAnnualOption(): void
    {
        $plan = $this->plan();
        self::assertSame(2900, $plan->getPriceFor('month'));
        self::assertNull($plan->getPriceFor('year'));
        self::assertNull($plan->getPriceFor('invalid'));
        self::assertTrue($plan->isCheckoutConfigured('month'));
        self::assertFalse($plan->isCheckoutConfigured('year'));
        self::assertFalse($plan->isCheckoutConfigured('invalid'));
        $plan->setStripePriceMonthlyId(null);
        self::assertFalse($plan->isCheckoutConfigured('month'));
    }

    public function testMatchingExclusiveMonthlyPriceIsAccepted(): void
    {
        PlanPriceValidator::assertMatches($this->plan(), 'month', $this->price());
        self::assertTrue(true);
    }

    public function testAmountsCurrencyAndRecurringIntervalMustMatch(): void
    {
        foreach ([['unit_amount'=>15900], ['currency'=>'usd'], ['active'=>false], ['tax_behavior'=>'inclusive'], ['recurring'=>['interval'=>'year', 'interval_count'=>1]], ['recurring'=>['interval'=>'month', 'interval_count'=>3]]] as $change) {
            try {
                PlanPriceValidator::assertMatches($this->plan(), 'month', array_replace($this->price(), $change));
                self::fail('A different Stripe price must never be charged.');
            } catch (\DomainException) {
                self::assertTrue(true);
            }
        }
    }

    public function testCatalogueRenderingDoesNotNeedStripeConnection(): void
    {
        $billing = new StripeBillingService('unused-no-network', 'http://localhost');
        self::assertSame(['SOLO' => ['month'=>2900, 'year'=>null]], $billing->getPlansPublicPrices([$this->plan()]));
    }

    public function testUnconfiguredCheckoutStopsBeforeAnyStripeRequest(): void
    {
        $billing = new StripeBillingService('unused-no-network', 'http://localhost');
        $this->expectException(\DomainException::class);
        $billing->validatePlanPrice($this->plan()->setStripePriceMonthlyId(null), 'month');
    }
    public function testExcellence150RefusesLegacy799Amount(): void
    {
        $plan = (new Plan())->setCode('EXCELLENCE_2026')->setName('Excellence')->setPriceMonthlyCents(15000)->setStripePriceMonthlyId('price_new150');
        PlanPriceValidator::assertMatches($plan, 'month', array_replace($this->price(), ['unit_amount'=>15000]));
        $this->expectException(\DomainException::class);
        PlanPriceValidator::assertMatches($plan, 'month', array_replace($this->price(), ['unit_amount'=>79900]));
    }

    public function testRetiredPlansAndAnnualPricesCannotReachStripe(): void
    {
        $billing = new StripeBillingService('unused-no-network', 'http://localhost');
        $yearly = $this->plan()->setPriceYearlyCents(34800)->setStripePriceYearlyId('price_year');
        $retired = $this->plan()->setCode('EXCELLENCE')->setPriceMonthlyCents(79900);
        foreach ([[$yearly, 'year'], [$retired, 'month']] as [$plan, $interval]) {
            self::assertTrue($plan->isCheckoutConfigured($interval));
            self::assertFalse($plan->isAvailableForNewSubscription($interval));
            foreach (['validatePlanPrice', 'createCheckoutSession'] as $method) {
                try {
                    if ($method === 'validatePlanPrice') $billing->validatePlanPrice($plan, $interval);
                    else $billing->createCheckoutSession('cus_test', $plan, $interval);
                    self::fail('New sales must be refused before any network request.');
                } catch (\DomainException) {
                    self::assertTrue(true);
                }
            }
        }
    }

}
