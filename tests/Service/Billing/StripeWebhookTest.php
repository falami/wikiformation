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
            new \App\Service\Marketplace\Payments($em,$this->createMock(\App\Service\Marketplace\StripeGateway::class),new \App\Service\Marketplace\Policy($em),$this->createMock(\Symfony\Component\Routing\Generator\UrlGeneratorInterface::class),self::getContainer()->get(\App\Service\Marketplace\Invoicing::class)),
            new \App\Service\Marketplace\Confirmations($em,new \App\Service\Email\MailerManager($this->createMock(\Symfony\Component\Mailer\MailerInterface::class),new \Twig\Environment(new \Twig\Loader\ArrayLoader()),$this->createMock(\Symfony\Component\Routing\Generator\UrlGeneratorInterface::class)),$this->createMock(\Symfony\Component\Routing\Generator\UrlGeneratorInterface::class)),
            new EntiteSubscriptionRepository($registry),
            new PlanRepository($registry),
            $em,
            new \App\Service\Partner\Commissions($em,new \App\Service\Partner\Referrals($em),$this->createMock(\App\Service\Partner\PartnerStripeGateway::class),new \Symfony\Component\Lock\LockFactory(new \Symfony\Component\Lock\Store\FlockStore())),
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

}
