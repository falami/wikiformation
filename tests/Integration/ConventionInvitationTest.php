<?php

declare(strict_types=1);
namespace App\Tests\Integration;
use App\Entity\{ConventionContrat, ConventionSignatureInvitation, ConventionRevision, Entite, Entreprise, Utilisateur, Session, Site};
use App\Service\Convention\{ConventionInvitation, ConventionDocument};
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mailer\MailerInterface;

final class ConventionInvitationTest extends WebTestCase
{
    private $client;
    private EntityManagerInterface $em;
    private array $database;
    private ConventionContrat $c;
    private Utilisateur $user;
    private array $sent = [];
    private array $files = [];
    protected function setUp(): void
    {
        $this->database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        $this->client = self::createClient(); $this->client->disableReboot();
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function($message){ $this->sent[] = $message; });
        self::getContainer()->set(MailerInterface::class, $mailer);
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->user = (new Utilisateur())->setEmail('rh@example.test')->setPrenom('Contact')->setNom('RH')->setPassword('unused')->setRoles(['ROLE_SUPER_ADMIN']);
        $e = (new Entite())->setNom('Organisme test')->setPublic(false)->setCreateur($this->user);
        $this->em->persist($this->user); $this->em->persist($e); $this->em->flush();
        $company = (new Entreprise())->setEntite($e)->setCreateur($this->user)->setRaisonSociale('Entreprise cliente');
        $this->user->setEntreprise($company)->setEntite($e);
        $this->em->persist((new \App\Entity\UtilisateurEntite())->setUtilisateur($this->user)->setEntite($e)->setCreateur($this->user)->setRoles(['TENANT_ADMIN','TENANT_ENTREPRISE']));
        $site = (new Site())->setEntite($e)->setCreateur($this->user)->setNom('Salle')->setSlug('invitation-test');
        $session = (new Session())->setEntite($e)->setCreateur($this->user)->setCode('SES-INVITE')->setSite($site)->setFormationIntituleLibre('Formation test');
        $this->c = (new ConventionContrat())->setEntite($e)->setCreateur($this->user)->setEntreprise($company)->setSession($session)->setNumero('CONV-INVITE')->setPdfPath('test.pdf')->setPdfGeneratedAt(new \DateTimeImmutable());
        foreach ([$company, $site, $session, $this->c, (new \App\Entity\Billing\EntiteSubscription())->setEntite($e)->setStatus('active')] as $entity) $this->em->persist($entity);
        $this->em->flush();
    }
    protected function tearDown(): void
    {
        foreach ($this->files as $file) if (is_file($file)) unlink($file);
        parent::tearDown();
        foreach (['ENV','SERVER'] as $i=>$type) {
            if ($this->database[$i] === null) unset($GLOBALS['_'.$type]['DATABASE_URL']);
            else $GLOBALS['_'.$type]['DATABASE_URL'] = $this->database[$i];
        }
    }
    private function issue(): string
    {
        self::getContainer()->get(ConventionInvitation::class)->send($this->c, $this->user, 'direction@example.test', 'Alice Direction', 'Directrice');
        $mail = end($this->sent);
        preg_match('~/signature-convention/([a-f0-9]{64})~', $mail->getHtmlBody(), $matches);
        self::assertNotEmpty($matches[1] ?? null);
        self::assertSame('direction@example.test', $mail->getTo()[0]->getAddress());
        self::assertStringContainsString('cid:convention-qr', $mail->getHtmlBody());
        self::assertCount(1, $mail->getAttachments());
        return $matches[1];
    }
    public function testInvitationSignsWithoutAccountAndCannotBeReused(): void
    {
        $token = $this->issue(); $url = '/fr/signature-convention/'.$token;
        $invitation = self::getContainer()->get(ConventionInvitation::class)->resolve($token);
        self::assertNotNull($invitation);
        self::assertEqualsWithDelta(172800, $invitation->getExpiresAt()->getTimestamp() - time(), 5);
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Alice Direction');
        self::assertSame('no-referrer', $this->client->getResponse()->headers->get('Referrer-Policy'));
        $csrf = $crawler->filter('input[name="_token"]')->attr('value');
        $this->client->request('GET', $url.'/document');
        self::assertResponseIsSuccessful();
        $preview = $this->client->getResponse()->getFile()->getPathname(); $this->files[] = $preview;
        $image = imagecreatetruecolor(200,80); ob_start(); imagepng($image); $signature = 'data:image/png;base64,'.base64_encode(ob_get_clean()); imagedestroy($image);
        $this->client->request('POST', $url, ['_token'=>$csrf, 'consent'=>'1', 'signatureData'=>$signature]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Votre signature est enregistrée');
        $this->c = $this->em->find(ConventionContrat::class, $this->c->getId());
        self::assertNotNull($this->c->getDateSignatureEntreprise());
        $this->files[] = self::getContainer()->getParameter('kernel.project_dir').'/var/storage/conventions/'.substr($this->c->getPdfPath(),8);
        $revision = $this->em->getRepository(ConventionRevision::class)->findOneBy(['convention'=>$this->c]);
        self::assertSame('direction@example.test', $revision->getDonnees()['signerEmail']);
        self::assertNull($revision->getDonnees()['actorId']);
        self::assertSame('invitation_png', $revision->getDonnees()['method']);
        foreach (['GET','POST'] as $method) { $this->client->request($method,$url); self::assertResponseStatusCodeSame(410); }
        $this->client->request('GET',$url.'/document'); self::assertResponseStatusCodeSame(410);
    }
    public function testExpiredRevokedAndChangedDocumentsAreRejected(): void
    {
        $hash = self::getContainer()->get(ConventionDocument::class)->fingerprint($this->c);
        $expired = new ConventionSignatureInvitation($this->c,$this->user,hash('sha256',str_repeat('a',64)),'a@example.test','Alice','Direction',$hash,new \DateTimeImmutable('-1 second'));
        $this->em->persist($expired); $this->em->flush();
        $this->client->request('GET','/fr/signature-convention/'.str_repeat('a',64)); self::assertResponseStatusCodeSame(410);
        $token = bin2hex(random_bytes(32));
        $active = new ConventionSignatureInvitation($this->c,$this->user,hash('sha256',$token),'a@example.test','Alice','Direction',$hash,new \DateTimeImmutable('+48 hours'));
        $this->em->persist($active); $this->em->flush();
        $this->c->setNumero('CHANGED'); $this->em->flush();
        self::assertNull(self::getContainer()->get(ConventionInvitation::class)->resolve($token));
        $this->c->setNumero('CONV-INVITE'); $active->revoke(); $this->em->flush();
        self::assertNull(self::getContainer()->get(ConventionInvitation::class)->resolve($token));
        $this->client->request('GET','/fr/signature-convention/'.str_repeat('b',64)); self::assertResponseStatusCodeSame(410);
    }
    public function testInvitationCannotSignWithoutConsentCsrfOrReadingPdf(): void
    {
        $token = $this->issue(); $url = '/fr/signature-convention/'.$token;
        $crawler = $this->client->request('GET', $url);
        $csrf = $crawler->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST', $url, ['_token'=>'invalid', 'consent'=>'1']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', $url, ['_token'=>$csrf]);
        self::assertResponseIsSuccessful(); self::assertSelectorTextContains('[role="alert"]', 'Confirmez votre habilitation');
        $image = imagecreatetruecolor(200,80); ob_start(); imagepng($image); $signature = 'data:image/png;base64,'.base64_encode(ob_get_clean()); imagedestroy($image);
        $this->client->request('POST', $url, ['_token'=>$csrf, 'consent'=>'1', 'signatureData'=>$signature]);
        self::assertResponseIsSuccessful(); self::assertSelectorTextContains('[role="alert"]', 'Consultez à nouveau le PDF');
        self::assertNull($this->em->getConnection()->fetchOne('SELECT date_signature_entreprise FROM convention_contrat WHERE id = ?', [$this->c->getId()]));
    }

    public function testNewInvitationReplacesPreviousAndThrottlesRepeatedSends(): void
    {
        $token = $this->issue();
        $service = self::getContainer()->get(ConventionInvitation::class);
        try { $service->send($this->c, $this->user, 'second@example.test', 'Bob', 'Direction'); self::fail('Repeated send accepted'); }
        catch (\DomainException $error) { self::assertStringContainsString('une minute', $error->getMessage()); }
        $old = $service->resolve($token);
        (new \ReflectionProperty($old, 'createdAt'))->setValue($old, new \DateTimeImmutable('-2 minutes'));
        $this->em->flush();
        $service->send($this->c, $this->user, 'second@example.test', 'Bob', 'Direction');
        self::assertNull($service->resolve($token));
        preg_match('~/signature-convention/([a-f0-9]{64})~', end($this->sent)->getHtmlBody(), $matches);
        self::assertNotNull($service->resolve($matches[1]));
        self::assertNotSame($token, $matches[1]);
    }

    public function testSendRequiresCsrfAndCompanyOwnership(): void
    {
        $this->client->loginUser($this->user);
        $url='/fr/entreprise/'.$this->c->getEntite()->getId().'/convention/'.$this->c->getId();
        $crawler=$this->client->request('GET',$url.'/signature'); self::assertResponseIsSuccessful();
        $csrf=$crawler->filter('[data-invite-token]')->attr('data-invite-token');
        $this->client->request('POST',$url.'/inviter',['_token'=>'bad','name'=>'Alice','position'=>'Direction','email'=>'a@example.test']);
        self::assertResponseStatusCodeSame(403); self::assertCount(0,$this->sent);
        $this->client->request('POST',$url.'/inviter',['_token'=>$csrf,'name'=>'Alice','position'=>'Direction','email'=>'a@example.test']);
        self::assertResponseRedirects(); self::assertCount(1,$this->sent);
        $this->c = $this->em->find(ConventionContrat::class, $this->c->getId());
        $this->user = $this->em->find(Utilisateur::class, $this->user->getId());
        $other=(new Entreprise())->setEntite($this->c->getEntite())->setCreateur($this->user)->setRaisonSociale('Other');
        $this->em->persist($other); $this->c->setEntreprise($other); $this->em->flush();
        $this->client->request('POST',$url.'/inviter',['_token'=>$csrf,'name'=>'Alice','position'=>'Direction','email'=>'a@example.test']);
        self::assertResponseStatusCodeSame(404); self::assertCount(1,$this->sent);
    }
}
