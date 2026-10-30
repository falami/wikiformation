<?php

declare(strict_types=1);
namespace App\Tests\Integration\PasseportPrevention;

use App\Entity\{Entite, Utilisateur, UtilisateurEntite, Session, SessionJour, Site, Inscription};
use App\Enum\{StatusSession, StatusInscription, TypeFinancement};
use App\Service\PasseportPrevention\PasseportPreventionReadiness;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class PasseportPreventionTest extends KernelTestCase
{
    private array $database; private $em; private $client;
    private Entite $entite; private Entite $other; private Utilisateur $admin; private Session $session;
    protected function setUp(): void
    {
        $this->database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel(); $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->admin = (new Utilisateur())->setEmail('admin@passport.test')->setPassword('unused')->setPrenom('Camille')->setNom('Martin');
        $this->entite = (new Entite())->setNom('WikiFormation Démonstration')->setCreateur($this->admin)->setPublic(false);
        $this->other = (new Entite())->setNom('Autre organisme')->setCreateur($this->admin)->setPublic(false);
        foreach ([$this->admin, $this->entite, $this->other] as $e) $this->em->persist($e);
        $this->em->flush(); $this->admin->setEntite($this->entite);
        $this->em->persist((new UtilisateurEntite())->setUtilisateur($this->admin)->setEntite($this->entite)->setCreateur($this->admin)->setRoles(['TENANT_ADMIN']));
        $this->em->persist((new \App\Entity\Billing\EntiteSubscription())->setEntite($this->entite)->setStatus('active'));
        $this->session = $this->createSession($this->entite, 'SES-2026-0042', 'Prévention des risques électriques');
        $this->addLearner($this->session, StatusInscription::TERMINE);
        $this->em->flush();
        $this->client = self::getContainer()->get('test.client'); $this->client->disableReboot(); $this->client->catchExceptions(false);
        $this->client->loginUser($this->admin);
    }
    private function createSession(Entite $entite, string $code, string $title): Session
    {
        $site = (new Site())->setEntite($entite)->setCreateur($this->admin)->setNom('Montpellier')->setSlug(strtolower($code));
        $session = (new Session())->setEntite($entite)->setCreateur($this->admin)->setCode($code)->setFormationIntituleLibre($title)->setSite($site)->setStatus(StatusSession::DONE);
        $session->addJour((new SessionJour())->setEntite($entite)->setCreateur($this->admin)->setDateDebut(new \DateTimeImmutable('2026-10-05 09:00 Europe/Paris'))->setDateFin(new \DateTimeImmutable('2026-10-05 17:00 Europe/Paris')));
        $this->em->persist($site); $this->em->persist($session);
        return $session;
    }
    private function addLearner(Session $session, StatusInscription $status): Inscription
    {
        $i = (new Inscription())->setSession($session)->setEntite($session->getEntite())->setCreateur($this->admin)->setStagiaire($this->admin)->setStatus($status);
        $session->addInscription($i); $this->em->persist($i); return $i;
    }
    protected function tearDown(): void
    {
        parent::tearDown(); foreach (['ENV','SERVER'] as $i => $n) {
            if ($this->database[$i] === null) unset($GLOBALS['_'.$n]['DATABASE_URL']); else $GLOBALS['_'.$n]['DATABASE_URL'] = $this->database[$i];
        }
    }
    private function url(): string { return '/fr/administrateur/'.$this->entite->getId().'/passeport-prevention'; }
    private function form(): array
    {
        $page = $this->client->request('GET', $this->url());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        return ['_token' => $page->filter('form[action$="/export"] input[name="_token"]')->attr('value'), 'mode'=>'manual', 'declarationType'=>'ADF', 'category'=>'2',
            'sessionIds'=>json_encode([$this->session->getId()]), 'eligibilitySst'=>'1', 'eligibilityEvidence'=>'1', 'eligibilityTransferable'=>'1', 'eligibilityAudience'=>'1'];
    }
    public function testScopeAndTechnicalExclusions(): void
    {
        $review = new PasseportPreventionReadiness(); $now = new \DateTimeImmutable('2026-10-30 Europe/Paris');
        self::assertTrue($review->inspect($this->session, $this->entite, $now)['selectable']);
        self::assertFalse($review->inspect($this->session, $this->other, $now)['selectable']);
        foreach ([StatusInscription::ABSENT, StatusInscription::ANNULE, StatusInscription::PREINSCRIT] as $status) $this->addLearner($this->session, $status);
        self::assertCount(1, $review->participants($this->session, $this->entite));
        $this->session->setTypeFinancement(TypeFinancement::OUI);
        self::assertFalse($review->inspect($this->session, $this->entite, $now)['selectable']);
        $this->session->setTypeFinancement(TypeFinancement::NON)->setStatus(StatusSession::DRAFT);
        self::assertFalse($review->inspect($this->session, $this->entite, $now)['selectable']);
        $this->session->setStatus(StatusSession::DONE);
        self::assertFalse($review->inspect($this->session, $this->entite, new \DateTimeImmutable('2026-10-01'))['selectable']);
    }
    public function testExportGuardsAndDownload(): void
    {
        $foreign = $this->createSession($this->other, 'OTHER', 'Autre'); $this->em->flush();
        $form = $this->form();
        $this->client->catchExceptions(true);
        $this->client->request('POST', $this->url().'/export', array_replace($form, ['_token'=>'invalid']));
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', $this->url().'/export', array_replace($form, ['eligibilitySst'=>'0']));
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', $this->url().'/export', array_replace($form, ['mode'=>'period', 'dateFrom'=>'2026-02-31','dateTo'=>'2026-10-30']));
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', $this->url().'/export', array_replace($form, ['sessionIds'=>json_encode([$foreign->getId()])]));
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', $this->url().'/export', $form);
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertInstanceOf(\Symfony\Component\HttpFoundation\BinaryFileResponse::class, $response);
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        if (is_file($response->getFile()->getPathname())) unlink($response->getFile()->getPathname());
    }
    public function testUploadErrorsNeverEchoPersonalData(): void
    {
        $page = $this->client->request('GET', $this->url());
        $token = $page->filter('form[action*="/verifier"] input[name="_token"]')->attr('value');
        $path = tempnam(sys_get_temp_dir(), 'passport-test-');
        file_put_contents($path, "privateNIRName|<script>alert(1)</script>\n");
        $file = new UploadedFile($path, 'controle.csv', 'text/csv', null, true);
        $this->client->request('POST', $this->url().'/verifier', ['_token'=>$token, 'declarationType'=>'ADF'], ['csvFile'=>$file]);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('privateNIRName', $this->client->getResponse()->getContent());
        self::assertFalse(is_file($path));
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'));
    }
    public function testPageExplainsPreparationAndProducesPreview(): void
    {
        $future = $this->createSession($this->entite, 'SES-2026-0043', 'Excel — analyse et tableaux de bord');
        $future->setStatus(StatusSession::DRAFT);
        $this->em->flush();
        $this->client->request('GET', $this->url());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('contentReference', $this->client->getResponse()->getContent());
        if ($dir = getenv('PASSPORT_PREVIEW_DIR')) file_put_contents($dir.'/index.html', $this->client->getResponse()->getContent());
    }
}
