<?php
declare(strict_types=1);
namespace App\Tests\Integration;

use App\Entity\{Entite, Utilisateur, UtilisateurEntite};
use App\Entity\Billing\{EntiteSubscription, InternalUsageSetup, Plan};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Stripe\{ApiRequestor, HttpClient\ClientInterface, HttpClient\CurlClient};
use Symfony\Bundle\FrameworkBundle\{KernelBrowser, Test\KernelTestCase};

final class InternalUsageContractProtectionTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
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

    public static function protectedIdentities(): iterable
    {
        yield 'owner identity before setup' => [false];
        yield 'persisted setup after identity edit' => [true];
    }

    #[DataProvider('protectedIdentities')]
    public function testInternalContractCannotBeChangedToPublicPaidPlan(bool $identityEdited): void
    {
        $user = (new Utilisateur())->setEmail('protected@example.test')->setPassword('unused')->setPrenom('Owner')->setNom('Fixture');
        $entite = (new Entite())->setNom('I.T. For You')->setSiret('80813612100023')->setCreateur($user)->setPublic(false);
        $plan = (new Plan())->setCode('SOLO')->setName('Existing rights')->setPriceMonthlyCents(2900)->setStripePriceMonthlyId('price_solo');
        foreach ([$user, $entite, $plan] as $row) $this->em->persist($row);
        $this->em->flush();
        $user->setEntite($entite);
        $membership = (new UtilisateurEntite())->setEntite($entite)->setUtilisateur($user)->setCreateur($user)->setRoles([UtilisateurEntite::TENANT_ADMIN]);
        $user->addUtilisateurEntite($membership);$this->em->persist($membership);
        $sub = (new EntiteSubscription())->setEntite($entite)->setPlan($plan)->setStatus('active')->setStripeCustomerId('cus_internal')->setStripeSubscriptionId('sub_internal');
        $this->em->persist($sub);$this->em->flush();
        if ($identityEdited) {
            $setup = new InternalUsageSetup();$setup->entite = $entite;$setup->subscription = $sub;
            $this->em->persist($setup);$entite->setSiret(null);$this->em->flush();
        }
        $http = $this->createMock(ClientInterface::class);$http->expects(self::never())->method('request');ApiRequestor::setHttpClient($http);
        $client = new KernelBrowser(self::$kernel);$client->disableReboot();$client->catchExceptions(false);$client->loginUser($user);
        $page = $client->request('GET', '/fr/administrateur/'.$entite->getId().'/billing');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(0, $page->filter('#btnChangePlan, #changePlanModal, #billing-cancel-help'));
        self::assertStringContainsString('Base mensuelle de 0 €', $page->text());
        $tokens = self::getContainer()->get('security.csrf.token_manager');
        $requests = self::getContainer()->get('request_stack');
        $requests->push($client->getRequest());
        $preview = $tokens->getToken('change_plan_preview')->getValue();
        $apply = $tokens->getToken('change_plan')->getValue();
        $client->getRequest()->getSession()->save();
        $requests->pop();
        $client->request('POST', '/fr/billing/change/preview', ['plan' => 'SOLO', 'interval' => 'month', '_token' => $preview]);
        self::assertSame(400, $client->getResponse()->getStatusCode());
        $client->request('POST', '/fr/billing/change/apply', ['plan' => 'SOLO', 'interval' => 'month', '_token' => $apply]);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $saved = $this->em->find(EntiteSubscription::class, $sub->getId());
        self::assertSame('sub_internal', $saved->getStripeSubscriptionId());
        self::assertSame($plan->getId(), $saved->getPlan()->getId());
        // Portal failures return a usable page and never disclose Stripe exception contents.
        $failedStripe = $this->createMock(ClientInterface::class);
        $failedStripe->expects(self::once())->method('request')->willThrowException(new \RuntimeException('private-stripe-diagnostic'));
        ApiRequestor::setHttpClient($failedStripe);
        $client->request('GET', '/fr/administrateur/'.$entite->getId().'/billing/portal');
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertStringEndsWith('/billing', $client->getResponse()->headers->get('Location'));
        $page = $client->followRedirect();
        self::assertStringContainsString('Le portail de facturation est momentanément indisponible', $page->text());
        self::assertStringNotContainsString('private-stripe-diagnostic', $page->text());
    }
}
