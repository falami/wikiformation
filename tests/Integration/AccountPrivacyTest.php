<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Command\{PrivacyRequestsCommand, PrivacyInventoryCommand};
use App\Entity\{Utilisateur, Entite, UtilisateurEntite, Site, Formation, Session, Inscription};
use App\Entity\Privacy\DataRightsRequest;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class AccountPrivacyTest extends KernelTestCase
{
    private KernelBrowser $client;
    private array $database;
    private Utilisateur $user;
    private Utilisateur $other;

    protected function setUp(): void
    {
        $this->database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        self::getContainer()->get('twig')->addGlobal('entite', null);
        $this->user = (new Utilisateur())->setEmail('owner@example.test')->setNom('Compte')->setPrenom('Alice')->setPassword('never-export-this-hash')->setNewsletter(true)->setResetToken('never-export-this-token');
        $this->other = (new Utilisateur())->setEmail('other@example.test')->setNom('Another')->setPrenom('Bob')->setPassword('other-secret')->setNewsletter(true);
        $em->persist($this->user); $em->persist($this->other); $em->flush();
        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot(); $this->client->catchExceptions(false);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $index => $scope) {
            if ($this->database[$index] === null) unset($GLOBALS['_'.$scope]['DATABASE_URL']);
            else $GLOBALS['_'.$scope]['DATABASE_URL'] = $this->database[$index];
        }
    }

    public function testRightsWorkWithoutTenantAndDuplicateRequestIsIdempotentAndPrivate(): void
    {
        $this->client->loginUser($this->user);
        $page = $this->client->request('GET', '/fr/compte/confidentialite');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
        $form = $page->filter('form[name="privacy_request"]')->form(['privacy_request[type]' => 'erasure', 'privacy_request[details]' => '<script>private request</script>']);
        $this->client->submit($form); $this->client->submit($form);
        $repo = self::getContainer()->get('doctrine')->getRepository(DataRightsRequest::class);
        self::assertCount(1, $repo->findAll());
        $item = $repo->findAll()[0];
        self::assertSame($this->user->getId(), $item->getOwner()->getId());
        self::assertSame('pending', $item->getStatus());
        self::assertNotNull(self::getContainer()->get('doctrine')->getRepository(Utilisateur::class)->find($this->user->getId()), 'A request must not delete the account');
        $page = $this->client->request('GET', '/fr/compte/confidentialite');
        self::assertStringContainsString('&lt;script&gt;private request&lt;/script&gt;', $page->html());
        $this->client->loginUser($this->other);
        $page = $this->client->request('GET', '/fr/compte/confidentialite');
        self::assertStringNotContainsString($item->getReference(), $page->html());
        self::assertStringNotContainsString('private request', $page->html());
    }

    public function testCsrfAndValidationPreventWrites(): void
    {
        $this->client->loginUser($this->user);
        $page = $this->client->request('GET', '/fr/compte/confidentialite');
        $token = $page->filter('input[name="privacy_request[_token]"]')->attr('value');
        foreach ([['type' => 'access', 'details' => '', '_token' => 'bad'], ['type' => 'invalid', 'details' => '', '_token' => $token], ['type' => 'access', 'details' => str_repeat('a', 2001), '_token' => $token], ['type' => 'access', 'details' => '', 'owner' => $this->other->getId(), '_token' => $token]] as $values) {
            $this->client->request('POST', '/fr/compte/confidentialite', ['privacy_request' => $values]);
            self::assertCount(0, self::getContainer()->get('doctrine')->getRepository(DataRightsRequest::class)->findAll());
        }
    }

    public function testExportAndWithdrawalAreOwnAccountOnlyWithCsrf(): void
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        $organization = (new Entite())->setNom('Own organization')->setCreateur($this->user)->setPublic(false);
        $em->persist($organization); $em->flush();
        $em->persist((new UtilisateurEntite())->setUtilisateur($this->user)->setEntite($organization)->setCreateur($this->user)->setRoles(['TENANT_STAGIAIRE'])); $em->flush();
        $site = (new Site())->setNom('Salle')->setSlug('privacy-room')->setEntite($organization)->setCreateur($this->user);
        $training = (new Formation())->setTitre('Formation privée')->setSlug('privacy-training')->setEntite($organization)->setCreateur($this->user);
        $em->persist($site); $em->persist($training);
        $session = (new Session())->setCode('PRIVACY-SESSION')->setSite($site)->setFormation($training)->setEntite($organization)->setCreateur($this->user);
        $em->persist($session);
        foreach ([$this->user, $this->other] as $learner) $em->persist((new Inscription())->setEntite($organization)->setCreateur($this->user)->setSession($session)->setStagiaire($learner));
        $em->flush();
        $this->client->loginUser($this->user);
        $page = $this->client->request('GET', '/fr/compte/confidentialite');
        $exportForm = $page->selectButton('Télécharger mes données de compte')->form();
        $withdrawForm = $page->selectButton('Désactiver la newsletter')->form();
        $this->client->request('POST', $exportForm->getUri(), $exportForm->getPhpValues() + ['owner' => $this->other->getId()]);
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        $json = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('owner@example.test', $json['account']['email']);
        self::assertSame('Own organization', $json['memberships'][0]['organization']);
        self::assertCount(1, $json['enrolments']);
        self::assertSame('PRIVACY-SESSION', $json['enrolments'][0]['session']);
        self::assertSame('Formation privée', $json['enrolments'][0]['training']);
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        self::assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        foreach (['other@example.test', 'never-export-this-hash', 'never-export-this-token'] as $secret) self::assertStringNotContainsString($secret, $response->getContent());
        $this->client->request('POST', $withdrawForm->getUri(), $withdrawForm->getPhpValues() + ['owner' => $this->other->getId()]);
        self::assertFalse($em->find(Utilisateur::class, $this->user->getId())->isNewsletter());
        self::assertTrue($em->find(Utilisateur::class, $this->other->getId())->isNewsletter());
        $this->client->catchExceptions(true);
        foreach (['export', 'newsletter/retrait'] as $action) {
            $this->client->request('POST', '/fr/compte/confidentialite/'.$action, ['_token' => 'bad']);
            self::assertSame(403, $this->client->getResponse()->getStatusCode());
        }
    }

    public function testAnonymousCannotAccessAccountPrivacyOrExport(): void
    {
        $this->client->catchExceptions(true);
        foreach ([['GET', ''], ['POST', '/export'], ['POST', '/newsletter/retrait']] as [$method, $suffix]) {
            $this->client->request($method, '/fr/compte/confidentialite'.$suffix);
            self::assertSame(302, $this->client->getResponse()->getStatusCode());
            self::assertStringContainsString('/login', $this->client->getResponse()->headers->get('Location'));
        }
    }

    public function testOperatorQueueIsPrivateByDefaultAndTracksResponse(): void
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        $item = new DataRightsRequest($this->user, 'access', 'private request', new \DateTimeImmutable('-2 months'));
        $em->persist($item); $em->flush();
        $command = new CommandTester(new PrivacyRequestsCommand($em));
        self::assertSame(1, $command->execute(['--overdue' => true]));
        self::assertStringContainsString($item->getReference(), $command->getDisplay());
        self::assertStringNotContainsString('owner@example.test', $command->getDisplay());
        self::assertStringNotContainsString('private request', $command->getDisplay());
        self::assertSame(0, $command->execute(['reference' => $item->getReference(), '--status' => 'processing', '--operator' => 'privacy-1']));
        self::assertSame('processing', $item->getStatus());
        $path = tempnam(sys_get_temp_dir(), 'privacy-response-');
        try {
            file_put_contents($path, 'Votre demande a été examinée.');
            self::assertSame(0, $command->execute(['reference' => $item->getReference(), '--status' => 'answered', '--operator' => 'privacy-1', '--response-file' => $path]));
        } finally { unlink($path); }
        self::assertSame('answered', $item->getStatus());
        self::assertCount(2, $item->getHistory());
        self::assertSame(0, $command->execute(['--overdue' => true]));
        $this->client->loginUser($this->user);
        $page = $this->client->request('GET', '/fr/compte/confidentialite');
        self::assertStringContainsString('Votre demande a été examinée.', $page->text());
    }

    public function testInventoryContainsModelMetadataOnly(): void
    {
        $command = new CommandTester(new PrivacyInventoryCommand(self::getContainer()->get('doctrine')->getManager()));
        self::assertSame(0, $command->execute([]));
        $data = json_decode($command->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertNotEmpty($data['entities']);
        self::assertStringNotContainsString('owner@example.test', $command->getDisplay());
        self::assertStringNotContainsString('never-export-this-hash', $command->getDisplay());
    }
}
