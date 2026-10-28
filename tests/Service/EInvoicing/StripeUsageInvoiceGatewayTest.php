<?php
declare(strict_types=1);
namespace App\Tests\Service\EInvoicing;

use App\Entity\{Entite, Facture, EInvoiceConnection, EInvoiceTransmission, EInvoiceUsage, EInvoiceUsageBatch};
use App\Service\EInvoicing\StripeUsageInvoiceGateway;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stripe\{ApiRequestor, HttpClient\ClientInterface, HttpClient\CurlClient};

final class StripeUsageInvoiceGatewayTest extends TestCase
{
    protected function tearDown(): void { ApiRequestor::setHttpClient(new CurlClient()); }
    private function batch(): EInvoiceUsageBatch
    {
        $entity = new Entite(); (new \ReflectionProperty($entity, 'id'))->setValue($entity, 1);
        $connection = new EInvoiceConnection($entity, 'production'); (new \ReflectionProperty($connection, 'id'))->setValue($connection, 1);
        $f = (new Facture())->setEntite($entity); (new \ReflectionProperty($f, 'id'))->setValue($f, 1);
        $t = new EInvoiceTransmission($connection, $f);
        $t->captureUsageTerms(['unitPriceCents'=>2, 'customer'=>'cus_fixture', 'subscription'=>'sub_fixture', 'consentedAt'=>'2020-09-01T12:00:00+02:00']);
        $t->prepared('<Invoice/>'); $t->begin(); $t->accepted('100');
        (new \ReflectionProperty($t, 'acceptedAt'))->setValue($t, new \DateTimeImmutable('2020-09-20T12:00:00+02:00'));
        $usage = new EInvoiceUsage($t); (new \ReflectionProperty($usage, 'id'))->setValue($usage, 1);
        return new EInvoiceUsageBatch([$usage]);
    }
    private function response(EInvoiceUsageBatch $b): array
    {
        return ['id'=>'ii_fixture', 'object'=>'invoiceitem', 'livemode'=>true, 'amount'=>2, 'currency'=>'eur', 'customer'=>'cus_fixture',
            'parent'=>['type'=>'subscription_details', 'subscription_details'=>['subscription'=>'sub_fixture']], 'metadata'=>$b->getPayload()['metadata']];
    }
    public function testCreateUsesSubscriptionAndStableIdempotencyWithExclusiveTax(): void
    {
        $batch = $this->batch(); $http = $this->createMock(ClientInterface::class);
        $http->expects(self::once())->method('request')->willReturnCallback(function ($method, $url, $headers, $params) use ($batch) {
            self::assertSame('post', $method); self::assertStringEndsWith('/v1/invoiceitems', $url);
            self::assertSame('sub_fixture', $params['subscription']); self::assertSame('2', $params['unit_amount_decimal']); self::assertSame(1, $params['quantity']);
            self::assertSame('exclusive', $params['tax_behavior']); self::assertSame('false', $params['discountable']); self::assertArrayNotHasKey('invoice', $params);
            self::assertStringContainsString('Idempotency-Key: '.$batch->getRequestKey(), implode("\n", $headers));
            return [json_encode($this->response($batch), JSON_THROW_ON_ERROR), 200, []];
        });
        ApiRequestor::setHttpClient($http);
        self::assertSame('ii_fixture', (new StripeUsageInvoiceGateway('sk_test_fixture'))->create($batch));
    }
    public function testSubscriptionMismatchOrUnconfiguredTaxPreventsCharge(): void
    {
        $batch = $this->batch();
        foreach ([['customer'=>'cus_foreign', 'automatic_tax'=>['enabled'=>true]], ['customer'=>'cus_fixture','automatic_tax'=>['enabled'=>false], 'default_tax_rates'=>[]]] as $override) {
            $http = $this->createMock(ClientInterface::class);
            $http->expects(self::once())->method('request')->willReturnCallback(function ($method, $url) use ($override) {
                self::assertSame('get', $method); self::assertStringEndsWith('/v1/subscriptions/sub_fixture', $url);
                return [json_encode($override+['id'=>'sub_fixture','object'=>'subscription','status'=>'active','currency'=>'eur','livemode'=>true], JSON_THROW_ON_ERROR),200,[]];
            });
            ApiRequestor::setHttpClient($http);
            try { (new StripeUsageInvoiceGateway('sk_test_fixture'))->validate($batch); self::fail('Unsafe subscription'); } catch (\DomainException) { self::assertTrue(true); }
        }
    }
    public function testReconciliationOnlyReadsBothPendingAndInvoicedItems(): void
    {
        $batch = $this->batch(); $batch->begin(); $batch->uncertain();
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::once())->method('request')->willReturnCallback(function ($method, $url, $headers, $params) use ($batch) {
            self::assertSame('get', $method); self::assertStringEndsWith('/v1/invoiceitems', $url); self::assertSame('cus_fixture', $params['customer']);
            self::assertArrayNotHasKey('pending', $params); self::assertArrayHasKey('created', $params);
            return [json_encode(['object'=>'list','url'=>'/v1/invoiceitems','has_more'=>false,'data'=>[$this->response($batch)]], JSON_THROW_ON_ERROR),200,[]];
        });
        ApiRequestor::setHttpClient($http);
        self::assertSame('ii_fixture', (new StripeUsageInvoiceGateway('sk_test_fixture'))->find($batch));
    }
    public function testWrongRemoteAmountIsNotAcknowledged(): void
    {
        $batch = $this->batch(); $response = $this->response($batch); $response['amount'] = 200;
        $http = $this->createMock(ClientInterface::class); $http->expects(self::once())->method('request')->willReturn([json_encode($response),200,[]]); ApiRequestor::setHttpClient($http);
        $this->expectException(\UnexpectedValueException::class); (new StripeUsageInvoiceGateway('sk_test_fixture'))->create($batch);
    }

    #[DataProvider('invalidRemoteAmounts')]
    public function testMalformedAmountsNeverAcknowledgeCreationOrReconciliation(mixed $amount, bool $reconcile): void
    {
        $batch = $this->batch();
        if ($reconcile) { $batch->begin(); $batch->uncertain(); }
        $response = $this->response($batch);
        if ($amount === null) unset($response['amount']);
        else $response['amount'] = $amount;
        $payload = $reconcile ? ['object'=>'list','url'=>'/v1/invoiceitems','has_more'=>false,'data'=>[$response]] : $response;
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::once())->method('request')->willReturnCallback(function ($method) use ($reconcile, $payload) {
            self::assertSame($reconcile ? 'get' : 'post', $method);
            return [json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), 200, []];
        });
        ApiRequestor::setHttpClient($http);
        $this->expectException(\UnexpectedValueException::class);
        $gateway = new StripeUsageInvoiceGateway('sk_test_fixture');
        if ($reconcile) $gateway->find($batch);
        else $gateway->create($batch);
    }

    public static function invalidRemoteAmounts(): iterable
    {
        foreach (['missing'=>null, 'string'=>'2', 'float'=>2.5, 'whole float'=>2.0, 'boolean'=>true, 'array'=>[2]] as $name => $amount) {
            yield $name.' create' => [$amount, false];
            yield $name.' reconcile' => [$amount, true];
        }
    }
}
