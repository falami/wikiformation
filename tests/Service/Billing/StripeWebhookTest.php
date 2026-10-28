<?php

namespace App\Tests\Service\Billing;

use App\Controller\Billing\StripeWebhookController;
use App\Repository\Billing\{EntiteSubscriptionRepository, PlanRepository};
use App\Service\Billing\StripeBillingService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Stripe\{ApiRequestor, HttpClient\ClientInterface, HttpClient\CurlClient};
use Symfony\Component\HttpFoundation\Request;

final class StripeWebhookTest extends KernelTestCase
{
    private $em;
    private array $env;
    private \App\Entity\Billing\EntiteSubscription $subscription;
    protected function setUp(): void
    {
        $this->env = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new \Doctrine\ORM\Tools\SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $user = (new \App\Entity\Utilisateur())->setEmail('webhook@example.test')->setPassword('unused')->setPrenom('Test')->setNom('Test');
        $entity = (new \App\Entity\Entite())->setNom('Test')->setPublic(false)->setCreateur($user);
        $this->subscription = (new \App\Entity\Billing\EntiteSubscription())->setEntite($entity)->setStripeCustomerId('cus_fixture')->setStatus('incomplete');
        foreach ([$user, $entity, $this->subscription] as $row) $this->em->persist($row);
        $this->em->flush();
    }
    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(new CurlClient());
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $i => $key) {
            if ($this->env[$i] === null) unset($GLOBALS['_'.$key]['DATABASE_URL']);
            else $GLOBALS['_'.$key]['DATABASE_URL'] = $this->env[$i];
        }
    }

    private function deliver(array $event, bool $validSignature = true): int
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $time = time();
        $signature = hash_hmac('sha256', $time.'.'.$body, $validSignature ? 'fixture_secret' : 'wrong');
        $request = Request::create('/fr/stripe/webhook', 'POST', server: [
            'HTTP_STRIPE_SIGNATURE' => 't='.$time.',v1='.$signature,
        ], content: $body);
        $registry = self::getContainer()->get('doctrine');
        $em = $this->em;

        return (new StripeWebhookController())->webhook(
            $request,
            new StripeBillingService('sk_test_fixture', 'https://example.test/fr'),
            self::getContainer()->get(\App\Service\Platform\StripeRevenue::class),
            new \App\Service\Marketplace\Payments($em,$this->createMock(\App\Service\Marketplace\StripeGateway::class),new \App\Service\Marketplace\Policy($em),$this->createMock(\Symfony\Component\Routing\Generator\UrlGeneratorInterface::class),self::getContainer()->get(\App\Service\Marketplace\Invoicing::class),self::getContainer()->get(\App\Service\Marketplace\MarketplaceInvoiceReadiness::class)),
            new \App\Service\Marketplace\Confirmations($em,new \App\Service\Email\MailerManager($this->createMock(\Symfony\Component\Mailer\MailerInterface::class),new \Twig\Environment(new \Twig\Loader\ArrayLoader()),$this->createMock(\Symfony\Component\Routing\Generator\UrlGeneratorInterface::class)),$this->createMock(\Symfony\Component\Routing\Generator\UrlGeneratorInterface::class)),
            new EntiteSubscriptionRepository($registry),
            new PlanRepository($registry),
            $em,
            new \App\Service\Partner\Commissions($em,new \App\Service\Partner\Referrals($em),$this->createMock(\App\Service\Partner\PartnerStripeGateway::class),new \Symfony\Component\Lock\LockFactory(new \Symfony\Component\Lock\Store\FlockStore())),
            new \App\Service\Billing\InternalUsageCheckout($em, 'sk_live_fixture'),
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
    public function testOldSubscriptionEventDoesNotOverwriteCurrentSubscription(): void
    {
        $this->subscription->setStripeSubscriptionId('sub_current')->setStatus('active');$this->em->flush();
        $http = $this->createMock(ClientInterface::class);$http->expects(self::never())->method('request');ApiRequestor::setHttpClient($http);
        self::assertSame(200, $this->deliver(['id'=>'evt_old','object'=>'event','type'=>'customer.subscription.deleted','data'=>['object'=>['id'=>'sub_old','customer'=>'cus_fixture']]]));
        self::assertSame('active',$this->subscription->getStatus());self::assertSame('sub_current',$this->subscription->getStripeSubscriptionId());
    }

    public function testScheduledCancellationKeepsPaidAccessUntilPeriodEnd(): void
    {
        $this->subscription->setStripeSubscriptionId('sub_current')->setStatus('active');
        $this->em->flush();
        $periodEnd = time() + 86400 * 20;
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::once())->method('request')->willReturn([
            json_encode([
                'id' => 'sub_current', 'object' => 'subscription', 'customer' => 'cus_fixture',
                'status' => 'active', 'current_period_end' => $periodEnd,
                'canceled_at' => time() - 60, 'cancel_at_period_end' => true,
                'items' => ['data' => []],
            ], JSON_THROW_ON_ERROR), 200, [],
        ]);
        ApiRequestor::setHttpClient($http);
        self::assertSame(200, $this->deliver([
            'id' => 'evt_cancel_scheduled', 'object' => 'event', 'type' => 'customer.subscription.updated',
            'data' => ['object' => ['id' => 'sub_current', 'customer' => 'cus_fixture']],
        ]));
        self::assertSame('active', $this->subscription->getStatus());
        self::assertSame($periodEnd, $this->subscription->getCanceledAt()?->getTimestamp());
    }
    public function testCheckoutCannotBindAnotherCustomerToLocalSubscription(): void
    {
        $http = $this->createMock(ClientInterface::class);$http->expects(self::never())->method('request');ApiRequestor::setHttpClient($http);
        self::assertSame(500,$this->deliver(['id'=>'evt_wrong','object'=>'event','type'=>'checkout.session.completed','data'=>['object'=>['mode'=>'subscription','customer'=>'cus_other','subscription'=>'sub_other','client_reference_id'=>(string)$this->subscription->getId()]]]));
        self::assertNull($this->subscription->getStripeSubscriptionId());self::assertSame('incomplete',$this->subscription->getStatus());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('internalEvents')]
    public function testInternalUsagePriceIsVerifiedBeforeBindingEvenWhenSubscriptionEventArrivesFirst(string $eventType, int $basePrice): void
    {
        $entite = $this->subscription->getEntite(); $entite->setSiret('80813612100023');
        $plan = (new \App\Entity\Billing\Plan())->setCode('OWNER-EXISTING')->setName('Droits existants')->setPriceMonthlyCents(2900);
        $this->em->persist($plan); $this->subscription->setPlan($plan);
        $setup = new \App\Entity\Billing\InternalUsageSetup();
        $setup->entite = $entite; $setup->subscription = $this->subscription;
        $setup->customerId = 'cus_fixture'; $setup->sessionId = 'cs_internal';
        $this->em->persist($setup); $this->em->flush();
        $metadata = ['internal_usage_setup' => $setup->id, 'entite_id' => (string) $entite->getId(), 'local_subscription_id' => (string) $this->subscription->getId()];
        $remote = ['id' => 'sub_internal', 'object' => 'subscription', 'customer' => 'cus_fixture', 'livemode' => true,
            'status' => 'active', 'collection_method' => 'charge_automatically', 'currency' => 'eur',
            'metadata' => $metadata, 'automatic_tax' => ['enabled' => true], 'current_period_end' => time() + 86400,
            'items' => ['has_more' => false, 'data' => [['quantity' => 1, 'price' => ['id' => 'price_inline_internal', 'unit_amount' => $basePrice,
                'currency' => 'eur', 'tax_behavior' => 'exclusive', 'recurring' => ['interval' => 'month', 'interval_count' => 1]]]]]];
        $object = $eventType === 'checkout.session.completed'
            ? ['id' => 'cs_internal', 'object' => 'checkout.session', 'livemode' => true, 'mode' => 'subscription',
                'status' => 'complete', 'payment_status' => 'no_payment_required', 'currency' => 'eur', 'amount_total' => 0, 'customer' => 'cus_fixture', 'subscription' => 'sub_internal',
                'client_reference_id' => (string) $this->subscription->getId(), 'metadata' => $metadata]
            : ['id' => 'sub_internal', 'object' => 'subscription', 'customer' => 'cus_fixture', 'metadata' => $metadata];
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::once())->method('request')->willReturn([json_encode($remote, JSON_THROW_ON_ERROR), 200, []]);
        ApiRequestor::setHttpClient($http);
        self::assertSame($basePrice === 0 ? 200 : 500, $this->deliver([
            'id' => 'evt_internal', 'object' => 'event', 'type' => $eventType, 'data' => ['object' => $object],
        ]));
        $this->em->refresh($this->subscription);
        self::assertSame($basePrice === 0 ? 'sub_internal' : null, $this->subscription->getStripeSubscriptionId());
        self::assertSame($basePrice === 0 ? 'active' : 'incomplete', $this->subscription->getStatus());
        self::assertSame($plan->getId(), $this->subscription->getPlan()->getId(), 'The usage-only setup does not replace the existing access plan.');
    }

    public static function internalEvents(): iterable
    {
        foreach (['checkout.session.completed', 'customer.subscription.created', 'customer.subscription.updated'] as $type) {
            yield $type.' zero' => [$type, 0];
            yield $type.' unexpected base fee' => [$type, 100];
        }
    }

}
