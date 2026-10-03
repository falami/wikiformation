<?php

namespace App\Tests\Integration;

use App\Entity\{DossierInscription, Emargement, Entite, Formation, Inscription, Session, SessionJour, SessionPiece, Site, Utilisateur, UtilisateurEntite};
use App\Enum\{DemiJournee, SessionPieceType, StatusSession, TypeFinancement};
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
            $this->em->persist((new Emargement())->setSession($session)->setEntite($session->getEntite())->setCreateur($this->admin)->setUtilisateur($this->learner)->setRole('stagiaire')->setDateJour(new \DateTimeImmutable('yesterday'))->setPeriode(DemiJournee::AM));
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
        self::assertSame(200, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $alerts = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['unsignedEmargements'];
        self::assertCount(2, $alerts);
        self::assertSame(['missing', 'unsigned'], array_column($alerts, 'type'));
        self::assertSame(['SES-INTERNAL', 'SES-INTERNAL'], array_column($alerts, 'sessionLabel'));
        $client->request('GET', $this->url('app_administrateur_dashboard_kpis'));
        self::assertSame(200, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
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
        foreach ([SessionPieceType::CONTRAT_FORMATEUR_SIGNE, SessionPieceType::EMARGEMENT_SIGNE, SessionPieceType::COMPTE_RENDU_FORMATEUR, SessionPieceType::COMPTE_RENDU_STAGIAIRE] as $type) {
            $this->piece($this->subcontracted, $type);
        }
        $this->em->flush();
        $client = $this->client();
        foreach (['complete' => 'SES-SUBCONTRACTED', 'missing' => 'SES-INTERNAL'] as $filter => $expectedCode) {
            $client->request('POST', $this->url('app_administrateur_session_ajax'), ['dossierFilter' => $filter, 'length' => 10]);
            self::assertSame(200, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
            $body = $client->getResponse()->getContent();
            $result = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            self::assertArrayNotHasKey('error', $result, $body);
            self::assertSame(1, $result['recordsFiltered']);
            self::assertStringContainsString($expectedCode, $body);
            if ($filter === 'complete') {
                $html = implode(' ', $result['data'][0]);
                self::assertStringContainsString('Compte rendu formateur', $html);
                self::assertStringContainsString('Compte(s) rendu(s) stagiaires', $html);
                self::assertStringContainsString('>OK</span>', $html);
                self::assertStringNotContainsString('Conventions', $html);
                self::assertStringNotContainsString('Factures', $html);
                self::assertStringNotContainsString('À compléter', $html);
                self::assertStringNotContainsString('Émargements: 0/', $html);
            }
        }
    }

    public function testUploadedAttendanceStopsMissingAndUnsignedAlerts(): void
    {
        $this->piece($this->internal, SessionPieceType::EMARGEMENT_SIGNE);
        $this->em->flush();
        $client = $this->client();
        $client->request('GET', $this->url('app_administrateur_dashboard_todo'));
        self::assertSame([], json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['unsignedEmargements']);
        $client->request('GET', $this->url('app_administrateur_dashboard_kpis'));
        self::assertSame(0, json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['unsignedEmargements']);
    }

    public function testClosedSessionsHaveNoDashboardAttendanceAlertsAndKeepTheirRecords(): void
    {
        foreach ([StatusSession::CANCELED, StatusSession::DONE, StatusSession::DRAFT] as $status) {
            $this->internal->setStatus($status);
            if ($status === StatusSession::DRAFT) {
                foreach ($this->internal->getJours() as $jour) {
                    $jour->setDateDebut(new \DateTimeImmutable('yesterday 08:30'))->setDateFin(new \DateTimeImmutable('yesterday 17:00'));
                }
            }
            $this->em->flush();
            $client = $this->client();
            $client->request('GET', $this->url('app_administrateur_dashboard_todo'));
            self::assertSame([], json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['unsignedEmargements']);
            $client->request('GET', $this->url('app_administrateur_dashboard_kpis'));
            self::assertSame(0, json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['unsignedEmargements']);
            self::assertSame(3, $this->em->getRepository(Emargement::class)->count([]));
        }
    }

    public function testSubcontractedChecklistRequiresReportsButNeverConventionsOrInvoices(): void
    {
        $this->piece($this->subcontracted, SessionPieceType::CONTRAT_FORMATEUR_SIGNE);
        $this->piece($this->subcontracted, SessionPieceType::EMARGEMENT_SIGNE);
        $this->piece($this->subcontracted, SessionPieceType::COMPTE_RENDU_FORMATEUR);
        $this->em->flush();
        $client = $this->client();
        $client->request('POST', $this->url('app_administrateur_session_ajax'), ['dossierFilter' => 'missing', 'length' => 10]);
        $result = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(2, $result['recordsFiltered']);
        $row = array_values(array_filter($result['data'], fn ($row) => str_contains(implode(' ', $row), 'SES-SUBCONTRACTED')))[0];
        $html = implode(' ', $row);
        self::assertStringContainsString('Compte(s) rendu(s) stagiaires', $html);
        self::assertStringContainsString('À déposer', $html);
        self::assertStringNotContainsString('Conventions', $html);
        self::assertStringNotContainsString('Factures', $html);
        // Les anciens questionnaires papier restent reconnus comme comptes rendus stagiaires.
        $this->piece($this->subcontracted, SessionPieceType::SATISFACTION_STAGIAIRE);
        $this->em->flush();
        $client->request('POST', $this->url('app_administrateur_session_ajax'), ['dossierFilter' => 'complete', 'length' => 10]);
        $result = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $result['recordsFiltered']);
        self::assertStringContainsString('SES-SUBCONTRACTED', implode(' ', $result['data'][0]));
    }

    public function testCancelledSessionHasNoMissingDocumentsInList(): void
    {
        $this->internal->setStatus(StatusSession::CANCELED);
        $this->em->flush();
        $client = $this->client();
        $client->request('POST', $this->url('app_administrateur_session_ajax'), ['dossierFilter' => 'complete', 'length' => 10]);
        $result = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $result['recordsFiltered']);
        $html = implode(' ', $result['data'][0]);
        self::assertStringContainsString('Session annulée', $html);
        self::assertStringContainsString('Aucun document attendu', $html);
        self::assertStringNotContainsString('À compléter', $html);
    }

    private function piece(Session $session, SessionPieceType $type): void
    {
        $this->em->persist((new SessionPiece())->setSession($session)->setEntite($session->getEntite())->setCreateur($this->admin)->setType($type)->setFilename('test.pdf'));
    }

    private function session(string $code, Entite $entite, bool $subcontracted): Session
    {
        $site = (new Site())->setEntite($entite)->setCreateur($this->admin)->setNom($code)->setSlug(strtolower($code));
        $formation = (new Formation())->setEntite($entite)->setCreateur($this->admin)->setTitre($code)->setSlug(strtolower($code));
        $session = (new Session())->setEntite($entite)->setCreateur($this->admin)->setSite($site)->setCode($code);
        if ($subcontracted) $session->setTypeFinancement(TypeFinancement::OUI)->setFormationIntituleLibre('Formation sous-traitée');
        else $session->setFormation($formation);
        $session->addJour((new SessionJour())->setEntite($entite)->setCreateur($this->admin)->setDateDebut(new \DateTimeImmutable('yesterday 08:30'))->setDateFin(new \DateTimeImmutable('yesterday 17:00')));
        $session->addJour((new SessionJour())->setEntite($entite)->setCreateur($this->admin)->setDateDebut(new \DateTimeImmutable('tomorrow 08:30'))->setDateFin(new \DateTimeImmutable('tomorrow 17:00')));
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
