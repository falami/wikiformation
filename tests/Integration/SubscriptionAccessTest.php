<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\{Entite, Entreprise, Utilisateur, UtilisateurEntite};
use App\Entity\Billing\EntiteSubscription;
use App\EventSubscriber\BillingAccessSubscriber;
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\{Request, RequestStack};
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class SubscriptionAccessTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private array $database;
    private Utilisateur $user;
    private Entite $first;
    private Entite $second;

    protected function setUp(): void
    {
        $this->database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->user = (new Utilisateur())->setEmail('access@example.test')->setPassword('unused')->setNom('Access')->setPrenom('Test');
        $this->first = (new Entite())->setNom('First')->setPublic(false)->setCreateur($this->user);
        $this->second = (new Entite())->setNom('Second')->setPublic(false)->setCreateur($this->user);
        foreach ([$this->user, $this->first, $this->second] as $record) $this->em->persist($record);
        $this->em->flush();
        $this->user->setEntite($this->first);
        foreach ([$this->first, $this->second] as $entite) {
            $this->em->persist((new EntiteSubscription())->setEntite($entite)->setStatus(EntiteSubscription::STATUS_ACTIVE));
            $this->em->persist((new UtilisateurEntite())->setEntite($entite)->setUtilisateur($this->user)->setCreateur($this->user)->setRoles([UtilisateurEntite::TENANT_ADMIN, UtilisateurEntite::TENANT_COMMERCIAL]));
        }
        $this->em->flush();
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($this->user, 'main', $this->user->getRoles()));
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $index => $name) {
            if ($this->database[$index] === null) unset($GLOBALS['_'.$name]['DATABASE_URL']);
            else $GLOBALS['_'.$name]['DATABASE_URL'] = $this->database[$index];
        }
    }

    private function expire(Entite $entite): void
    {
        $subscription = $this->em->getRepository(EntiteSubscription::class)->findOneBy(['entite' => $entite]);
        $subscription->setCurrentPeriodEnd(new \DateTimeImmutable('-1 day'));
        $this->em->flush();
    }

    private function event(string $route, ?Entite $entite, ?Session $session = null): RequestEvent
    {
        $request = Request::create('/fr/organisme');
        $request->attributes->set('_route', $route);
        if ($entite !== null) $request->attributes->set('entite', (string) $entite->getId());
        if ($session !== null) $request->setSession($session);
        $event = new RequestEvent(self::$kernel, $request, HttpKernelInterface::MAIN_REQUEST);
        self::getContainer()->get(BillingAccessSubscriber::class)->onKernelRequest($event);

        return $event;
    }

    public function testExpiredRouteOrganismIsRejectedEvenWhenSavedOrganismIsActive(): void
    {
        $this->expire($this->second);
        self::assertSame(302, $this->event('app_administrateur_dashboard', $this->second)->getResponse()?->getStatusCode());
    }

    public function testActiveRouteOrganismIsAllowedEvenWhenSavedOrganismIsExpired(): void
    {
        $this->expire($this->first);
        self::assertNull($this->event('app_administrateur_dashboard', $this->second)->getResponse());
    }

    public function testPlatformAdministratorCanInspectAnExpiredOrganism(): void
    {
        $this->expire($this->second);
        $this->user->setRoles(['ROLE_SUPER_ADMIN']);
        self::assertNull($this->event('app_administrateur_dashboard', $this->second)->getResponse());
        $this->user->setRoles(['ROLE_USER']);
        self::assertSame(302, $this->event('app_administrateur_dashboard', $this->second)->getResponse()?->getStatusCode());
    }

    public function testCurrentSessionOrganismIsUsedForPrivateRoutesWithoutOrganismParameter(): void
    {
        $this->expire($this->second);
        $session = new Session(new MockArraySessionStorage());
        $session->set('current_entite_id', $this->second->getId());
        $request = new Request();
        $request->setSession($session);
        $stack = self::getContainer()->get(RequestStack::class);
        $stack->push($request);
        try {
            self::assertSame(302, $this->event('app_administrateur_dashboard', null, $session)->getResponse()?->getStatusCode());
        } finally {
            $stack->pop();
        }
    }

    public static function partnerRoutes(): iterable
    {
        yield ['app_commercial_dashboard'];
        yield ['app_commercial_new'];
        yield ['app_opco_dashboard'];
    }

    #[DataProvider('partnerRoutes')]
    public function testExpiredPartnerSpaceRequiresAdministratorRenewal(string $route): void
    {
        $this->expire($this->second);
        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('renouvelé par son administrateur');
        $this->event($route, $this->second);
    }

    public function testExpiredSubscriptionKeepsBillingRecoveryPageAccessible(): void
    {
        $this->expire($this->first);
        $client = self::getContainer()->get('test.client');
        $client->disableReboot();
        $client->loginUser($this->user);
        $client->request('GET', '/fr/administrateur/'.$this->first->getId().'/billing');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Gérez votre offre', $client->getResponse()->getContent());
    }

    public function testPrivateQuickCreateCannotBypassExpiredSubscriptionThroughItsRouteName(): void
    {
        $this->expire($this->second);
        $client = self::getContainer()->get('test.client');
        $client->disableReboot();
        $client->loginUser($this->user);
        $client->request('GET', '/fr/administrateur/'.$this->second->getId().'/formation/creation-rapide/formateur');
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame('/fr/tarifs', $client->getResponse()->headers->get('Location'));
    }

    public function testForeignExpiredOrganismStillUsesControllerAuthorization(): void
    {
        $this->expire($this->second);
        $membership = $this->em->getRepository(UtilisateurEntite::class)->findOneBy(['entite' => $this->second, 'utilisateur' => $this->user]);
        $this->em->remove($membership);
        $this->em->flush();
        self::assertNull($this->event('app_administrateur_dashboard', $this->second)->getResponse());
    }

    public function testProspectCompanyQuickCreateReturnsQuotaReasonAndCreatesNothing(): void
    {
        $company = (new Entreprise())->setEntite($this->first)->setCreateur($this->user)->setRaisonSociale('Existing');
        $this->em->persist($company);
        $this->em->flush();
        $client = self::getContainer()->get('test.client');
        $client->disableReboot();
        $client->loginUser($this->user);
        $crawler = $client->request('GET', '/fr/administrateur/'.$this->first->getId().'/prospection/new');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $token = $crawler->filter('#form-new-entreprise input[name="_token"]')->attr('value');
        $client->request('POST', '/fr/administrateur/'.$this->first->getId().'/prospection/entreprise/new', ['_token' => $token, 'raisonSociale' => 'Over capacity'], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertSame(409, $client->getResponse()->getStatusCode());
        $response = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($response['limitReached']);
        self::assertSame('max_entreprises', $response['quota']);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM entreprise'));
    }
}
