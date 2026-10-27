<?php
declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\{Devis, EmailLog, EmailTemplate, Entite, Prospect, Utilisateur, UtilisateurEntite};
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\MailerInterface;

final class BackendPrivacySecurityTest extends KernelTestCase
{
    private array $database;
    private $em;
    private $client;
    private $mailer;
    private array $ids = [];

    protected function setUp(): void
    {
        $this->database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->mailer = $this->createMock(MailerInterface::class);
        self::getContainer()->set(MailerInterface::class, $this->mailer);
        $objects['admin'] = (new Utilisateur())->setEmail('admin@privacy.test')->setPassword('unused')->setPrenom('Test')->setNom('Admin');
        $objects['recipient'] = (new Utilisateur())->setEmail('recipient@privacy.test')->setPassword('unused')->setPrenom('Test')->setNom('Recipient');
        foreach (['tenant', 'foreignTenant'] as $key) {
            $objects[$key] = (new Entite())->setNom($key)->setEmail('sender@privacy.test')->setCreateur($objects['admin'])->setPublic(false);
        }
        foreach ($objects as $object) $this->em->persist($object);
        $this->em->flush();
        $objects['admin']->setEntite($objects['tenant']);
        foreach (['admin', 'recipient'] as $key) {
            $this->em->persist((new UtilisateurEntite())->setEntite($objects['tenant'])->setUtilisateur($objects[$key])->setCreateur($objects['admin'])->setRoles([$key === 'admin' ? 'TENANT_ADMIN' : 'TENANT_STAGIAIRE']));
        }
        $this->em->persist((new \App\Entity\Billing\EntiteSubscription())->setEntite($objects['tenant'])->setStatus('active'));
        foreach (['own' => 'tenant', 'foreign' => 'foreignTenant'] as $prefix => $tenant) {
            $objects[$prefix.'Prospect'] = (new Prospect())->setEntite($objects[$tenant])->setCreateur($objects['admin'])->setEmail('prospect@privacy.test')->setPrenom('Test')->setNom($prefix);
            $objects[$prefix.'UserDevis'] = (new Devis())->setEntite($objects[$tenant])->setCreateur($objects['admin'])->setDestinataire($objects['recipient']);
            $objects[$prefix.'ProspectDevis'] = (new Devis())->setEntite($objects[$tenant])->setCreateur($objects['admin'])->setProspect($objects[$prefix.'Prospect']);
        }
        $objects['otherUserDevis'] = (new Devis())->setEntite($objects['tenant'])->setCreateur($objects['admin'])->setDestinataire($objects['admin']);
        $objects['template'] = (new EmailTemplate())->setEntite($objects['tenant'])->setCreateur($objects['admin'])->setCode('privacy-test')->setName('Test')->setSubject('Test')->setBodyHtml('<p>Test</p>');
        foreach ($objects as $object) $this->em->persist($object);
        $this->em->flush();
        foreach ($objects as $key => $object) $this->ids[$key] = $object->getId();
        $this->em->clear();
        $this->client = self::getContainer()->get('test.client');
        $this->client->disableReboot();
        $this->client->catchExceptions(true);
        $this->client->loginUser($this->em->find(Utilisateur::class, $this->ids['admin']));
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $i => $name) {
            if ($this->database[$i] === null) unset($GLOBALS['_'.$name]['DATABASE_URL']);
            else $GLOBALS['_'.$name]['DATABASE_URL'] = $this->database[$i];
        }
    }

    private function base(): string { return '/fr/administrateur/'.$this->ids['tenant']; }
    private function request(string $method, string $url, array $data = []): int
    {
        $this->client->request($method, $url, $data);
        return $this->client->getResponse()->getStatusCode();
    }
    private function token(string $id): string
    {
        $stack = self::getContainer()->get('request_stack');
        $stack->push($this->client->getRequest());
        try { return self::getContainer()->get('security.csrf.token_manager')->getToken($id)->getValue(); }
        finally { $this->client->getRequest()->getSession()->save(); $stack->pop(); }
    }

    public function testEmailWritesRequireScopedCsrfBeforeSendingOrRecording(): void
    {
        $this->mailer->expects(self::never())->method('send');
        $routes = [
            ['/utilisateur/'.$this->ids['recipient'].'/email/send', 'send_email_user_'.$this->ids['tenant'].'_'.$this->ids['recipient']],
            ['/prospection/'.$this->ids['ownProspect'].'/email/send', 'send_email_prospect_'.$this->ids['tenant'].'_'.$this->ids['ownProspect']],
            ['/utilisateur/'.$this->ids['recipient'].'/devis/'.$this->ids['ownUserDevis'].'/send', 'user_devis_send_'.$this->ids['recipient'].'_'.$this->ids['ownUserDevis']],
            ['/prospection/'.$this->ids['ownProspect'].'/devis/'.$this->ids['ownProspectDevis'].'/send', 'prospect_devis_send_'.$this->ids['ownProspect'].'_'.$this->ids['ownProspectDevis']],
        ];
        foreach ($routes as [$route, $tokenId]) {
            foreach ([[], ['_token' => 'invalid']] as $data) {
                self::assertSame(403, $this->request('POST', $this->base().$route, $data), $route);
                self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'));
            }
            $token = $this->token($tokenId);
            self::assertSame(400, $this->request('POST', $this->base().$route, ['_token' => $token, 'to' => 'invalid']), $route);
        }
        self::assertSame(0, $this->em->getRepository(EmailLog::class)->count([]));
    }

    public function testQuoteReadPreviewAndSendCannotChangeRecipientOrTenantInTheUrl(): void
    {
        $this->mailer->expects(self::never())->method('send');
        foreach (['modal' => 'GET', 'preview' => 'POST', 'send' => 'POST'] as $suffix => $method) {
            self::assertSame(403, $this->request($method, $this->base().'/utilisateur/'.$this->ids['recipient'].'/devis/'.$this->ids['otherUserDevis'].'/'.$suffix));
            self::assertSame(404, $this->request($method, $this->base().'/utilisateur/'.$this->ids['recipient'].'/devis/'.$this->ids['foreignUserDevis'].'/'.$suffix));
            self::assertSame(404, $this->request($method, $this->base().'/prospection/'.$this->ids['foreignProspect'].'/devis/'.$this->ids['foreignProspectDevis'].'/'.$suffix));
        }
        foreach ([
            '/utilisateur/'.$this->ids['recipient'].'/devis/'.$this->ids['ownUserDevis'].'/modal',
            '/prospection/'.$this->ids['ownProspect'].'/devis/'.$this->ids['ownProspectDevis'].'/modal',
            '/prospection/'.$this->ids['ownProspect'].'/email/modal',
        ] as $route) {
            self::assertSame(200, $this->request('GET', $this->base().$route));
            self::assertStringContainsString('name="_token"', $this->client->getResponse()->getContent());
        }
    }

    public function testSmtpFailureDoesNotExposeOrStoreTransportPayload(): void
    {
        $this->mailer->expects(self::once())->method('send')->willThrowException(new \RuntimeException('SMTP secret-password recipient@privacy.test smtp://user:secret@host'));
        $url = $this->base().'/utilisateur/'.$this->ids['recipient'].'/email/send';
        self::assertSame(403, $this->request('POST', $url));
        $token = $this->token('send_email_user_'.$this->ids['tenant'].'_'.$this->ids['recipient']);
        self::assertSame(500, $this->request('POST', $url, ['_token' => $token, 'to' => 'recipient@privacy.test', 'templateId' => $this->ids['template'], 'subject' => 'Test', 'message' => 'Test', 'idemKey' => 'privacy-failure']));
        self::assertStringNotContainsString('secret', $this->client->getResponse()->getContent());
        $log = $this->em->getRepository(EmailLog::class)->findOneBy(['idemKey' => 'privacy-failure']);
        self::assertNotNull($log);
        self::assertSame('FAILED', $log->getStatus());
        self::assertStringNotContainsString('secret', $log->getErrorMessage());
        self::assertStringNotContainsString('recipient@', $log->getErrorMessage());
    }

    public function testOcrRequiresTenantPermissionAndCsrfBeforeExternalProcessing(): void
    {
        self::assertSame(403, $this->request('POST', $this->base().'/ocr/extract'));
        $token = $this->token('ocr_extract_'.$this->ids['tenant']);
        self::assertSame(400, $this->request('POST', $this->base().'/ocr/extract', ['_token' => $token]));
        self::assertSame(403, $this->request('POST', '/fr/administrateur/'.$this->ids['foreignTenant'].'/ocr/extract', ['_token' => $token]));
    }
}
