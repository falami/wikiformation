<?php

namespace App\Tests\Service\Billing;

use App\Controller\Billing\StripeWebhookController;
use App\Repository\Billing\{EntiteSubscriptionRepository, PlanRepository};
use App\Service\Billing\StripeBillingService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Stripe\{ApiRequestor, HttpClient\ClientInterface, HttpClient\CurlClient};
use Symfony\Component\HttpFoundation\Request;

final class StripeWebhookTest extends TestCase
{
    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(new CurlClient());
    }

    private function deliver(array $event, bool $validSignature = true): int
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $time = time();
        $signature = hash_hmac('sha256', $time.'.'.$body, $validSignature ? 'fixture_secret' : 'wrong');
        $request = Request::create('/fr/stripe/webhook', 'POST', server: [
            'HTTP_STRIPE_SIGNATURE' => 't='.$time.',v1='.$signature,
        ], content: $body);
        $registry = $this->createMock(ManagerRegistry::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        return (new StripeWebhookController())->webhook(
            $request,
            new StripeBillingService('sk_test_fixture', 'https://example.test/fr'),
            new EntiteSubscriptionRepository($registry),
            new PlanRepository($registry),
            $em,
            'fixture_secret',
        )->getStatusCode();
    }

    public function testFailedSynchronizationReturnsErrorSoStripeCanRetry(): void
    {
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::once())->method('request')->willThrowException(new \RuntimeException('Simulated provider outage'));
        ApiRequestor::setHttpClient($http);
        self::assertSame(500, $this->deliver([
            'id' => 'evt_fixture', 'object' => 'event', 'type' => 'checkout.session.completed',
            'data' => ['object' => ['mode' => 'subscription', 'customer' => 'cus_fixture', 'subscription' => 'sub_fixture']],
        ]));
    }

    public function testInvalidSignatureIsRejectedWithoutNetworkRequest(): void
    {
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::never())->method('request');
        ApiRequestor::setHttpClient($http);
        self::assertSame(400, $this->deliver(['id' => 'evt_fixture', 'object' => 'event', 'type' => 'checkout.session.completed'], false));
    }

    public function testUnrelatedEventIsAcknowledgedWithoutNetworkRequest(): void
    {
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::never())->method('request');
        ApiRequestor::setHttpClient($http);
        self::assertSame(200, $this->deliver(['id' => 'evt_fixture', 'object' => 'event', 'type' => 'customer.created']));
    }
}
