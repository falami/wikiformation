<?php

namespace App\Tests\Service\Billing;

use App\Service\Billing\StripeBillingService;
use PHPUnit\Framework\TestCase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;

final class StripeChangeClockTest extends TestCase
{
    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(new CurlClient());
    }

    public function testPreviewAndApplyUseStripeTimeInsteadOfApplicationTime(): void
    {
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::exactly(2))->method('request')->willReturnCallback(
            function ($method, $url, $headers, $params): array {
                self::assertSame('post', $method);
                if (str_ends_with($url, '/invoices/create_preview')) {
                    self::assertSame('sub_clock', $params['subscription']);
                    $details = $params['subscription_details'];
                    self::assertArrayNotHasKey('proration_date', $details);
                    self::assertSame('unchanged', $details['billing_cycle_anchor']);
                    $object = ['id' => 'upcoming', 'object' => 'invoice'];
                } else {
                    self::assertStringEndsWith('/subscriptions/sub_clock', $url);
                    $details = $params;
                    self::assertArrayNotHasKey('proration_date', $details);
                    $object = ['id' => 'sub_clock', 'object' => 'subscription'];
                }
                self::assertSame('create_prorations', $details['proration_behavior']);
                self::assertSame([['id' => 'si_clock', 'price' => 'price_pro']], $details['items']);
                return [json_encode($object, JSON_THROW_ON_ERROR), 200, []];
            }
        );
        ApiRequestor::setHttpClient($http);
        $service = new StripeBillingService('sk_test_fixture', 'http://localhost');
        $service->previewSubscriptionChange('cus_clock', 'sub_clock', 'si_clock', 'price_pro');
        $service->applySubscriptionChangeNow('sub_clock', 'si_clock', 'price_pro');
    }
}
