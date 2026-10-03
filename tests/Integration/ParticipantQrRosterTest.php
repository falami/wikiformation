<?php
namespace App\Tests\Integration;

use App\Entity\{ConventionContrat, Entite, Formateur, Formation, Inscription, Session, SessionJour, Site, Utilisateur, UtilisateurEntite};
use App\Entity\Billing\{Plan, EntiteSubscription};
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\{Test\KernelTestCase, KernelBrowser};

final class ParticipantQrRosterTest extends KernelTestCase
{
    private array $database;
    private EntityManagerInterface $em;
    private Entite $entite;
    private Session $session;
    private Utilisateur $trainer;
    private Utilisateur $learner;
    private Utilisateur $outsider;

    protected function setUp(): void
    {
        $this->database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->trainer = (new Utilisateur())->setEmail('trainer-qr@example.test')->setPassword('unused')->setPrenom('Claire')->setNom('Martin')->setRoles(['ROLE_USER']);
        $this->entite = (new Entite())->setNom('Organisme démonstration')->setPublic(false)->setCreateur($this->trainer);
        $this->em->persist($this->trainer); $this->em->persist($this->entite); $this->em->flush();
        $this->membership($this->trainer, 'TENANT_FORMATEUR');
        $formateur = (new Formateur())->setUtilisateur($this->trainer)->setEntite($this->entite)->setCreateur($this->trainer);
        $this->trainer->setFormateur($formateur);
        $this->em->persist($formateur);
        $this->learner = $this->user('Camille', 'Durand', 'TENANT_STAGIAIRE');
        $this->outsider = $this->user('Autre', 'Formateur', 'TENANT_FORMATEUR');
        $outsideTrainer = (new Formateur())->setUtilisateur($this->outsider)->setEntite($this->entite)->setCreateur($this->trainer);
        $this->outsider->setFormateur($outsideTrainer); $this->em->persist($outsideTrainer);
        $formation = (new Formation())->setTitre('Formation SST')->setSlug('qr-sst')->setEntite($this->entite)->setCreateur($this->trainer);
        $site = (new Site())->setNom('Salle de formation')->setSlug('qr-site')->setEntite($this->entite)->setCreateur($this->trainer);
        $this->em->persist($formation); $this->em->persist($site);
        $this->session = (new Session())->setEntite($this->entite)->setCreateur($this->trainer)->setCode('SES-QR-DEMO')->setFormation($formation)->setSite($site)->setFormateur($formateur);
        $this->session->addJour((new SessionJour())->setEntite($this->entite)->setCreateur($this->trainer)->setDateDebut(new \DateTimeImmutable('today 08:30'))->setDateFin(new \DateTimeImmutable('today 23:59')));
        $this->em->persist($this->session);
        $inscription = (new Inscription())->setSession($this->session)->setStagiaire($this->learner)->setEntite($this->entite)->setCreateur($this->trainer);
        $this->em->persist($inscription);
        $convention = (new ConventionContrat())->setSession($this->session)->setEntite($this->entite)->setCreateur($this->trainer)->setNumero('CONV-QR-DEMO')->setParticipantsLibres("Alex Martin\nSam Dupont");
        $this->em->persist($convention);
        $plan = (new Plan())->setCode('qr-demo')->setName('Démo'); $this->em->persist($plan);
        $this->em->persist((new EntiteSubscription())->setEntite($this->entite)->setPlan($plan)->setStatus('active'));
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV','SERVER'] as $i => $scope) {
            if ($this->database[$i] === null) unset($GLOBALS['_'.$scope]['DATABASE_URL']);
            else $GLOBALS['_'.$scope]['DATABASE_URL'] = $this->database[$i];
        }
    }

    public function testTrainerSeesIndividualCodesForEnrolledAndGuestParticipants(): void
    {
        $client = $this->client($this->trainer);
        $crawler = $client->request('GET', $this->url('app_formateur_session_qr'));
        self::assertSame(200, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        self::assertCount(3, $crawler->filter('[data-qr-card]'));
        self::assertCount(6, $crawler->filter('[data-participant-qr]'));
        self::assertStringContainsString('Alex Martin', $crawler->text());
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control'));
        self::assertSame(3, $this->em->getRepository(Utilisateur::class)->count([]), 'Guests must not create login accounts.');
    }

    public function testUnassignedTrainerCannotReadParticipantTokens(): void
    {
        $client = $this->client($this->outsider);
        $client->request('GET', $this->url('app_formateur_session_qr'));
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testLearnerOnlySeesOwnCodesOnDashboardAndPersonalPage(): void
    {
        $client = $this->client($this->learner);
        $crawler = $client->request('GET', $this->url('app_stagiaire_session_qr'));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('[data-qr-card]'));
        self::assertStringNotContainsString('Alex Martin', $crawler->text());
        $crawler = $client->request('GET', self::getContainer()->get('router')->generate('app_stagiaire_dashboard', ['entite' => $this->entite->getId()]));
        self::assertSame(200, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        self::assertCount(2, $crawler->filter('[data-participant-qr]'));
        self::assertStringContainsString('Camille Durand', $crawler->filter('[data-qr-card]')->text());
    }

    private function membership(Utilisateur $user, string $role): void
    {
        $user->setEntite($this->entite);
        $membership = (new UtilisateurEntite())->setUtilisateur($user)->setEntite($this->entite)->setCreateur($this->trainer)->setStatus('active')->setRoles([$role]);
        $user->addUtilisateurEntite($membership); $this->em->persist($membership);
    }
    private function user(string $first, string $last, string $role): Utilisateur
    {
        $user = (new Utilisateur())->setEmail(strtolower($first).'@qr.example.test')->setPrenom($first)->setNom($last)->setPassword('unused')->setRoles(['ROLE_USER']);
        $this->em->persist($user); $this->membership($user, $role); return $user;
    }
    private function client(Utilisateur $user): KernelBrowser
    {
        $client = self::getContainer()->get('test.client'); $client->disableReboot(); $client->loginUser($user); return $client;
    }
    private function url(string $route): string
    {
        return self::getContainer()->get('router')->generate($route, ['entite'=>$this->entite->getId(),'id'=>$this->session->getId()]);
    }
}
