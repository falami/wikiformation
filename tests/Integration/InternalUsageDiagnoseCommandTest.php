<?php
declare(strict_types=1);

namespace App\Tests\Integration;

use App\Command\InternalUsageDiagnoseCommand;
use App\Entity\Entite;
use App\Entity\Billing\{EntiteSubscription, InternalUsageSetup};
use Doctrine\ORM\{EntityManagerInterface, EntityRepository};
use PHPUnit\Framework\TestCase;
use Stripe\{ApiRequestor, HttpClient\ClientInterface, HttpClient\CurlClient};
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class InternalUsageDiagnoseCommandTest extends TestCase
{
    private InternalUsageSetup $setup;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $entite = new Entite();
        (new \ReflectionProperty(Entite::class, 'id'))->setValue($entite, 1);
        $subscription = (new EntiteSubscription())->setEntite($entite)->setStripeCustomerId('cus_fixture');
        (new \ReflectionProperty(EntiteSubscription::class, 'id'))->setValue($subscription, 12);
        $this->setup = new InternalUsageSetup();
        $this->setup->entite = $entite;
        $this->setup->subscription = $subscription;
        $this->setup->createdAt = new \DateTimeImmutable('-1 day');
        $this->setup->attemptedAt = $this->setup->createdAt;
        $this->setup->customerId = 'cus_fixture';
        $this->setup->request = ['customer' => ['email' => 'private@example.test'], 'checkout' => ['expires_at' => time() - 3600]];
        $this->setup->acceptance = ['private' => 'PRIVATE_CONSENT'];
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->method('find')->with(Entite::class, 1)->willReturn($entite);
        $setupRepository = $this->createMock(EntityRepository::class);
        $setupRepository->method('findOneBy')->willReturn($this->setup);
        $subscriptionRepository = $this->createMock(EntityRepository::class);
        $subscriptionRepository->method('findOneBy')->willReturn($subscription);
        $this->em->method('getRepository')->willReturnCallback(fn(string $class) => $class === InternalUsageSetup::class ? $setupRepository : $subscriptionRepository);
        foreach (['persist', 'flush', 'remove'] as $method) $this->em->expects(self::never())->method($method);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(new CurlClient());
    }

    private function executeCommand(array $arguments = [], string $key = 'sk_live_fixture'): CommandTester
    {
        $tester = new CommandTester(new InternalUsageDiagnoseCommand($this->em, $key));
        $tester->execute($arguments + ['entite' => '1']);
        $text = $tester->getDisplay(true);
        self::assertStringNotContainsString('private@example.test', $text);
        self::assertStringNotContainsString('PRIVATE_CONSENT', $text);
        self::assertStringNotContainsString($key, $text);
        return $tester;
    }

    private function noNetwork(): void
    {
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::never())->method('request');
        ApiRequestor::setHttpClient($http);
    }

    private function customer(): array
    {
        return ['id' => 'cus_fixture', 'object' => 'customer', 'livemode' => true, 'metadata' => ['entite_id' => '1']];
    }

    private function metadata(): array
    {
        return ['internal_usage_setup' => $this->setup->id, 'entite_id' => '1', 'local_subscription_id' => '12'];
    }

    private function response(array $body): array
    {
        return [json_encode($body, JSON_THROW_ON_ERROR), 200, []];
    }

    public function testLocalOnlyExplainsExpiredReservationWithoutNetworkOrWrites(): void
    {
        $this->noNetwork();
        $before = serialize($this->setup);
        $tester = $this->executeCommand(['--local-only' => true]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString($this->setup->id, $tester->getDisplay());
        self::assertStringContainsString('(expiré)', $tester->getDisplay());
        self::assertStringContainsString('aucun appel Stripe', $tester->getDisplay());
        self::assertSame($before, serialize($this->setup));
    }

    public function testUnknownCustomerNeverInfersAbsenceOrCreatesRemoteObjects(): void
    {
        $this->setup->customerId = null;
        $this->noNetwork();
        $tester = $this->executeCommand();
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertMatchesRegularExpression('/rapprochement\s+client requis/u', $tester->getDisplay());
        self::assertStringNotContainsString('Aucune session trouvée', $tester->getDisplay());
    }

    public function testEveryCheckoutAndSubscriptionPageIsReadWithoutMutation(): void
    {
        $before = serialize($this->setup);
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::exactly(5))->method('request')->willReturnCallback(function ($method, $url, $headers, $params) {
            self::assertSame('get', strtolower($method));
            if (str_ends_with($url, '/customers/cus_fixture')) return $this->response($this->customer());
            self::assertSame('cus_fixture', $params['customer']);
            self::assertSame(100, $params['limit']);
            if (str_ends_with($url, '/checkout/sessions')) {
                self::assertSame($this->setup->createdAt->getTimestamp() - 300, $params['created']['gte']);
                if (!isset($params['starting_after'])) return $this->response(['object' => 'list', 'has_more' => true, 'data' => [['id' => 'cs_unrelated', 'metadata' => ['internal_usage_setup' => 'other']]]]);
                self::assertSame('cs_unrelated', $params['starting_after']);
                return $this->response(['object' => 'list', 'has_more' => false, 'data' => [[
                    'id' => 'cs_found', 'status' => 'complete', 'livemode' => true, 'customer' => 'cus_fixture', 'subscription' => 'sub_found', 'metadata' => $this->metadata(),
                ]]]);
            }
            self::assertStringEndsWith('/subscriptions', $url);
            self::assertSame('all', $params['status']);
            if (!isset($params['starting_after'])) return $this->response(['object' => 'list', 'has_more' => true, 'data' => [['id' => 'sub_old', 'status' => 'canceled']]]);
            self::assertSame('sub_old', $params['starting_after']);
            return $this->response(['object' => 'list', 'has_more' => false, 'data' => [[
                'id' => 'sub_found', 'status' => 'active', 'livemode' => true, 'customer' => 'cus_fixture', 'metadata' => $this->metadata(),
            ]]]);
        });
        ApiRequestor::setHttpClient($http);
        $tester = $this->executeCommand();
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('cs_found', $tester->getDisplay());
        self::assertStringContainsString('sub_found', $tester->getDisplay());
        self::assertStringContainsString('sub_old', $tester->getDisplay());
        self::assertStringNotContainsString('cs_unrelated', $tester->getDisplay());
        self::assertSame($before, serialize($this->setup));
    }

    public function testStripeErrorsShowOnlySanitizedSupportIdentifiers(): void
    {
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::once())->method('request')->willReturn([
            json_encode(['error' => ['type' => 'invalid_request_error', 'code' => 'parameter_invalid_integer', 'param' => 'expires_at', 'message' => 'Secret sk_live_fixture private@example.test PRIVATE_CONSENT']]),
            400, ['Request-Id' => 'req_support123'],
        ]);
        ApiRequestor::setHttpClient($http);
        $tester = $this->executeCommand();
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('InvalidRequestException', $tester->getDisplay());
        self::assertStringContainsString('parameter_invalid_integer', $tester->getDisplay());
        self::assertStringContainsString('expires_at', $tester->getDisplay());
        self::assertStringContainsString('req_support123', $tester->getDisplay());
    }

    public function testForeignCustomerStopsRemoteInspection(): void
    {
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::once())->method('request')->willReturn($this->response(array_replace($this->customer(), ['metadata' => ['entite_id' => '2']])));
        ApiRequestor::setHttpClient($http);
        $tester = $this->executeCommand();
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Rapprochement client requis', $tester->getDisplay());
    }

    public function testUntrustedErrorFieldsCannotLeakSecretsOrContactDetails(): void
    {
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::once())->method('request')->willReturn([
            json_encode(['error' => ['type' => 'invalid_request_error', 'code' => 'sk_live_fixture', 'param' => 'private@example.test', 'message' => 'PRIVATE_CONSENT']]),
            400, ['Request-Id' => 'private@example.test'],
        ]);
        ApiRequestor::setHttpClient($http);
        $tester = $this->executeCommand();
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('code=masqué', $tester->getDisplay());
        self::assertStringContainsString('param=masqué', $tester->getDisplay());
        self::assertStringContainsString('request-id=absent', $tester->getDisplay());
    }

    public function testMalformedPaginationCannotBecomeANoResultsConclusion(): void
    {
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::exactly(2))->method('request')->willReturnCallback(function ($method, $url) {
            self::assertSame('get', strtolower($method));
            return $this->response(str_ends_with($url, '/customers/cus_fixture') ? $this->customer() : ['object' => 'list', 'has_more' => true, 'data' => []]);
        });
        ApiRequestor::setHttpClient($http);
        $tester = $this->executeCommand();
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Diagnostic incomplet', $tester->getDisplay());
        self::assertStringNotContainsString('Aucune session trouvée', $tester->getDisplay());
    }

    public function testInvalidEntityArgumentIsRejectedBeforeReads(): void
    {
        $this->noNetwork();
        $this->em->expects(self::never())->method('find');
        $tester = $this->executeCommand(['entite' => '1 OR 1=1']);
        self::assertSame(Command::INVALID, $tester->getStatusCode());
    }

    public function testRepeatedPaginationCursorFailsWithoutInferringRemoteAbsence(): void
    {
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::exactly(3))->method('request')->willReturnCallback(function ($method, $url) {
            self::assertSame('get', strtolower($method));
            return $this->response(str_ends_with($url, '/customers/cus_fixture') ? $this->customer() : [
                'object' => 'list', 'has_more' => true, 'data' => [['id' => 'cs_repeated']],
            ]);
        });
        ApiRequestor::setHttpClient($http);
        $tester = $this->executeCommand();
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Diagnostic incomplet', $tester->getDisplay());
        self::assertStringNotContainsString('Aucune session trouvée', $tester->getDisplay());
    }

    public function testTestKeyCannotReadLiveSetup(): void
    {
        $this->noNetwork();
        $tester = $this->executeCommand([], 'sk_test_fixture');
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('clé Stripe de production', $tester->getDisplay());
    }
}
