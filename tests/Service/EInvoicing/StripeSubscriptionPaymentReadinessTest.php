<?php
declare(strict_types=1);

namespace App\Tests\Service\EInvoicing;

use App\Entity\Billing\EntiteSubscription;
use App\Entity\Entite;
use App\Service\EInvoicing\StripeSubscriptionPaymentReadiness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stripe\{ApiRequestor, HttpClient\ClientInterface, HttpClient\CurlClient};

final class StripeSubscriptionPaymentReadinessTest extends TestCase
{
    private array $requests = [];

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(new CurlClient());
        foreach ($this->requests as [$method]) self::assertSame('get', $method, 'Readiness must never change Stripe state.');
    }

    private function local(): EntiteSubscription
    {
        $entite = new Entite();
        (new \ReflectionProperty($entite, 'id'))->setValue($entite, 42);
        return (new EntiteSubscription())->setEntite($entite)->setStatus('active')->setStripeCustomerId('cus_fixture')->setStripeSubscriptionId('sub_fixture');
    }

    private function responses(): array
    {
        return [
            '/v1/subscriptions/sub_fixture' => ['id' => 'sub_fixture', 'object' => 'subscription', 'livemode' => true,
                'status' => 'active', 'customer' => 'cus_fixture', 'currency' => 'eur', 'collection_method' => 'charge_automatically',
                'pause_collection' => null, 'current_period_end' => time() + 3600, 'automatic_tax' => ['enabled' => true],
                'default_payment_method' => 'pm_subscription', 'default_source' => null],
            '/v1/customers/cus_fixture' => ['id' => 'cus_fixture', 'object' => 'customer', 'livemode' => true,
                'currency' => 'eur', 'metadata' => ['entite_id' => '42'], 'invoice_settings' => ['default_payment_method' => 'pm_customer'], 'default_source' => null],
            '/v1/payment_methods/pm_subscription' => ['id' => 'pm_subscription', 'object' => 'payment_method', 'livemode' => true,
                'customer' => 'cus_fixture', 'type' => 'card', 'card' => ['exp_month' => 12, 'exp_year' => (int) gmdate('Y') + 2]],
            '/v1/payment_methods/pm_customer' => ['id' => 'pm_customer', 'object' => 'payment_method', 'livemode' => true,
                'customer' => 'cus_fixture', 'type' => 'card', 'card' => ['exp_month' => 12, 'exp_year' => (int) gmdate('Y') + 2]],
        ];
    }

    private function mock(array $responses, ?\Throwable $failure = null): void
    {
        $http = $this->createMock(ClientInterface::class);
        $http->method('request')->willReturnCallback(function ($method, $url, $headers, $params) use ($responses, $failure) {
            $path = parse_url($url, PHP_URL_PATH);
            $this->requests[] = [$method, $path, $params];
            if ($failure) throw $failure;
            if (!array_key_exists($path, $responses)) throw new \RuntimeException('Unexpected test request');
            return [json_encode($responses[$path], JSON_THROW_ON_ERROR), 200, []];
        });
        ApiRequestor::setHttpClient($http);
    }

    private function check(): void
    {
        (new StripeSubscriptionPaymentReadiness('sk_live_fixture'))->assertReady($this->local());
    }

    public function testActiveCardUsesSubscriptionDefaultAndMakesOnlyThreeReads(): void
    {
        $this->mock($this->responses());
        $this->check();
        self::assertSame(['/v1/subscriptions/sub_fixture', '/v1/customers/cus_fixture', '/v1/payment_methods/pm_subscription'], array_column($this->requests, 1));
    }

    public function testCustomerDefaultSupportsExpandedIdsAndCurrentMonthCard(): void
    {
        $responses = $this->responses();
        $responses['/v1/subscriptions/sub_fixture']['default_payment_method'] = null;
        $responses['/v1/subscriptions/sub_fixture']['customer'] = ['id' => 'cus_fixture'];
        $responses['/v1/customers/cus_fixture']['invoice_settings']['default_payment_method'] = ['id' => 'pm_customer'];
        $responses['/v1/payment_methods/pm_customer']['customer'] = ['id' => 'cus_fixture'];
        $responses['/v1/payment_methods/pm_customer']['card'] = ['exp_month' => (int) gmdate('m'), 'exp_year' => (int) gmdate('Y')];
        $this->mock($responses);
        $this->check();
        self::assertSame('/v1/payment_methods/pm_customer', $this->requests[2][1]);
    }

    public function testTrialWithPaymentAndNewStripeItemPeriodsIsAllowed(): void
    {
        $responses = $this->responses();
        $sub = &$responses['/v1/subscriptions/sub_fixture'];
        $sub['status'] = 'trialing'; $sub['trial_end'] = time() + 3600;
        unset($sub['current_period_end']);
        $sub['items']['data'] = [['current_period_end' => time() + 3600]];
        $sub['cancel_at_period_end'] = true; $sub['cancel_at'] = time() + 3600; $sub['canceled_at'] = time() - 3600;
        $this->mock($responses); $this->check();
        self::assertCount(3, $this->requests);
    }

    #[DataProvider('unsafeSubscriptions')]
    public function testUnsafeSubscriptionStopsBeforeCustomerOrPaymentRead(array $override, string $message): void
    {
        $responses = $this->responses();
        $responses['/v1/subscriptions/sub_fixture'] = array_replace($responses['/v1/subscriptions/sub_fixture'], $override);
        $this->mock($responses);
        try { $this->check(); self::fail('Unsafe subscription accepted'); }
        catch (\DomainException $error) { self::assertStringContainsString($message, $error->getMessage()); }
        self::assertCount(1, $this->requests);
    }

    public static function unsafeSubscriptions(): iterable
    {
        foreach (['canceled', 'past_due', 'unpaid', 'incomplete', 'paused', 'incomplete_expired'] as $status) yield $status => [['status' => $status], 'actif'];
        yield 'test mode' => [['livemode' => false], 'production'];
        yield 'wrong customer' => [['customer' => 'cus_foreign'], 'rattaché'];
        yield 'wrong subscription' => [['id' => 'sub_foreign'], 'rattaché'];
        yield 'foreign currency' => [['currency' => 'usd'], 'euros'];
        yield 'manual collection' => [['collection_method' => 'send_invoice'], 'paiement automatique'];
        yield 'paused collection' => [['pause_collection' => ['behavior' => 'void']], 'prélèvements'];
        yield 'ended' => [['ended_at' => 1], 'pris fin'];
        yield 'effective cancellation' => [['cancel_at' => 1], 'pris fin'];
        yield 'expired period' => [['current_period_end' => 1], 'période'];
        yield 'missing period' => [['current_period_end' => null], 'période'];
        yield 'expired trial' => [['status' => 'trialing', 'trial_end' => 1], 'essai'];
        yield 'unverified trial' => [['status' => 'trialing'], 'essai'];
        yield 'tax missing' => [['automatic_tax' => ['enabled' => false], 'default_tax_rates' => []], 'TVA'];
        yield 'tax reference only' => [['automatic_tax' => ['enabled' => false], 'default_tax_rates' => ['txr_fixture']], 'TVA'];
        yield 'inclusive tax' => [['automatic_tax' => ['enabled' => false], 'default_tax_rates' => [['id' => 'txr_fixture', 'object' => 'tax_rate', 'livemode' => true, 'inclusive' => true, 'percentage' => 20]]], 'hors taxes'];
    }

    public function testExplicitExclusiveTaxRemainsValidWhenArchivedByStripe(): void
    {
        $responses = $this->responses();
        $responses['/v1/subscriptions/sub_fixture']['automatic_tax']['enabled'] = false;
        $responses['/v1/subscriptions/sub_fixture']['default_tax_rates'] = [['id' => 'txr_fixture', 'object' => 'tax_rate', 'livemode' => true, 'inclusive' => false, 'active' => false, 'percentage' => 20]];
        $this->mock($responses); $this->check(); self::assertCount(3, $this->requests);
    }

    #[DataProvider('unsafeCustomers')]
    public function testCustomerMustBeLivePresentAndTaxReady(array $override, string $message): void
    {
        $responses = $this->responses();
        $responses['/v1/customers/cus_fixture'] = array_replace($responses['/v1/customers/cus_fixture'], $override);
        $this->mock($responses);
        try { $this->check(); self::fail('Unsafe customer accepted'); }
        catch (\DomainException $error) { self::assertStringContainsString($message, $error->getMessage()); }
        self::assertCount(2, $this->requests);
    }

    public static function unsafeCustomers(): iterable
    {
        yield 'deleted' => [['deleted' => true], 'client Stripe'];
        yield 'test' => [['livemode' => false], 'client Stripe'];
        yield 'foreign' => [['id' => 'cus_foreign'], 'client Stripe'];
        yield 'currency' => [['currency' => 'usd'], 'client Stripe'];
        yield 'tax location missing' => [['tax' => ['automatic_tax' => 'requires_location_inputs']], 'adresse'];
        yield 'tenant missing from metadata' => [['metadata' => []], 'rattachement'];
        yield 'other tenant metadata' => [['metadata' => ['entite_id' => '99']], 'rattachement'];
        yield 'malformed tenant metadata' => [['metadata' => ['entite_id' => '42other']], 'rattachement'];
    }

    #[DataProvider('unsafePaymentMethods')]
    public function testUnusablePaymentMethodIsRefused(array $override, string $message): void
    {
        $responses = $this->responses();
        $responses['/v1/payment_methods/pm_subscription'] = array_replace($responses['/v1/payment_methods/pm_subscription'], $override);
        $this->mock($responses);
        try { $this->check(); self::fail('Unsafe payment method accepted'); }
        catch (\DomainException $error) { self::assertStringContainsString($message, $error->getMessage()); }
        self::assertCount(3, $this->requests);
    }

    public static function unsafePaymentMethods(): iterable
    {
        yield 'detached' => [['customer' => null], 'rattaché'];
        yield 'other customer' => [['customer' => 'cus_other'], 'rattaché'];
        yield 'wrong instrument' => [['id' => 'pm_other'], 'rattaché'];
        yield 'test instrument' => [['livemode' => false], 'rattaché'];
        yield 'expired card' => [['card' => ['exp_month' => 12, 'exp_year' => 2020]], 'expirée'];
        yield 'invalid month' => [['card' => ['exp_month' => 13, 'exp_year' => 2099]], 'invalide'];
        yield 'missing expiry' => [['card' => []], 'invalide'];
        yield 'cash balance' => [['type' => 'customer_balance'], 'carte bancaire'];
    }

    public function testNoPaymentMethodEvenDuringTrialIsRejected(): void
    {
        $responses = $this->responses();
        $responses['/v1/subscriptions/sub_fixture']['status'] = 'trialing';
        $responses['/v1/subscriptions/sub_fixture']['trial_end'] = time() + 3600;
        $responses['/v1/subscriptions/sub_fixture']['default_payment_method'] = null;
        $responses['/v1/customers/cus_fixture']['invoice_settings']['default_payment_method'] = null;
        $this->mock($responses); $this->expectExceptionMessage('Ajoutez un moyen de paiement'); $this->check();
    }

    public function testLegacySourceCannotBeBypassedByCustomerPaymentMethod(): void
    {
        $responses = $this->responses();
        $responses['/v1/subscriptions/sub_fixture']['default_payment_method'] = null;
        $responses['/v1/subscriptions/sub_fixture']['default_source'] = 'card_legacy';
        $this->mock($responses); $this->expectExceptionMessage('ancien moyen de paiement'); $this->check();
    }

    public function testExplicitPaymentTypeRestrictionIsRespected(): void
    {
        $responses = $this->responses();
        $responses['/v1/subscriptions/sub_fixture']['payment_settings']['payment_method_types'] = ['sepa_debit'];
        $this->mock($responses); $this->expectExceptionMessage('Autorisez le type'); $this->check();
    }

    public function testPendingAuthenticationBlocksUntilConfirmed(): void
    {
        $responses = $this->responses();
        $responses['/v1/subscriptions/sub_fixture']['pending_setup_intent'] = 'seti_pending';
        $responses['/v1/setup_intents/seti_pending'] = ['id' => 'seti_pending', 'object' => 'setup_intent', 'livemode' => true, 'status' => 'requires_action', 'customer' => 'cus_fixture', 'payment_method' => 'pm_subscription'];
        $this->mock($responses); $this->expectExceptionMessage('Terminez la confirmation'); $this->check();
    }

    private function sepaResponses(): array
    {
        $responses = $this->responses();
        $responses['/v1/payment_methods/pm_subscription']['type'] = 'sepa_debit';
        $responses['/v1/setup_intents'] = ['object' => 'list', 'data' => [['object' => 'setup_intent', 'id' => 'seti_fixture', 'customer' => 'cus_fixture', 'payment_method' => 'pm_subscription', 'livemode' => true, 'status' => 'succeeded', 'usage' => 'off_session', 'mandate' => 'mandate_fixture']], 'has_more' => false];
        $responses['/v1/mandates/mandate_fixture'] = ['id' => 'mandate_fixture', 'object' => 'mandate', 'livemode' => true, 'status' => 'active', 'type' => 'multi_use', 'payment_method' => 'pm_subscription', 'on_behalf_of' => null, 'payment_method_details' => ['type' => 'sepa_debit']];
        $responses['/v1/charges'] = ['object' => 'list', 'data' => [], 'has_more' => false];
        return $responses;
    }

    public function testSepaRequiresActiveReusableMandateForTheExactPaymentMethod(): void
    {
        $this->mock($this->sepaResponses()); $this->check();
        self::assertCount(5, $this->requests);
        self::assertSame(['customer' => 'cus_fixture', 'payment_method' => 'pm_subscription', 'limit' => 10], $this->requests[3][2]);
    }

    public function testSepaSavedByPaymentCanUseItsVerifiedMandate(): void
    {
        $responses = $this->sepaResponses(); $responses['/v1/setup_intents']['data'] = [];
        $responses['/v1/charges']['data'] = [['object' => 'charge', 'id' => 'ch_fixture', 'livemode' => true, 'status' => 'succeeded', 'customer' => 'cus_fixture', 'payment_method' => 'pm_subscription', 'payment_method_details' => ['sepa_debit' => ['mandate' => 'mandate_fixture']]]];
        $this->mock($responses); $this->check(); self::assertCount(6, $this->requests);
    }

    #[DataProvider('unsafeMandates')]
    public function testSepaAttachmentDoesNotReplaceMandateVerification(array $override): void
    {
        $responses = $this->sepaResponses();
        $responses['/v1/mandates/mandate_fixture'] = array_replace($responses['/v1/mandates/mandate_fixture'], $override);
        $this->mock($responses); $this->expectExceptionMessage('mandat SEPA actif'); $this->check();
    }

    public static function unsafeMandates(): iterable
    {
        yield 'revoked' => [['status' => 'inactive']];
        yield 'single use' => [['type' => 'single_use']];
        yield 'other payment' => [['payment_method' => 'pm_foreign']];
        yield 'other merchant' => [['on_behalf_of' => 'acct_foreign']];
        yield 'test mandate' => [['livemode' => false]];
    }

    #[DataProvider('invalidConfiguration')]
    public function testInvalidKeyOrIdentifiersDoesNotMakeAnyRequest(string $key, string $customer, string $subscription): void
    {
        $http = $this->createMock(ClientInterface::class); $http->expects(self::never())->method('request'); ApiRequestor::setHttpClient($http);
        $local = $this->local()->setStripeCustomerId($customer)->setStripeSubscriptionId($subscription);
        $this->expectException(\DomainException::class);
        (new StripeSubscriptionPaymentReadiness($key))->assertReady($local);
    }

    public static function invalidConfiguration(): iterable
    {
        yield 'empty key' => ['', 'cus_fixture', 'sub_fixture'];
        yield 'test key' => ['sk_test_fixture', 'cus_fixture', 'sub_fixture'];
        yield 'publishable key' => ['pk_live_fixture', 'cus_fixture', 'sub_fixture'];
        yield 'customer absent' => ['sk_live_fixture', '', 'sub_fixture'];
        yield 'subscription invalid' => ['sk_live_fixture', 'cus_fixture', '../../foreign'];
    }

    public function testTransportErrorIsSanitizedAndDoesNotChainTheOriginalException(): void
    {
        $this->mock([], new \RuntimeException('secret sk_live_something personal@example.test API diagnostic'));
        try { $this->check(); self::fail('Stripe failure must close the gate'); }
        catch (\DomainException $error) {
            self::assertStringContainsString('momentanément indisponible', $error->getMessage());
            self::assertStringNotContainsString('secret', $error->getMessage());
            self::assertStringNotContainsString('personal', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
        self::assertCount(1, $this->requests);
    }

    public function testPaymentRemovalAfterPreviousSuccessfulCheckIsNotCached(): void
    {
        $this->mock($this->responses()); $this->check();
        $responses = $this->responses(); $responses['/v1/payment_methods/pm_subscription']['customer'] = null;
        $this->mock($responses);
        try { $this->check(); self::fail('Detached method cannot use earlier readiness'); }
        catch (\DomainException $error) { self::assertStringContainsString('rattaché', $error->getMessage()); }
        self::assertCount(6, $this->requests);
    }

    public function testUnpersistedTenantCannotUseAnOtherwiseValidSubscription(): void
    {
        $http = $this->createMock(ClientInterface::class); $http->expects(self::never())->method('request'); ApiRequestor::setHttpClient($http);
        $this->expectExceptionMessage('organisme enregistré');
        (new StripeSubscriptionPaymentReadiness('sk_live_fixture'))->assertReady($this->local()->setEntite(new Entite()));
    }

    #[DataProvider('curlBudgets')]
    public function testCurlReadLimitsAreScopedAndRestoredIncludingAfterFailure(int $timeout, int $connectTimeout, bool $fail, array $expected): void
    {
        $http = $this->getMockBuilder(CurlClient::class)->onlyMethods(['request'])->getMock();
        $http->setTimeout($timeout); $http->setConnectTimeout($connectTimeout);
        $observed = []; $responses = $this->responses();
        $http->method('request')->willReturnCallback(function ($method, $url, $headers, $params) use ($http, &$observed, $responses, $fail) {
            $path = parse_url($url, PHP_URL_PATH); $this->requests[] = [$method, $path, $params];
            $observed[] = [$http->getTimeout(), $http->getConnectTimeout()];
            if ($fail) throw new \RuntimeException('Simulated network failure; no request was sent.');
            return [json_encode($responses[$path], JSON_THROW_ON_ERROR), 200, []];
        });
        ApiRequestor::setHttpClient($http);
        try {
            $this->check();
            self::assertFalse($fail, 'The failed transport must close the gate.');
        } catch (\DomainException $error) {
            self::assertTrue($fail);
            self::assertStringContainsString('momentanément indisponible', $error->getMessage());
        }
        self::assertCount($fail ? 1 : 3, $observed);
        foreach ($observed as $limits) self::assertSame($expected, $limits);
        self::assertSame($timeout, $http->getTimeout());
        self::assertSame($connectTimeout, $http->getConnectTimeout());
        self::assertSame($http, ApiRequestor::httpClient(), 'The shared transport must not be replaced.');
    }

    public static function curlBudgets(): iterable
    {
        yield 'SDK defaults restored on success' => [80, 30, false, [10, 3]];
        yield 'SDK defaults restored on failure' => [80, 30, true, [10, 3]];
        yield 'stricter existing limits preserved' => [2, 1, false, [2, 1]];
        yield 'unbounded transport capped and restored' => [0, 0, false, [10, 3]];
    }
}
