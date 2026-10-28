<?php
declare(strict_types=1);
namespace App\Tests\Service\Billing;

use App\Service\Billing\StripeBillingService;
use PHPUnit\Framework\TestCase;
use Stripe\{ApiRequestor, HttpClient\ClientInterface, HttpClient\CurlClient};

final class InternalUsagePortalTest extends TestCase
{
    protected function tearDown(): void { ApiRequestor::setHttpClient(new CurlClient()); }

    public function testInternalSessionUsesVerifiedDedicatedReadOnlySubscriptionConfiguration(): void
    {
        $this->portal(false);
    }

    public function testReconfiguredPortalFailsBeforeCreatingASession(): void
    {
        $this->expectException(\DomainException::class);
        $this->portal(true);
    }

    private function portal(bool $unsafe): void
    {
        $config = ['id' => 'bpc_internal', 'object' => 'billing_portal.configuration', 'active' => true,
            'is_default' => false, 'livemode' => true, 'metadata' => ['wikiformation_policy' => 'internal-usage-payments-only-v1'],
            'login_page' => ['enabled' => false]];
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::exactly($unsafe ? 2 : 3))->method('request')->willReturnCallback(function ($method, $url, $headers, $params) use (&$config, $unsafe): array {
            if (str_ends_with($url, '/billing_portal/configurations')) {
                self::assertSame('post', strtolower($method));
                self::assertSame('false', $params['features']['subscription_update']['enabled']);
                self::assertSame('false', $params['features']['subscription_cancel']['enabled']);
                self::assertSame('true', $params['features']['payment_method_update']['enabled']);
                self::assertSame('true', $params['features']['invoice_history']['enabled']);
                self::assertArrayNotHasKey('is_default', $params);
                $config['features'] = array_map(static fn(array $feature) => ['enabled' => $feature['enabled'] === 'true'], $params['features']);
                $result = $config;
            } elseif (str_ends_with($url, '/billing_portal/configurations/bpc_internal')) {
                self::assertSame('get', strtolower($method));
                $result = $config;
                if ($unsafe) $result['features']['subscription_update']['enabled'] = true;
            } else {
                self::assertStringEndsWith('/billing_portal/sessions', $url);
                self::assertSame('bpc_internal', $params['configuration']);
                self::assertSame('cus_internal', $params['customer']);
                $result = ['id' => 'bps_internal', 'object' => 'billing_portal.session', 'livemode' => true,
                    'customer' => 'cus_internal', 'configuration' => 'bpc_internal', 'url' => 'https://billing.stripe.com/p/session/test'];
            }
            return [json_encode($result, JSON_THROW_ON_ERROR), 200, []];
        });
        ApiRequestor::setHttpClient($http);
        $service = new StripeBillingService('sk_live_fixture', 'https://wiki.test');
        self::assertSame('https://billing.stripe.com/p/session/test', $service->createCustomerPortalSession('cus_internal', 'https://wiki.test/return', true));
    }

    public function testOrdinarySubscriptionsKeepTheirExistingPortalConfiguration(): void
    {
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::once())->method('request')->willReturnCallback(function ($method, $url, $headers, $params): array {
            self::assertStringEndsWith('/billing_portal/sessions', $url);
            self::assertArrayNotHasKey('configuration', $params);
            return [json_encode(['id' => 'bps_public', 'object' => 'billing_portal.session', 'url' => 'https://billing.stripe.com/p/session/public']), 200, []];
        });
        ApiRequestor::setHttpClient($http);
        self::assertSame('https://billing.stripe.com/p/session/public', (new StripeBillingService('sk_test_fixture', 'https://wiki.test'))->createCustomerPortalSession('cus_public', 'https://wiki.test'));
    }
}
