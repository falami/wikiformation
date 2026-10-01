<?php

namespace App\Tests\Integration;

use App\Entity\{DossierInscription, Emargement, Entite, Formation, Inscription, Session, SessionJour, SessionPiece, Site, Utilisateur, UtilisateurEntite};
use App\Enum\{DemiJournee, SessionPieceType, TypeFinancement};
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\{KernelBrowser, Test\KernelTestCase};

/** Attendance alerts are tested against an isolated database, including legacy records. */
final class AttendanceScopeTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Entite $entite;
    private Utilisateur $admin;
    private Utilisateur $learner;
    private Session $internal;
    private Session $subcontracted;
    private array $originalDatabase;

    protected function setUp(): void
    {
        $this->originalDatabase = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        self::getContainer()->set(\Symfony\Component\Mailer\MailerInterface::class, $this->createMock(\Symfony\Component\Mailer\MailerInterface::class));
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->admin = (new Utilisateur())->setEmail('attendance-admin@example.test')->setPassword('unused')->setPrenom('Admin')->setNom('Test')->setRoles(['ROLE_SUPER_ADMIN']);
        $this->learner = (new Utilisateur())->setEmail('attendance-learner@example.test')->setPassword('unused')->setPrenom('Camille')->setNom('Test');
        $this->entite = (new Entite())->setNom('Organisme A')->setPublic(false)->setCreateur($this->admin);
        foreach ([$this->admin, $this->learner, $this->entite] as $entity) $this->em->persist($entity);
        $this->em->flush();
        $this->admin->setEntite($this->entite);
        $this->em->persist((new UtilisateurEntite())->setCreateur($this->admin)->setUtilisateur($this->admin)->setEntite($this->entite)->setRoles(['TENANT_ADMIN'])->setStatus('active'));
        $plan = (new \App\Entity\Billing\Plan())->setCode('attendance-test')->setName('Test');
        $this->em->persist($plan);
        $this->em->persist((new \App\Entity\Billing\EntiteSubscription())->setEntite($this->entite)->setStatus('active')->setPlan($plan));
        $this->internal = $this->session('SES-INTERNAL', $this->entite, false);
        $this->subcontracted = $this->session('SES-SUBCONTRACTED', $this->entite, true);
        $other = (new Entite())->setNom('Organisme B')->setPublic(false)->setCreateur($this->admin);
        $this->em->persist($other);
        $foreign = $this->session('SES-FOREIGN', $other, false);
        foreach ([$this->internal, $this->subcontracted, $foreign] as $session) {
            $this->em->persist((new Emargement())->setSession($session)->setEntite($session->getEntite())->setCreateur($this->admin)->setUtilisateur($this->learner)->setRole('stagiaire')->setDateJour(new \DateTimeImmutable('2020-01-06'))->setPeriode(DemiJournee::AM));
        }
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $index => $type) {
            if ($this->originalDatabase[$index] === null) unset($GLOBALS['_' . $type]['DATABASE_URL']);
            else $GLOBALS['_' . $type]['DATABASE_URL'] = $this->originalDatabase[$index];
        }
    }

    public function testDashboardExcludesExternalAttendanceFromMissingAndUnsignedAlerts(): void
    {
        $client = $this->client();
        $client->request('GET', $this->url('app_administrateur_dashboard_todo'));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $alerts = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['unsignedEmargements'];
        self::assertCount(2, $alerts);
        self::assertSame(['missing', 'unsigned'], array_column($alerts, 'type'));
        self::assertSame(['SES-INTERNAL', 'SES-INTERNAL'], array_column($alerts, 'sessionLabel'));
        $client->request('GET', $this->url('app_administrateur_dashboard_kpis'));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame(1, json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['unsignedEmargements']);
        self::assertSame(3, $this->em->getRepository(Emargement::class)->count([]), 'Existing records are retained, including subcontracted sessions.');
    }

    public function testStoredSignatureImageIsNotReportedAsUnsigned(): void
    {
        $this->em->getRepository(Emargement::class)->findOneBy(['session' => $this->internal])->setSignaturePath('signatures/existing.png');
        $this->em->flush();
        $client = $this->client();
        $client->request('GET', $this->url('app_administrateur_dashboard_todo'));
        $alerts = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['unsignedEmargements'];
        self::assertCount(1, $alerts);
        self::assertSame('missing', $alerts[0]['type']);
        $client->request('GET', $this->url('app_administrateur_dashboard_kpis'));
        self::assertSame(0, json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['unsignedEmargements']);
    }

    public function testSessionListDoesNotRequireExternalSignaturesForCompleteness(): void
    {
        self::assertTrue($this->internal->isEmargementRequis());
        self::assertFalse($this->subcontracted->isEmargementRequis());
        $client = $this->client();
        foreach (['complete' => 'SES-SUBCONTRACTED', 'missing' => 'SES-INTERNAL'] as $filter => $expectedCode) {
            $client->request('POST', $this->url('app_administrateur_session_ajax'), ['dossierFilter' => $filter, 'length' => 10]);
            self::assertSame(200, $client->getResponse()->getStatusCode());
            $body = $client->getResponse()->getContent();
            $result = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            self::assertArrayNotHasKey('error', $result, $body);
            self::assertSame(1, $result['recordsFiltered']);
            self::assertStringContainsString($expectedCode, $body);
            if ($filter === 'complete') {
                $html = implode(' ', $result['data'][0]);
                self::assertStringContainsString('Gérés par l’organisme donneur d’ordre', $html);
                self::assertStringNotContainsString('Émargements: 0/', $html);
            }
        }
    }

    private function session(string $code, Entite $entite, bool $subcontracted): Session
    {
        $site = (new Site())->setEntite($entite)->setCreateur($this->admin)->setNom($code)->setSlug(strtolower($code));
        $formation = (new Formation())->setEntite($entite)->setCreateur($this->admin)->setTitre($code)->setSlug(strtolower($code));
        $session = (new Session())->setEntite($entite)->setCreateur($this->admin)->setSite($site)->setCode($code);
        if ($subcontracted) $session->setTypeFinancement(TypeFinancement::OUI)->setFormationIntituleLibre('Formation sous-traitée');
        else $session->setFormation($formation);
        $session->addJour((new SessionJour())->setEntite($entite)->setCreateur($this->admin)->setDateDebut(new \DateTimeImmutable('2020-01-06 08:30'))->setDateFin(new \DateTimeImmutable('2020-01-06 17:00')));
        $inscription = (new Inscription())->setSession($session)->setEntite($entite)->setCreateur($this->admin)->setStagiaire($this->learner);
        $dossier = (new DossierInscription())->setInscription($inscription)->setEntite($entite)->setCreateur($this->admin);
        $piece = (new SessionPiece())->setSession($session)->setEntite($entite)->setCreateur($this->admin)->setType(SessionPieceType::CONVENTION_SIGNEE)->setFilename('convention-test.pdf');
        foreach ([$site, $formation, $session, $inscription, $dossier, $piece] as $entity) $this->em->persist($entity);
        return $session;
    }

    private function client(): KernelBrowser
    {
        $client = self::getContainer()->get('test.client');
        $client->disableReboot();
        $client->catchExceptions(false);
        $client->loginUser($this->admin);
        return $client;
    }

    private function url(string $route): string
    {
        return self::getContainer()->get('router')->generate($route, ['entite' => $this->entite->getId()]);
    }
}
