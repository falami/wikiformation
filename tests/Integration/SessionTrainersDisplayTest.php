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

    public function testParticipantConventionsShowOnlyActualCoverageAndTotalAmounts(): void
    {
        $learner = (new Utilisateur())->setEntite($this->entite)->setEmail('participant@example.test')->setPassword('unused')->setPrenom('Marine')->setNom('Cardona');
        $other = (new Utilisateur())->setEntite($this->entite)->setEmail('other-participant@example.test')->setPassword('unused')->setPrenom('Alex')->setNom('Martin');
        $company = (new \App\Entity\Entreprise())->setEntite($this->entite)->setCreateur($this->admin)->setRaisonSociale('Entreprise de prise en charge');
        $this->em->persist($learner); $this->em->persist($other); $this->em->persist($company);
        foreach ([$learner, $other] as $person) {
            $inscription = (new \App\Entity\Inscription())->setSession($this->session)->setEntite($this->entite)->setCreateur($this->admin)->setStagiaire($person)->setEntreprise($company);
            $this->session->addInscription($inscription); $this->em->persist($inscription);
        }
        $covered = $this->session->getInscriptions()->first();
        $this->session->setMontantCents(45000);
        $quote = (new \App\Entity\Devis())->setEntite($this->entite)->setCreateur($this->admin)->setNumero('DEV-COVERAGE')->setMontantTtcCents(120000);
        $this->em->persist($quote);
        foreach (['CONV-DEVIS', 'CONV-ESTIMATION'] as $index => $number) {
            $convention = (new \App\Entity\ConventionContrat())->setSession($this->session)->setEntite($this->entite)->setCreateur($this->admin)->setEntreprise($company)->setNumero($number)->setEffectifPrevisionnel(2)->addInscription($covered);
            if ($index === 0) $convention->setDevis($quote);
            $this->em->persist($convention);
        }
        $this->em->flush();
        $map = self::getContainer()->get(\App\Service\Session\ParticipantConventions::class)->forSession($this->session);
        self::assertCount(1, $map);
        self::assertCount(2, $map[$covered->getId()]);
        self::assertSame(120000, $map[$covered->getId()][0]['amount']);
        self::assertSame(90000, $map[$covered->getId()][1]['amount']);
        self::assertTrue($map[$covered->getId()][1]['estimated']);
        $page = $this->show();
        self::assertStringContainsString('Entreprise de prise en charge', $page->filter('[data-participant-conventions]')->first()->text());
        self::assertStringContainsString('1 200,00 € TTC', $page->filter('[data-participant-conventions]')->first()->text());
        $client = self::getContainer()->get('test.client');
        $client->disableReboot(); $client->loginUser($this->admin);
        $page = $client->request('GET', '/fr/administrateur/'.$this->entite->getId().'/session/modifier/'.$this->session->getId());
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(2, $page->filter('[data-saved-conventions] [data-convention-id]'));
        self::assertStringContainsString('900,00 € TTC', $page->filter('[data-saved-conventions]')->text());
        $documentId = $map[$covered->getId()][0]['id'];
        $submitted = $page->filter('form[name="session"]')->form();
        $submitted['session[inscriptions][1][conventionsToAssociate]']->select([(string) $documentId]);
        $client->submit($submitted);
        self::assertSame(302, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $this->em->clear();
        $saved = $this->em->find(\App\Entity\ConventionContrat::class, $documentId);
        self::assertCount(2, $saved->getInscriptions());
        self::assertSame(['Cardona', 'Martin'], $saved->getInscriptions()->map(fn($i) => $i->getStagiaire()->getNom())->toArray());
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

    public function testNoConventionShowsZeroRatherThanThePerParticipantPrice(): void
    {
        $this->session->setMontantCents(90000);
        $this->em->flush();
        $total = $this->show()->filter('#session-conventions-total')->text();
        self::assertStringContainsString('0,00 € TTC', $total);
        self::assertStringContainsString('0 convention rattachée', $total);
        self::assertStringNotContainsString('900,00', $total);
        self::assertStringNotContainsString('par stagiaire', $total);
    }

    public function testConventionTotalsCombineQuotesAndEstimatedHeadcountsWithoutDuplicatingQuotes(): void
    {
        $this->session->setMontantCents(90000);
        $first = (new \App\Entity\Devis())->setEntite($this->entite)->setCreateur($this->admin)->setMontantTtcCents(120000);
        $second = (new \App\Entity\Devis())->setEntite($this->entite)->setCreateur($this->admin)->setMontantTtcCents(180050);
        $this->em->persist($first);
        $this->em->persist($second);
        foreach ([[$first, 8], [$second, 4], [$first, 2], [null, 3]] as $index => [$quote, $headcount]) {
            $convention = (new \App\Entity\ConventionContrat())->setEntite($this->entite)->setCreateur($this->admin)
                ->setSession($this->session)->setNumero('TOTAL-' . $index)->setDevis($quote)->setEffectifPrevisionnel($headcount);
            $this->em->persist($convention);
        }
        // Without a forecast, use the convention's actual named participants.
        $free = (new \App\Entity\ConventionContrat())->setEntite($this->entite)->setCreateur($this->admin)
            ->setSession($this->session)->setNumero('TOTAL-FREE')->setParticipantsLibres("Alice Exemple\nBob Exemple");
        $foreign = (new Entite())->setNom('Autre organisme')->setCreateur($this->admin)->setPublic(false);
        $foreignConvention = (new \App\Entity\ConventionContrat())->setEntite($foreign)->setCreateur($this->admin)
            ->setSession($this->session)->setNumero('TOTAL-FOREIGN')->setEffectifPrevisionnel(50);
        foreach ([$free, $foreign, $foreignConvention] as $record) $this->em->persist($record);
        $this->em->flush();
        $total = $this->show()->filter('#session-conventions-total')->text();
        self::assertStringContainsString('7 500,50 € TTC', $total);
        self::assertStringContainsString('5 conventions rattachées', $total);
        self::assertStringContainsString('2 estimations', $total);
        self::assertStringContainsString('Chaque devis est compté une seule fois', $total);
    }

    public function testConventionTotalsNeverAddDifferentCurrenciesTogether(): void
    {
        foreach (['EUR'=>12000, 'USD'=>25000] as $currency=>$amount) {
            $quote = (new \App\Entity\Devis())->setEntite($this->entite)->setCreateur($this->admin)->setDevise($currency)->setMontantTtcCents($amount);
            $convention = (new \App\Entity\ConventionContrat())->setEntite($this->entite)->setCreateur($this->admin)
                ->setSession($this->session)->setNumero('TOTAL-' . $currency)->setDevis($quote);
            $this->em->persist($quote);
            $this->em->persist($convention);
        }
        $this->em->flush();
        $total = $this->show()->filter('#session-conventions-total')->text();
        self::assertStringContainsString('120,00 € TTC', $total);
        self::assertStringContainsString('250,00 USD TTC', $total);
        self::assertStringNotContainsString('370,00', $total);
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
