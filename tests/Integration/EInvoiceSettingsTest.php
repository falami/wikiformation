<?php
declare(strict_types=1);
namespace App\Tests\Integration;
use App\Entity\{Entite, Utilisateur, UtilisateurEntite, EInvoiceConnection, EInvoiceReceived};
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EInvoiceSettingsTest extends KernelTestCase
{
    private array $environment;
    private $em;
    private $client;
    private Entite $entite;
    private Utilisateur $user;
    protected function setUp(): void
    {
        $this->environment = [];
        foreach (['DATABASE_URL', 'EINVOICING_CLIENT_ID', 'EINVOICING_CLIENT_SECRET', 'EINVOICING_REDIRECT_URI', 'EINVOICING_ENCRYPTION_KEY'] as $key) {
            $this->environment[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null];
            $_ENV[$key] = $_SERVER[$key] = $key === 'DATABASE_URL' ? 'sqlite:///:memory:' : '';
        }
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->user = (new Utilisateur())->setEmail('pdp-settings@example.test')->setPassword('unused')->setNom('Admin')->setPrenom('Test')->setRoles(['ROLE_USER']);
        $this->entite = (new Entite())->setNom('Organisme connecté')->setCreateur($this->user)->setPublic(false);
        $this->em->persist($this->user); $this->em->persist($this->entite); $this->em->flush();
        $this->user->setEntite($this->entite);
        $this->em->persist((new UtilisateurEntite())->setEntite($this->entite)->setUtilisateur($this->user)->setCreateur($this->user)->setRoles(['TENANT_ADMIN']));
        $this->em->persist((new \App\Entity\Billing\EntiteSubscription())->setEntite($this->entite)->setStatus('active'));
        $this->em->flush();
        $this->client = self::getContainer()->get('test.client'); $this->client->disableReboot(); $this->client->catchExceptions(false); $this->client->loginUser($this->user);
    }
    protected function tearDown(): void
    {
        parent::tearDown();
        foreach ($this->environment as $key => $values) foreach (['ENV', 'SERVER'] as $i => $target) {
            if ($values[$i] === null) unset($GLOBALS['_'.$target][$key]); else $GLOBALS['_'.$target][$key] = $values[$i];
        }
    }
    public function testSettingsAreReadOnlyUntilConfiguredAndNeverExposeCredentials(): void
    {
        $url = '/fr/administrateur/'.$this->entite->getId().'/preferences/facturation-electronique';
        $page = $this->client->request('GET', $url);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->em->getRepository(EInvoiceConnection::class)->count([]));
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'));
        self::assertStringContainsString('0,02 € HT', $this->client->getResponse()->getContent());
        self::assertStringContainsString('Aucun quota', $this->client->getResponse()->getContent());
        $connection = new EInvoiceConnection($this->entite, 'production');
        $connection->connected(['id' => 'remote-1', 'number' => '123456789', 'formal_name' => 'Organisme connecté'], 'ciphertext-never-render');
        $this->em->persist($connection); $this->em->flush();
        $page = $this->client->request('GET', $url);
        self::assertStringNotContainsString('ciphertext-never-render', $this->client->getResponse()->getContent());
        $this->client->submit($page->selectButton('Accepter et activer')->form(['accept_price' => '1']));
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $connection = $this->em->find(EInvoiceConnection::class, $connection->getId()); self::assertFalse($connection->isAutomatic(), 'Local active subscription without Stripe identifiers cannot accept usage charges.');
        self::assertNull($connection->getBillingConsentAt());
    }
    public function testOwnerSeesTheDiscountInBothPriceAndConsent(): void
    {
        $this->entite->setSiret('80813612100023'); $this->em->flush();
        $this->client->request('GET', '/fr/administrateur/'.$this->entite->getId().'/preferences/facturation-electronique');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('0,01 € HT', $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('0,02 € HT', $this->client->getResponse()->getContent());
    }
    public function testMutationsRequireCsrfAndTenantPermissionAndOauthCannotBeReplayed(): void
    {
        $other = (new Entite())->setNom('Autre organisme')->setCreateur($this->user)->setPublic(false);
        $this->em->persist($other); $this->em->flush();
        $foreign = new EInvoiceConnection($other, 'production'); $this->em->persist($foreign);
        $received = new EInvoiceReceived($foreign, 'remote-private', []); $this->em->persist($received); $this->em->flush();
        $this->client->catchExceptions(true);
        $base = '/fr/administrateur/'.$this->entite->getId().'/preferences/facturation-electronique';
        $this->client->request('POST', $base.'/connexion', ['mode' => 'production', '_token' => 'bad']);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', '/fr/administrateur/'.$other->getId().'/preferences/facturation-electronique');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', $base.'/recue/'.$received->getId());
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', '/fr/administrateur/facturation-electronique/retour?state=invalid&code=unused');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('La connexion ne peut pas être finalisée', $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('unused', $this->client->getResponse()->getContent());
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'));
        self::assertSame(1, $this->em->getRepository(EInvoiceConnection::class)->count([]));
    }
    public function testOauthPkceBindsTheOrganismAndTariffNeedsASeparatePost(): void
    {
        $this->entite->setSiret('12345678900012');
        $subscription = $this->em->getRepository(\App\Entity\Billing\EntiteSubscription::class)->findOneBy(['entite' => $this->entite]);
        $subscription->setStripeCustomerId('cus_fixture')->setStripeSubscriptionId('sub_fixture');
        $this->em->flush();
        $config = new \App\Service\EInvoicing\EInvoiceConfiguration('client-fixture', 'secret-fixture', 'https://example.test/fr/administrateur/facturation-electronique/retour', base64_encode(random_bytes(32)));
        self::getContainer()->set(\App\Service\EInvoicing\EInvoiceConfiguration::class, $config);
        $replies = [
            ['access_token' => 'fixture-access', 'refresh_token' => 'fixture-refresh', 'expires_in' => 1800, 'token_type' => 'Bearer'],
            ['company_verification_status' => 'verified'],
            ['id' => '12', 'env' => 'production', 'number_scheme' => 'fr_siren', 'number' => '123456789', 'formal_name' => 'Organisme connecté'],
        ];
        $http = new \Symfony\Component\HttpClient\MockHttpClient(array_map(static fn($row) => new \Symfony\Component\HttpClient\Response\MockResponse(json_encode($row), ['response_headers' => ['content-type: application/json']]), $replies));
        self::getContainer()->set(\App\Service\EInvoicing\SuperPdpClient::class, new \App\Service\EInvoicing\SuperPdpClient($http));
        $url = '/fr/administrateur/'.$this->entite->getId().'/preferences/facturation-electronique';
        $page = $this->client->request('GET', $url);
        $this->client->submit($page->selectButton('Connecter mon organisme')->form());
        $redirect = $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('https://api.superpdp.tech/oauth2/authorize?', $redirect);
        parse_str(parse_url($redirect, PHP_URL_QUERY), $query);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertSame('123456789', $query['superpdp_company_number']);
        self::assertArrayNotHasKey('client_secret', $query);
        $callback = '/fr/administrateur/facturation-electronique/retour?state='.$query['state'].'&code=fixture-code';
        $this->client->request('GET', $callback);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $connection = $this->em->getRepository(EInvoiceConnection::class)->findOneBy(['entite' => $this->entite]);
        self::assertNotNull($connection);
        self::assertTrue($connection->isVerified()); self::assertFalse($connection->isAutomatic());
        self::assertStringNotContainsString('fixture-access', $connection->getCredentials());
        self::assertNull($connection->getBillingConsentAt());
        $page = $this->client->request('GET', $url);
        $this->client->submit($page->selectButton('Accepter et activer')->form(['accept_price' => '1']));
        $this->em->clear(); $connection = $this->em->getRepository(EInvoiceConnection::class)->findOneBy(['entite' => $this->entite->getId()]);
        self::assertTrue($connection->isAutomatic()); self::assertSame(2, $connection->getUnitPriceCents());
        self::assertSame($this->user->getId(), $connection->getBillingConsentBy());
        $this->client->catchExceptions(true); $this->client->request('GET', $callback);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, $this->em->getRepository(EInvoiceConnection::class)->count([]));
    }
}
