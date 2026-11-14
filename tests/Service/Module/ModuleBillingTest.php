<?php
declare(strict_types=1);
namespace App\Tests\Service\Module;
use App\Entity\{Entite, Utilisateur};
use App\Entity\Billing\{Module, ModuleAccess};
use App\Service\Module\{ModuleBilling, ModuleEntitlement};
use App\Service\Stripe\StripeClientFactory;
use Doctrine\ORM\Tools\SchemaTool;
use Stripe\{ApiRequestor, HttpClient\ClientInterface, HttpClient\CurlClient};
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ModuleBillingTest extends KernelTestCase
{
    private $em;
    private array $env;
    private ModuleAccess $row;
    private ModuleBilling $billing;
    protected function setUp(): void
    {
        $this->env = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $user = (new Utilisateur())->setEmail('module@example.test')->setPassword('unused')->setPrenom('Test')->setNom('Test');
        $tenant = (new Entite())->setNom('Test')->setPublic(false)->setCreateur($user);
        $module = new Module(); $module->code = 'caces'; $module->name = 'CACES'; $module->active = true; $module->stripePriceId = 'price_test';
        $this->row = new ModuleAccess(); $this->row->entite = $tenant; $this->row->module = $module; $this->row->customerId = 'cus_test'; $this->row->priceId = 'price_test'; $this->row->price = 20000;
        foreach ([$user, $tenant, $module, $this->row] as $row) $this->em->persist($row);
        $this->em->flush();
        $this->billing = new ModuleBilling($this->em, new StripeClientFactory('sk_test_fixture'), new ModuleEntitlement($this->em));
    }
    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(new CurlClient()); parent::tearDown();
        foreach (['ENV', 'SERVER'] as $i => $key) { if ($this->env[$i] === null) unset($GLOBALS['_'.$key]['DATABASE_URL']); else $GLOBALS['_'.$key]['DATABASE_URL'] = $this->env[$i]; }
    }
    private function subscription(string $status = 'active'): array
    {
        return ['id' => 'sub_test', 'object' => 'subscription', 'status' => $status, 'customer' => 'cus_test', 'metadata' => ['module_access' => (string)$this->row->id, 'entite_id' => (string)$this->row->entite->getId(), 'module_code' => 'caces'], 'items' => ['data' => [['quantity' => 1, 'price' => ['id' => 'price_test'], 'current_period_end' => time()+86400]]]];
    }
    public function testAccessExpiresWithoutWebhookAndNeverDeletesData(): void
    {
        $this->billing->sync($this->row, $this->subscription());
        self::assertTrue($this->row->hasAccess());
        self::assertFalse($this->row->hasAccess(new \DateTimeImmutable('+2 days')));
        $this->billing->sync($this->row, $this->subscription('past_due')); self::assertFalse($this->row->hasAccess());
        $this->billing->sync($this->row, $this->subscription('canceled')); self::assertFalse($this->row->hasAccess());
        $this->billing->sync($this->row, $this->subscription()); self::assertTrue($this->row->hasAccess());
        self::assertCount(4, $this->row->history);
    }
    public function testTrialAndCancellationHaveBoundedAccess(): void
    {
        $sub = $this->subscription('trialing'); $sub['trial_start'] = time(); $sub['trial_end'] = time()+3600; $sub['cancel_at_period_end'] = true;
        $this->billing->sync($this->row, $sub);
        self::assertTrue($this->row->trialUsed); self::assertTrue($this->row->cancelAtPeriodEnd);
        self::assertTrue($this->row->hasAccess()); self::assertFalse($this->row->hasAccess(new \DateTimeImmutable('+2 hours')));
        $this->billing->sync($this->row, $this->subscription('canceled')); self::assertTrue($this->row->trialUsed);
    }
    public function testGiftCanExpireOrBeRevokedIndependently(): void
    {
        $this->row->gift = true; self::assertTrue($this->row->hasAccess());
        $this->row->giftEnd = new \DateTimeImmutable('-1 second'); self::assertFalse($this->row->hasAccess());
        $this->row->giftEnd = null; $this->row->gift = false; self::assertFalse($this->row->hasAccess());
    }
    public function testWrongTenantCannotActivateSubscription(): void
    {
        $sub = $this->subscription(); $sub['metadata']['entite_id'] = '9999';
        $this->expectException(\DomainException::class); $this->billing->sync($this->row, $sub);
    }
    public function testOldReplayedEventUsesLatestStripeState(): void
    {
        $this->row->subscriptionId = 'sub_test'; $this->row->status = 'active'; $this->em->flush();
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::exactly(2))->method('request')->willReturn([json_encode($this->subscription('canceled')), 200, []]);
        ApiRequestor::setHttpClient($http);
        $event = $this->subscription('active');
        self::assertTrue($this->billing->webhook('customer.subscription.updated', $event));
        self::assertTrue($this->billing->webhook('customer.subscription.updated', $event));
        self::assertFalse($this->row->hasAccess()); self::assertSame('canceled', $this->row->status); self::assertCount(1, $this->row->history);
    }
    public function testCheckoutReplayedAfterSubscriptionEventIsSafe(): void
    {
        $this->row->subscriptionId = 'sub_test'; $this->row->checkoutId = null; $this->em->flush();
        $http = $this->createMock(ClientInterface::class); $http->method('request')->willReturn([json_encode($this->subscription()), 200, []]); ApiRequestor::setHttpClient($http);
        self::assertTrue($this->billing->webhook('checkout.session.completed', ['id' => 'cs_test', 'customer' => 'cus_test', 'subscription' => 'sub_test', 'metadata' => ['module_access' => (string)$this->row->id]]));
        self::assertTrue($this->row->hasAccess());
    }
    public function testRepeatedCheckoutUsesSameOpenSession(): void
    {
        $this->row->checkoutId = 'cs_test'; $this->em->flush();
        $http = $this->createMock(ClientInterface::class); $http->expects(self::exactly(2))->method('request')->willReturn([json_encode(['id' => 'cs_test', 'object' => 'checkout.session', 'status' => 'open', 'url' => 'https://checkout.stripe.com/fixture']), 200, []]); ApiRequestor::setHttpClient($http);
        foreach ([1,2] as $_) self::assertSame('https://checkout.stripe.com/fixture', $this->billing->checkout($this->row->entite, $this->row->module, 'https://example.test/options'));
    }
    public function testNewCheckoutUsesPlatformAccountAndTrialOnlyOnce(): void
    {
        $this->row->trialUsed = true; $this->em->flush();
        $price = ['id' => 'price_test', 'object' => 'price', 'active' => true, 'currency' => 'eur', 'unit_amount' => 20000, 'recurring' => ['interval' => 'month', 'interval_count' => 1, 'usage_type' => 'licensed'], 'billing_scheme' => 'per_unit'];
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::exactly(2))->method('request')->willReturnCallback(function ($method, $url, $headers, $params) use ($price) {
            if (str_contains($url, '/prices/')) return [json_encode($price), 200, []];
            self::assertSame('subscription', $params['mode']);
            self::assertSame('cus_test', $params['customer']);
            self::assertSame([['price' => 'price_test', 'quantity' => 1]], $params['line_items']);
            self::assertArrayNotHasKey('trial_period_days', $params['subscription_data']);
            self::assertArrayNotHasKey('transfer_data', $params['subscription_data']);
            self::assertArrayNotHasKey('application_fee_percent', $params['subscription_data']);
            self::assertStringNotContainsString('Stripe-Account:', implode(' ', $headers));
            return [json_encode(['id' => 'cs_new', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/new']), 200, []];
        });
        ApiRequestor::setHttpClient($http);
        self::assertSame('https://checkout.stripe.com/new', $this->billing->checkout($this->row->entite, $this->row->module, 'https://example.test/options'));
        self::assertSame('cs_new', $this->row->checkoutId);
        self::assertFalse($this->row->hasAccess());
    }
    public function testCanceledOldSubscriptionDoesNotErasePendingReactivationCheckout(): void
    {
        $this->row->subscriptionId = 'sub_test'; $this->row->status = 'canceled'; $this->row->checkoutId = 'cs_new'; $this->em->flush();
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::once())->method('request')->willReturnCallback(function ($method, $url) {
            return [json_encode(str_contains($url, '/subscriptions/') ? $this->subscription('canceled') : ['id' => 'cs_new', 'object' => 'checkout.session', 'status' => 'open', 'url' => 'https://checkout.stripe.com/new']), 200, []];
        });
        ApiRequestor::setHttpClient($http);
        self::assertSame('https://checkout.stripe.com/new', $this->billing->checkout($this->row->entite, $this->row->module, 'https://example.test/options'));
        self::assertSame('cs_new', $this->row->checkoutId);
    }
    public function testCancellationAndResumeKeepPaidAccess(): void
    {
        $this->row->subscriptionId = 'sub_test'; $this->row->status = 'active'; $this->em->flush();
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::exactly(4))->method('request')->willReturnCallback(function ($method, $url, $headers, $params) {
            $sub = $this->subscription(); $sub['cancel_at_period_end'] = in_array($params['cancel_at_period_end'] ?? false, [true, 'true'], true);
            return [json_encode($sub), 200, []];
        });
        ApiRequestor::setHttpClient($http);
        $this->billing->cancellation($this->row->entite, $this->row->module, true, 42);
        self::assertTrue($this->row->cancelAtPeriodEnd); self::assertTrue($this->row->hasAccess());
        $this->billing->cancellation($this->row->entite, $this->row->module, false, 42);
        self::assertFalse($this->row->cancelAtPeriodEnd); self::assertTrue($this->row->hasAccess());
    }
    public function testModuleInvoiceIsRecordedInPlatformRevenueIdempotently(): void
    {
        $this->row->subscriptionId = 'sub_test'; $this->em->flush();
        $revenue = self::getContainer()->get(\App\Service\Platform\StripeRevenue::class);
        $invoice = ['id' => 'in_module_test', 'status' => 'paid', 'subscription' => 'sub_test', 'customer' => 'cus_test', 'status_transitions' => ['paid_at' => time()], 'total' => 20000, 'total_excluding_tax' => 20000, 'amount_paid' => 20000];
        foreach ([1,2] as $_) { $entry = $revenue->record($invoice); $this->em->flush(); self::assertSame(20000, $entry->paid); }
        self::assertSame(1, $this->em->getRepository(\App\Entity\Platform\Revenue::class)->count([]));
        $invoice['customer'] = 'cus_foreign'; self::assertNull($revenue->record($invoice));
    }

    public function testPortalCannotSwitchModuleToAnotherProduct(): void
    {
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::exactly(2))->method('request')->willReturnCallback(function ($method, $url, $headers, $params) {
            if (str_contains($url, '/configurations')) {
                self::assertTrue(in_array($params['features']['subscription_update']['enabled'], [false, 'false'], true));
                self::assertSame('at_period_end', $params['features']['subscription_cancel']['mode']);
                return [json_encode(['id' => 'bpc_modules', 'object' => 'billing_portal.configuration']), 200, []];
            }
            self::assertSame('cus_test', $params['customer']); self::assertSame('bpc_modules', $params['configuration']);
            return [json_encode(['id' => 'bps_test', 'object' => 'billing_portal.session', 'url' => 'https://billing.stripe.com/fixture']), 200, []];
        });
        ApiRequestor::setHttpClient($http);
        self::assertSame('https://billing.stripe.com/fixture', $this->billing->portal($this->row->entite, $this->row->module, 'https://example.test/options'));
    }

    private function completedCheckout(): array
    {
        return ['id' => 'cs_completed', 'object' => 'checkout.session', 'status' => 'complete', 'mode' => 'subscription', 'customer' => 'cus_test', 'subscription' => 'sub_test', 'metadata' => ['module_access' => (string) $this->row->id]];
    }

    public function testCompletedCheckoutRecoversTrialWhenWebhookWasMissed(): void
    {
        $this->row->checkoutId = 'cs_completed'; $this->em->flush();
        $id = $this->row->id;
        $sub = $this->subscription('trialing'); $sub['trial_start'] = time(); $sub['trial_end'] = time() + 86400;
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::exactly(2))->method('request')->willReturnCallback(function ($method, $url) use ($sub) {
            self::assertSame('get', strtolower($method));
            return [json_encode(str_contains($url, '/checkout/sessions/') ? $this->completedCheckout() : $sub), 200, []];
        });
        ApiRequestor::setHttpClient($http);
        self::assertSame('https://example.test/options', $this->billing->checkout($this->row->entite, $this->row->module, 'https://example.test/options'));
        $this->em->clear();
        $saved = $this->em->find(ModuleAccess::class, $id);
        self::assertTrue($saved->hasAccess()); self::assertTrue($saved->trialUsed);
        self::assertSame('sub_test', $saved->subscriptionId); self::assertNull($saved->checkoutId);
        self::assertSame('trialing', $saved->status);
    }

    public function testCompletedCheckoutCannotActivateForeignCustomer(): void
    {
        $this->row->checkoutId = 'cs_completed'; $this->em->flush();
        $session = $this->completedCheckout(); $session['customer'] = 'cus_foreign';
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::once())->method('request')->willReturn([json_encode($session), 200, []]);
        ApiRequestor::setHttpClient($http);
        $this->expectException(\DomainException::class);
        $this->billing->checkout($this->row->entite, $this->row->module, 'https://example.test/options');
    }

    public function testCompletedCheckoutWithUnpaidSubscriptionDoesNotGrantAccess(): void
    {
        $this->row->checkoutId = 'cs_completed'; $this->em->flush();
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::exactly(2))->method('request')->willReturnCallback(function ($method, $url) {
            return [json_encode(str_contains($url, '/checkout/sessions/') ? $this->completedCheckout() : $this->subscription('unpaid')), 200, []];
        });
        ApiRequestor::setHttpClient($http);
        $this->billing->checkout($this->row->entite, $this->row->module, 'https://example.test/options');
        self::assertFalse($this->row->hasAccess()); self::assertSame('unpaid', $this->row->status);
    }

    public function testPriceMismatchFailsClosed(): void
    {
        $this->expectException(\DomainException::class);
        ModuleBilling::validatePrice($this->row->module, ['id' => 'price_test', 'active' => true, 'currency' => 'eur', 'unit_amount' => 10000, 'recurring' => ['interval' => 'month', 'interval_count' => 1, 'usage_type' => 'licensed'], 'billing_scheme' => 'per_unit']);
    }
    public function testInternalAccessRequiresStableOwnerIdentity(): void
    {
        $service = new ModuleEntitlement($this->em);
        $this->row->entite->setNom('I.T. For You'); self::assertFalse($service->internal($this->row->entite));
        $this->row->entite->setSiret('80813612100023'); self::assertTrue($service->internal($this->row->entite));
    }
}
