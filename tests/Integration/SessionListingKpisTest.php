<?php

namespace App\Tests\Integration;

use App\Entity\{Entite, Formation, Inscription, Session, SessionJour, Site, Utilisateur, UtilisateurEntite};
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\{KernelBrowser, Test\KernelTestCase};

final class SessionListingKpisTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Entite $entite;
    private Entite $other;
    private Utilisateur $admin;
    private Formation $formation;
    private Site $site;
    private array $originalDatabase;
    private Formation $otherFormation;
    private Formation $foreignFormation;
    private string $futureDate;
    private string $pastDate;

    protected function setUp(): void
    {
        $this->originalDatabase = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        self::getContainer()->set(\Symfony\Component\Mailer\MailerInterface::class, $this->createMock(\Symfony\Component\Mailer\MailerInterface::class));
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->admin = (new Utilisateur())->setEmail('quotes-list@example.test')->setPassword('unused')->setPrenom('Admin')->setNom('Test')->setRoles(['ROLE_SUPER_ADMIN']);
        $this->entite = (new Entite())->setNom('Organisme A')->setPublic(false)->setCreateur($this->admin);
        $this->other = (new Entite())->setNom('Organisme B')->setPublic(false)->setCreateur($this->admin);
        foreach ([$this->admin, $this->entite, $this->other] as $entity) $this->em->persist($entity);
        $this->em->flush();
        $this->admin->setEntite($this->entite);
        $this->em->persist((new UtilisateurEntite())->setCreateur($this->admin)->setUtilisateur($this->admin)->setEntite($this->entite)->setRoles(['TENANT_ADMIN'])->setStatus('active'));
        $plan = (new \App\Entity\Billing\Plan())->setCode('test')->setName('Test');
        $this->em->persist($plan);
        $this->em->persist((new \App\Entity\Billing\EntiteSubscription())->setEntite($this->entite)->setStatus('active')->setPlan($plan));
        $this->formation = (new Formation())->setEntite($this->entite)->setCreateur($this->admin)->setTitre('Habilitation électrique')->setSlug('habilitation-electrique');
        $this->site = (new Site())->setEntite($this->entite)->setCreateur($this->admin)->setNom('Salle test')->setSlug('salle-test');
        $this->em->persist($this->formation);
        $this->em->persist($this->site);
        $this->em->flush();
        $this->otherFormation = (new Formation())->setEntite($this->entite)->setCreateur($this->admin)->setTitre('Bureautique')->setSlug('bureautique');
        $this->foreignFormation = (new Formation())->setEntite($this->other)->setCreateur($this->admin)->setTitre('Confidentiel')->setSlug('confidentiel');
        $trainerUser = (new Utilisateur())->setEmail('trainer-kpi@example.test')->setPassword('unused')->setPrenom('Camille')->setNom('Martin');
        $trainer = (new \App\Entity\Formateur())->setUtilisateur($trainerUser)->setEntite($this->entite)->setCreateur($this->admin);
        foreach ([$this->otherFormation, $this->foreignFormation, $trainerUser, $trainer] as $entity) $this->em->persist($entity);
        $today = new \DateTimeImmutable('today');
        $this->futureDate = $today->modify('+10 days')->format('Y-m-d');
        $this->pastDate = $today->modify('-10 days')->format('Y-m-d');
        $past = $this->session('SES-PAST', $this->formation, \App\Enum\StatusSession::DONE, $this->pastDate);
        $running = $this->session('SES-RUNNING', $this->formation, \App\Enum\StatusSession::PUBLISHED, $today->format('Y-m-d'));
        $running->getJours()->first()->setDateDebut($today->modify('-1 day'));
        $running->getJours()->first()->setDateFin($today->modify('+1 day'));
        $future = $this->session('SES-FUTURE', $this->otherFormation, \App\Enum\StatusSession::DRAFT, $this->futureDate);
        $future->setFormateur($trainer);
        $this->session('SES-CANCELED', $this->formation, \App\Enum\StatusSession::CANCELED, $today->modify('+20 days')->format('Y-m-d'));
        $this->session('SES-FOREIGN', $this->foreignFormation, \App\Enum\StatusSession::DRAFT, $this->futureDate, $this->other);
        $completeInscription = $this->inscription($past);
        $dossier = (new \App\Entity\DossierInscription())->setCreateur($this->admin)->setEntite($this->entite)->setInscription($completeInscription);
        $completeInscription->setDossier($dossier);
        $this->em->persist($dossier);
        $this->em->persist((new \App\Entity\SessionPiece())->setEntite($this->entite)->setCreateur($this->admin)->setSession($past)->setType(\App\Enum\SessionPieceType::CONVENTION_SIGNEE)->setFilename('test-only.pdf'));
        $this->inscription($future);
        $this->em->flush();

    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $i => $type) {
            if ($this->originalDatabase[$i] === null) unset($GLOBALS['_' . $type]['DATABASE_URL']);
            else $GLOBALS['_' . $type]['DATABASE_URL'] = $this->originalDatabase[$i];
        }
    }

    public function testNoneAndMultipleSelectionsMatchListingAndKeepTenantScope(): void
    {
        $f1 = (string)$this->formation->getId();
        $f2 = (string)$this->otherFormation->getId();
        $foreign = (string)$this->foreignFormation->getId();
        foreach ([
            [[], [4, 2, 1, 1]],
            [['statusFilter'=>'[]'], [0, 0, 0, 0]],
            [['formationFilter'=>'[]'], [0, 0, 0, 0]],
            [['statusFilter'=>'["draft","published"]'], [2, 1, 1, 0]],
            [['formationFilter'=>json_encode([$f1, $f2, $foreign])], [4, 2, 1, 1]],
            [['formationFilter'=>$foreign], [0, 0, 0, 0]],
            [['formationFilter'=>$f2, 'statusFilter'=>'["draft","done"]'], [1, 1, 0, 0]],
            // URLs saved before the filter update remain supported.
            [['status'=>'done', 'formation'=>$f1], [1, 0, 0, 1]],
        ] as [$filters, $expected]) {
            $this->assertMatchingCounts($filters, $expected);
        }
    }

    public function testPeriodDossierTrainerAndSearchUseTheSameFilteredSessions(): void
    {
        foreach ([
            [['dateFrom'=>$this->futureDate, 'dateTo'=>$this->futureDate], [1, 1, 0, 0]],
            [['dateTo'=>$this->pastDate], [1, 0, 0, 1]],
            [['dateFrom'=>$this->futureDate], [2, 2, 0, 0]],
            [['formateurFilter'=>'martin'], [1, 1, 0, 0]],
            [['formateurFilter'=>'inconnu'], [0, 0, 0, 0]],
            [['search'=>['value'=>'SES-RUNNING']], [1, 0, 1, 0]],
            [['search'=>['value'=>'Confidentiel']], [0, 0, 0, 0]],
            [['dossierFilter'=>'[]'], [0, 0, 0, 0]],
            [['dossierFilter'=>'complete'], [1, 0, 0, 1]],
            [['dossierFilter'=>'missing'], [1, 1, 0, 0]],
            [['dossierFilter'=>'["complete","missing"]'], [2, 1, 0, 1]],
            [['dossierFilter'=>'["complete","missing"]', 'dateFrom'=>$this->futureDate], [1, 1, 0, 0]],
        ] as [$filters, $expected]) {
            $this->assertMatchingCounts($filters, $expected);
        }
    }

    /** @param array{int,int,int,int} $expected Total, upcoming, running, done. */
    private function assertMatchingCounts(array $filters, array $expected): void
    {
        $client = new KernelBrowser(self::$kernel);
        $client->disableReboot(); $client->catchExceptions(false); $client->loginUser($this->admin);
        $params = ['entite'=>$this->entite->getId()];
        $client->request('POST', self::getContainer()->get('router')->generate('app_administrateur_session_ajax', $params), $filters+['draw'=>1,'start'=>0,'length'=>1]);
        self::assertSame(200, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $rows = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(4, $rows['recordsTotal']);
        self::assertSame($expected[0], $rows['recordsFiltered'], json_encode($filters));
        self::assertCount(min(1, $expected[0]), $rows['data']);
        $client->request('GET', self::getContainer()->get('router')->generate('app_administrateur_session_kpis', $params), $filters);
        self::assertSame(200, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $kpis = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(array_combine(['count','upcoming','running','done'], $expected), $kpis, json_encode($filters));
    }

    private function session(string $code, Formation $formation, \App\Enum\StatusSession $status, string $date, ?Entite $tenant = null): Session
    {
        $tenant ??= $this->entite;
        $session = (new Session())->setEntite($tenant)->setCreateur($this->admin)->setFormation($formation)->setSite($this->site)->setCode($code)->setStatus($status);
        $session->addJour((new SessionJour())->setCreateur($this->admin)->setEntite($tenant)->setDateDebut(new \DateTimeImmutable($date.' 08:30'))->setDateFin(new \DateTimeImmutable($date.' 17:00')));
        $this->em->persist($session);
        return $session;
    }

    private function inscription(Session $session): Inscription
    {
        $inscription = (new Inscription())->setSession($session)->setStagiaire($this->admin)->setCreateur($this->admin)->setEntite($this->entite);
        $this->em->persist($inscription);
        return $inscription;
    }
}
