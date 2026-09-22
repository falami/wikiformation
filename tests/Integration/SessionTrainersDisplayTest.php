<?php

namespace App\Tests\Integration;

use App\Entity\{Entite, Formateur, Formation, Session, SessionJour, Site, Utilisateur, UtilisateurEntite};
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\{KernelBrowser, Test\KernelTestCase};

final class SessionTrainersDisplayTest extends KernelTestCase
{
    private array $originalDatabase;
    private EntityManagerInterface $em;
    private Entite $entite;
    private Utilisateur $admin;
    private Formateur $referent;
    private Formateur $second;
    private Session $session;

    protected function setUp(): void
    {
        $this->originalDatabase = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->admin = (new Utilisateur())->setEmail('admin@example.test')->setPassword('unused')->setPrenom('Admin')->setNom('Test')->setRoles(['ROLE_SUPER_ADMIN']);
        $this->entite = (new Entite())->setNom('Organisme test')->setPublic(false)->setCreateur($this->admin);
        $this->em->persist($this->admin);
        $this->em->persist($this->entite);
        $this->em->flush();
        $this->admin->setEntite($this->entite)->addUtilisateurEntite((new UtilisateurEntite())->setUtilisateur($this->admin)->setEntite($this->entite)->setCreateur($this->admin)->setRoles(['TENANT_ADMIN']));
        $plan = (new \App\Entity\Billing\Plan())->setCode('test-unlimited')->setName('Test');
        $this->em->persist($plan);
        $this->em->persist((new \App\Entity\Billing\EntiteSubscription())->setEntite($this->entite)->setStatus('active')->setPlan($plan));
        $this->referent = $this->trainer('Claire', 'Martin');
        $this->second = $this->trainer('Paul', 'Durand');
        // Une ancienne photo supprimée ne doit pas produire un élément img cassé.
        $this->second->setPhoto('removed-trainer-photo.jpg');
        $this->second->getUtilisateur()->setPhoto('removed-user-photo.jpg');
        $formation = (new Formation())->setEntite($this->entite)->setCreateur($this->admin)->setTitre('Formation test')->setSlug('formation-test');
        $site = (new Site())->setEntite($this->entite)->setCreateur($this->admin)->setNom('Salle test')->setSlug('salle-test');
        $this->em->persist($formation);
        $this->em->persist($site);
        $this->session = (new Session())->setEntite($this->entite)->setCreateur($this->admin)->setFormation($formation)->setSite($site)->setCode('SES-TEST')->setFormateur($this->referent);
        $this->session->addJour($this->slot('09:00', '12:30'));
        $this->session->addJour($this->slot('13:30', '17:00', $this->second));
        $this->em->persist($this->session);
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

    public function testSessionListsEveryTrainerAndTheirOwnSlotsWithLocalInitials(): void
    {
        $crawler = $this->show();
        $trainers = $crawler->filter('#session-trainers');
        self::assertSame(2, $trainers->filter('[data-trainer-id]')->count());
        self::assertStringContainsString('2 formateurs', $trainers->text());
        $referent = $trainers->filter('[data-trainer-id="' . $this->referent->getId() . '"]');
        $second = $trainers->filter('[data-trainer-id="' . $this->second->getId() . '"]');
        self::assertStringContainsString('Claire Martin', $referent->text());
        self::assertStringContainsString('Paul Durand', $second->text());
        self::assertStringContainsString('09:00 – 12:30', $referent->filter('.trainer-slots')->text());
        self::assertStringNotContainsString('13:30', $referent->filter('.trainer-slots')->text());
        self::assertStringContainsString('13:30 – 17:00', $second->filter('.trainer-slots')->text());
        self::assertSame('CM', $referent->filter('.trainer-avatar')->text());
        self::assertSame('PD', $second->filter('.trainer-avatar')->text());
        self::assertSame(0, $trainers->filter('.trainer-avatar img')->count());
        self::assertStringNotContainsString('placeholder.com', $trainers->html());
        self::assertStringNotContainsString('clé navigateur', $trainers->text());
    }

    public function testReferenceTrainerRemainsVisibleWhenEverySlotIsDelegated(): void
    {
        $this->session->getJours()->first()->setFormateur($this->second);
        $this->em->flush();
        $crawler = $this->show();
        $trainers = $crawler->filter('#session-trainers');
        self::assertSame(2, $trainers->filter('[data-trainer-id]')->count());
        $referent = $trainers->filter('[data-trainer-id="' . $this->referent->getId() . '"]');
        self::assertStringContainsString('Référent', $referent->text());
        self::assertStringContainsString('Aucun créneau affecté', $referent->text());
        self::assertSame(0, $referent->filter('form')->count());
        self::assertSame(2, $trainers->filter('[data-trainer-id="' . $this->second->getId() . '"] .trainer-slots li')->count());
    }

    private function show(): \Symfony\Component\DomCrawler\Crawler
    {
        $client = new KernelBrowser(self::$kernel);
        $client->disableReboot();
        $client->catchExceptions(false);
        $client->loginUser($this->admin);
        $url = self::getContainer()->get('router')->generate('app_administrateur_session_show', ['entite' => $this->entite->getId(), 'id' => $this->session->getId()]);
        $crawler = $client->request('GET', $url);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        return $crawler;
    }

    private function trainer(string $first, string $last): Formateur
    {
        $user = (new Utilisateur())->setEntite($this->entite)->setCreateur($this->admin)->setEmail(strtolower($first) . '@example.test')->setPassword('unused')->setPrenom($first)->setNom($last);
        $trainer = (new Formateur())->setEntite($this->entite)->setCreateur($this->admin)->setUtilisateur($user)->setAssujettiTva(false);
        $this->em->persist($user);
        $this->em->persist($trainer);
        return $trainer;
    }

    private function slot(string $start, string $end, ?Formateur $trainer = null): SessionJour
    {
        return (new SessionJour())->setEntite($this->entite)->setCreateur($this->admin)->setDateDebut(new \DateTimeImmutable('2026-10-05 ' . $start))->setDateFin(new \DateTimeImmutable('2026-10-05 ' . $end))->setFormateur($trainer);
    }
}
