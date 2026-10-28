<?php
declare(strict_types=1);
namespace App\Tests\Integration;

use App\Entity\{Entite, Utilisateur, UtilisateurEntite};
use App\Entity\Billing\{EntiteSubscription, InternalUsageSetup, Plan};
use App\Service\Billing\InternalUsageCheckout;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Stripe\{ApiRequestor, HttpClient\ClientInterface, HttpClient\CurlClient};
use Symfony\Bundle\FrameworkBundle\{KernelBrowser, Test\KernelTestCase};

final class InternalUsageCheckoutTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private array $environment;
    private Entite $entite;
    private Utilisateur $user;
    private EntiteSubscription $sub;

    protected function setUp(): void
    {
        $this->environment = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->user = (new Utilisateur())->setEmail('internal@example.test')->setPassword('unused')->setPrenom('Owner')->setNom('Fixture');
        $this->entite = (new Entite())->setNom('I.T. For You')->setEmail('internal@example.test')->setSiret('80813612100023')->setCreateur($this->user)->setPublic(false);
        $plan = (new Plan())->setCode('INTERNAL_EXISTING')->setName('Existing internal rights')->setPriceMonthlyCents(15000)->setMaxApprenantsAn(0)->setMaxUtilisateurs(0);
        foreach ([$this->user, $this->entite, $plan] as $row) $this->em->persist($row);
        $this->em->flush();
        $this->user->setEntite($this->entite);
        $membership = (new UtilisateurEntite())->setEntite($this->entite)->setUtilisateur($this->user)->setCreateur($this->user)->setRoles([UtilisateurEntite::TENANT_ADMIN]);
        $this->user->addUtilisateurEntite($membership);
        $this->em->persist($membership);
        $this->sub = (new EntiteSubscription())->setEntite($this->entite)->setPlan($plan)->setStatus('active')->setAddons(['OLD_ADDON'])->setLimitsSnapshot(['max_users' => 0]);
        $this->em->persist($this->sub);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(new CurlClient());
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $i => $name) {
            if ($this->environment[$i] === null) unset($GLOBALS['_'.$name]['DATABASE_URL']);
            else $GLOBALS['_'.$name]['DATABASE_URL'] = $this->environment[$i];
        }
    }

    private function service(): InternalUsageCheckout { return new InternalUsageCheckout($this->em, 'sk_live_fixture'); }
    private function start(): string
    {
        return $this->service()->start($this->entite, $this->user, ['version' => 'fixture', 'html' => 'Terms', 'sha256' => hash('sha256', 'Terms')],
            'https://example.test/success?session_id={CHECKOUT_SESSION_ID}', 'https://example.test/cancel');
    }
    private function mockStripe(callable $checkout, ?array $customer = null, array $subscriptions = []): void
    {
        $http = $this->createMock(ClientInterface::class);
        $http->method('request')->willReturnCallback(function ($method, $url, $headers, $params) use ($checkout, $customer, $subscriptions) {
            if (str_ends_with($url, '/customers')) {
                self::assertSame('post', strtolower($method));
                // Reservation and consent exist before any remote side effect.
                self::assertSame(1, $this->em->getRepository(InternalUsageSetup::class)->count([]));
                return [json_encode($customer ?? ['id' => 'cus_internal', 'object' => 'customer', 'livemode' => true, 'balance' => 0, 'metadata' => ['entite_id' => '1']]), 200, []];
            }
            if (str_ends_with($url, '/customers/cus_internal')) return [json_encode($customer ?? ['id' => 'cus_internal', 'object' => 'customer', 'livemode' => true, 'balance' => 0, 'metadata' => ['entite_id' => '1']]), 200, []];
            if (str_ends_with($url, '/invoiceitems') || str_ends_with($url, '/invoices')) return [json_encode(['object' => 'list', 'data' => [], 'has_more' => false]), 200, []];
            if (str_ends_with($url, '/subscriptions')) return [json_encode(['object' => 'list', 'data' => $subscriptions, 'has_more' => false]), 200, []];
            self::assertStringEndsWith('/checkout/sessions', $url);
            return $checkout($params, $headers);
        });
        ApiRequestor::setHttpClient($http);
    }
    private static function sessionResponse(array $params): array
    {
        return [json_encode(['id' => 'cs_internal', 'object' => 'checkout.session', 'livemode' => true, 'mode' => 'subscription',
            'customer' => $params['customer'], 'metadata' => $params['metadata'], 'url' => 'https://checkout.stripe.com/c/pay/fixture']), 200, []];
    }

    public function testZeroBaseCheckoutPreservesTheExistingPlanAccessAndTrialHistory(): void
    {
        $before = [$this->sub->getPlan(), $this->sub->getStatus(), $this->sub->getCurrentPeriodEnd(), $this->sub->getTrialEndsAt(), $this->sub->getAddons(), $this->sub->getLimitsSnapshot()];
        $this->mockStripe(function ($params) {
            self::assertSame('always', $params['payment_method_collection']);
            self::assertSame(['card', 'sepa_debit'], $params['payment_method_types']);
            self::assertSame(0, $params['line_items'][0]['price_data']['unit_amount']);
            self::assertSame('eur', $params['line_items'][0]['price_data']['currency']);
            self::assertSame('exclusive', $params['line_items'][0]['price_data']['tax_behavior']);
            self::assertSame('month', $params['line_items'][0]['price_data']['recurring']['interval']);
            self::assertArrayNotHasKey('trial_period_days', $params['subscription_data']);
            self::assertArrayNotHasKey('price', $params['line_items'][0]);
            return self::sessionResponse($params);
        });
        self::assertSame('https://checkout.stripe.com/c/pay/fixture', $this->start());
        self::assertSame($before, [$this->sub->getPlan(), $this->sub->getStatus(), $this->sub->getCurrentPeriodEnd(), $this->sub->getTrialEndsAt(), $this->sub->getAddons(), $this->sub->getLimitsSnapshot()]);
        self::assertSame(1, $this->em->getRepository(EntiteSubscription::class)->count([]));
        self::assertNull($this->sub->getStripeSubscriptionId());
        $setup = $this->em->getRepository(InternalUsageSetup::class)->findOneBy([]);
        self::assertSame(0, $setup->acceptance['baseMonthlyCents']);
        self::assertSame(1, $setup->acceptance['unitPriceCents']);
        self::assertSame('cs_internal', $setup->sessionId);
    }

    public function testLostCheckoutResponseReplaysOnlyTheSameFrozenRequestAndKey(): void
    {
        $requests = [];
        $keys = [];
        $this->mockStripe(function ($params, $headers) use (&$requests, &$keys) {
            $requests[] = $params;
            $keys[] = array_values(array_filter($headers, fn($header) => str_starts_with($header, 'Idempotency-Key:')));
            if (count($requests) === 1) throw new \RuntimeException('Lost response fixture');
            return self::sessionResponse($params);
        });
        try { $this->start(); self::fail('Expected temporary failure'); } catch (\DomainException) {}
        self::assertSame(1, $this->em->getRepository(InternalUsageSetup::class)->count([]));
        $this->start();
        self::assertSame($requests[0], $requests[1]);
        self::assertNotEmpty($keys[0]);
        self::assertSame($keys[0], $keys[1]);
    }

    public function testForeignCustomerNeverCreatesCheckout(): void
    {
        $this->mockStripe(function () { self::fail('Foreign customer must not be charged'); }, ['id' => 'cus_internal', 'object' => 'customer', 'livemode' => true, 'balance' => 0, 'metadata' => ['entite_id' => '2']]);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('client Stripe');
        $this->start();
    }

    public function testExistingRemoteSubscriptionStopsDuplicateEvenBeforeWebhookArrives(): void
    {
        $this->mockStripe(function () { self::fail('Existing subscription must not create another checkout'); }, null, [['id' => 'sub_existing', 'status' => 'active']]);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('souscription Stripe existe déjà');
        $this->start();
    }

    public function testExpiredUncertainRequestCannotCreateANewCheckout(): void
    {
        $this->mockStripe(function () { throw new \RuntimeException('Lost response'); });
        try { $this->start(); } catch (\DomainException) {}
        $setup = $this->em->getRepository(InternalUsageSetup::class)->findOneBy([]);
        $setup->request['checkout']['expires_at'] = time() - 1;
        $this->em->flush();
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::never())->method('request');
        ApiRequestor::setHttpClient($http);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('expiré');
        $this->start();
    }

    public function testPlatformShellWithoutLegalIdentityCannotStartSetup(): void
    {
        $this->entite->setSiret(null);
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::never())->method('request');
        ApiRequestor::setHttpClient($http);
        $this->expectException(\DomainException::class);
        $this->start();
    }

    public function testDedicatedPageRequiresExplicitCurrentTermsBeforeAnyStripeRequest(): void
    {
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::never())->method('request');
        ApiRequestor::setHttpClient($http);
        $client = new KernelBrowser(self::$kernel);
        $client->disableReboot();
        $client->catchExceptions(false);
        $client->loginUser($this->user);
        $url = self::getContainer()->get('router')->generate('app_billing_internal_usage', ['entite' => 1, '_locale' => 'fr']);
        $page = $client->request('GET', $url);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('0,01 € HT', $page->text());
        self::assertCount(1, $page->filter('input[name="usage_accepted"]'));
        $client->request('POST', $url, ['_token' => $page->filter('input[name="_token"]')->attr('value')]);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame(0, $this->em->getRepository(InternalUsageSetup::class)->count([]));
    }
    private function confirmation(): array
    {
        $this->sub->setStripeCustomerId('cus_internal');
        $setup = new InternalUsageSetup();
        $setup->entite = $this->entite;
        $setup->subscription = $this->sub;
        $setup->customerId = 'cus_internal';
        $this->em->persist($setup);
        $this->em->flush();
        $metadata = ['entite_id' => '1', 'local_subscription_id' => (string) $this->sub->getId(), 'internal_usage_setup' => $setup->id];
        $remote = ['id' => 'sub_internal', 'object' => 'subscription', 'customer' => 'cus_internal', 'livemode' => true, 'metadata' => $metadata,
            'currency' => 'eur', 'collection_method' => 'charge_automatically', 'automatic_tax' => ['enabled' => true],
            'items' => ['has_more' => false, 'data' => [['quantity' => 1, 'price' => ['unit_amount' => 0, 'currency' => 'eur', 'tax_behavior' => 'exclusive', 'recurring' => ['interval' => 'month', 'interval_count' => 1]]]]]];
        $session = ['id' => 'cs_internal', 'object' => 'checkout.session', 'mode' => 'subscription', 'livemode' => true, 'status' => 'complete',
            'customer' => 'cus_internal', 'subscription' => 'sub_internal', 'client_reference_id' => (string) $this->sub->getId(), 'metadata' => $metadata,
            'currency' => 'eur', 'amount_total' => 0, 'payment_status' => 'no_payment_required'];
        return [$setup, $remote, $session];
    }

    public function testSubscriptionCreatedBeforeCheckoutAndThenCompletedAreValidatedWithoutChangingAccess(): void
    {
        [$setup, $remote, $session] = $this->confirmation();
        $before = [$this->sub->getPlan(), $this->sub->getStatus(), $this->sub->getAddons(), $this->sub->getLimitsSnapshot()];
        $this->service()->assertSubscription($remote, $this->sub);
        self::assertNull($this->sub->getStripeSubscriptionId());
        self::assertNull($setup->sessionId);
        $this->service()->assertCompleted($session, $remote, $this->sub);
        self::assertSame('cs_internal', $setup->sessionId);
        self::assertSame($before, [$this->sub->getPlan(), $this->sub->getStatus(), $this->sub->getAddons(), $this->sub->getLimitsSnapshot()]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidSubscriptionData')]
    public function testSubscriptionEventsFailClosedAgainstChangedOrForeignSubscription(array $change): void
    {
        [, $remote] = $this->confirmation();
        $remote = array_replace_recursive($remote, $change);
        $this->expectException(\DomainException::class);
        $this->service()->assertSubscription($remote, $this->sub);
    }

    public static function invalidSubscriptionData(): iterable
    {
        yield 'paid base' => [['items' => ['data' => [['price' => ['unit_amount' => 100]]]]]];
        yield 'foreign customer' => [['customer' => 'cus_foreign']];
        yield 'wrong currency' => [['currency' => 'usd']];
        yield 'test mode' => [['livemode' => false]];
        yield 'wrong tenant metadata' => [['metadata' => ['entite_id' => '2']]];
        yield 'missing marker' => [['metadata' => ['internal_usage_setup' => null]]];
        yield 'wrong local subscription' => [['metadata' => ['local_subscription_id' => '99999']]];
        yield 'changed setup' => [['metadata' => ['internal_usage_setup' => 'other']]];
        yield 'year interval' => [['items' => ['data' => [['price' => ['recurring' => ['interval' => 'year']]]]]]];
        yield 'tax inclusive' => [['items' => ['data' => [['price' => ['tax_behavior' => 'inclusive']]]]]];
        yield 'extra quantity' => [['items' => ['data' => [['quantity' => 2]]]]];
        yield 'paginated items' => [['items' => ['has_more' => true]]];
        yield 'extra item' => [['items' => ['data' => [1 => ['quantity' => 1]]]]];
        yield 'automatic tax disabled' => [['automatic_tax' => ['enabled' => false]]];
        yield 'manual collection' => [['collection_method' => 'send_invoice']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidCheckoutData')]
    public function testCompletedCheckoutMustMatchExactFrozenReservation(array $change): void
    {
        [$setup, $remote, $session] = $this->confirmation();
        $setup->sessionId = 'cs_internal';
        $session = array_replace_recursive($session, $change);
        $this->expectException(\DomainException::class);
        $this->service()->assertCompleted($session, $remote, $this->sub);
    }

    public static function invalidCheckoutData(): iterable
    {
        yield 'different checkout' => [['id' => 'cs_other']];
        yield 'initial amount charged' => [['amount_total' => 100]];
        yield 'wrong currency' => [['currency' => 'usd']];
        yield 'wrong subscription' => [['subscription' => 'sub_other']];
        yield 'not complete' => [['status' => 'open']];
        yield 'payment mode' => [['mode' => 'payment']];
        yield 'foreign customer' => [['customer' => 'cus_other']];
        yield 'wrong local reference' => [['client_reference_id' => '99999']];
        yield 'missing internal marker' => [['metadata' => ['internal_usage_setup' => null]]];
        yield 'wrong tenant marker' => [['metadata' => ['entite_id' => '2']]];
    }

    public function testMetadataAloneCannotInventReservation(): void
    {
        $this->expectException(\DomainException::class);
        $this->service()->assertSubscription(['metadata' => ['internal_usage_setup' => 'invented']], $this->sub);
    }

    public function testOrdinarySubscriptionsWithoutInternalMarkerRemainUnaffected(): void
    {
        $this->service()->assertSubscription([], $this->sub);
        $this->service()->assertCompleted([], [], $this->sub);
        self::assertSame(0, $this->em->getRepository(InternalUsageSetup::class)->count([]));
    }

    public function testExpiredLocalAccessStillAllowsOnlyTheBillingSetupPage(): void
    {
        $this->sub->setStatus('canceled')->setCurrentPeriodEnd(new \DateTimeImmutable('-1 day'));
        $this->em->flush();
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::never())->method('request');
        ApiRequestor::setHttpClient($http);
        $client = new KernelBrowser(self::$kernel);
        $client->disableReboot();
        $client->loginUser($this->user);
        $client->request('GET', '/fr/administrateur/1/billing/internal-usage');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('canceled', $this->sub->getStatus());
    }

    public function testPriorCustomerBalanceCannotBeCollectedByZeroBaseSetup(): void
    {
        $this->mockStripe(function () { self::fail('Prior debt must be reconciled before checkout'); }, ['id' => 'cus_internal', 'object' => 'customer', 'livemode' => true, 'balance' => 500, 'metadata' => ['entite_id' => '1']]);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('solde Stripe');
        $this->start();
    }

    public function testPendingInvoiceItemCannotBeCollectedByZeroBaseSetup(): void
    {
        $this->sub->setStripeCustomerId('cus_internal');
        $this->em->flush();
        $http = $this->createMock(ClientInterface::class);
        $http->method('request')->willReturnCallback(function ($method, $url) {
            self::assertSame('get', strtolower($method));
            if (str_ends_with($url, '/customers/cus_internal')) return [json_encode(['id' => 'cus_internal', 'object' => 'customer', 'livemode' => true, 'balance' => 0, 'metadata' => ['entite_id' => '1']]), 200, []];
            self::assertStringEndsWith('/invoiceitems', $url);
            return [json_encode(['object' => 'list', 'data' => [['id' => 'ii_previous']], 'has_more' => false]), 200, []];
        });
        ApiRequestor::setHttpClient($http);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('antérieure');
        $this->start();
    }

}
