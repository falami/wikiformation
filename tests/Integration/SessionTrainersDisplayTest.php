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
        $numbers = $this->createMock(\App\Service\Sequence\SequenceNumberManager::class);
        $numbers->method('next')->willReturn([2026, 99]);
        self::getContainer()->set(\App\Service\Sequence\ConventionContratNumberGenerator::class, new \App\Service\Sequence\ConventionContratNumberGenerator($numbers));
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
        $quote = (new \App\Entity\Devis())->setEntite($this->entite)->setCreateur($this->admin)->setNumero('DEV-COVERAGE')->setMontantHtCents(100000)->setMontantTtcCents(120000);
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
        self::assertSame(100000, $map[$covered->getId()][0]['amount']);
        self::assertSame(90000, $map[$covered->getId()][1]['amount']);
        self::assertTrue($map[$covered->getId()][1]['estimated']);
        $page = $this->show();
        self::assertStringContainsString('Entreprise de prise en charge', $page->filter('[data-participant-conventions]')->first()->text());
        self::assertStringContainsString('1 000,00 € HT', $page->filter('[data-participant-conventions]')->first()->text());
        $client = self::getContainer()->get('test.client');
        $client->disableReboot(); $client->loginUser($this->admin);
        $page = $client->request('GET', '/fr/administrateur/'.$this->entite->getId().'/session/modifier/'.$this->session->getId());
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(2, $page->filter('[data-saved-conventions] [data-convention-id]'));
        self::assertStringContainsString('900,00 € HT', $page->filter('[data-saved-conventions]')->text());
        $documentId = $map[$covered->getId()][0]['id'];
        $submitted = $page->filter('form[name="session"]')->form();
        $submitted['session[inscriptions][1][conventionsToAssociate]']->select([(string) $documentId]);
        $client->submit($submitted);
        self::assertSame(302, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $this->em->clear();
        $saved = $this->em->find(\App\Entity\ConventionContrat::class, $documentId);
        self::assertCount(2, $saved->getInscriptions());
        self::assertSame(['Cardona', 'Martin'], $saved->getInscriptions()->map(fn($i) => $i->getStagiaire()->getNom())->toArray());
        $page = $client->request('GET', '/fr/administrateur/'.$this->entite->getId().'/session/modifier/'.$this->session->getId());
        $form = $page->filter('form[name="session"]')->form();
        $form['session[inscriptions][0][createConvention]']->tick();
        $form['session[inscriptions][1][createConvention]']->tick();
        $client->submit($form);
        self::assertSame(302, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $this->em->clear();
        $documents = $this->em->getRepository(\App\Entity\ConventionContrat::class)->findBy(['session' => $this->session->getId()]);
        self::assertCount(3, $documents);
        self::assertCount(2, $documents[2]->getInscriptions());
        self::assertFalse($documents[2]->isSigned());

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
        self::assertStringContainsString('0,00 € HT', $total);
        self::assertStringContainsString('0 convention rattachée', $total);
        self::assertStringNotContainsString('900,00', $total);
        self::assertStringNotContainsString('par stagiaire', $total);
    }

    public function testConventionTotalsCombineQuotesAndEstimatedHeadcountsWithoutDuplicatingQuotes(): void
    {
        $this->session->setMontantCents(90000);
        $first = (new \App\Entity\Devis())->setEntite($this->entite)->setCreateur($this->admin)->setMontantHtCents(100000)->setMontantTtcCents(120000);
        $second = (new \App\Entity\Devis())->setEntite($this->entite)->setCreateur($this->admin)->setMontantHtCents(150000)->setMontantTtcCents(180050);
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
        self::assertStringContainsString('7 000,00 € HT', $total);
        self::assertStringContainsString('5 conventions rattachées', $total);
        self::assertStringContainsString('2 estimations', $total);
        self::assertStringContainsString('Chaque devis est compté une seule fois', $total);
    }

    public function testConventionTotalsNeverAddDifferentCurrenciesTogether(): void
    {
        foreach (['EUR'=>12000, 'USD'=>25000] as $currency=>$amount) {
            $quote = (new \App\Entity\Devis())->setEntite($this->entite)->setCreateur($this->admin)->setDevise($currency)->setMontantHtCents($amount)->setMontantTtcCents($amount * 2);
            $convention = (new \App\Entity\ConventionContrat())->setEntite($this->entite)->setCreateur($this->admin)
                ->setSession($this->session)->setNumero('TOTAL-' . $currency)->setDevis($quote);
            $this->em->persist($quote);
            $this->em->persist($convention);
        }
        $this->em->flush();
        $total = $this->show()->filter('#session-conventions-total')->text();
        self::assertStringContainsString('120,00 € HT', $total);
        self::assertStringContainsString('250,00 USD HT', $total);
        self::assertStringNotContainsString('370,00', $total);
    }

    public function testTrainerSessionShowsCompletedRegistrationAndHalfDaySignatures(): void
    {
        $user = $this->referent->getUtilisateur();
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->addUtilisateurEntite((new UtilisateurEntite())->setUtilisateur($user)->setEntite($this->entite)->setCreateur($this->admin)->setRoles(['TENANT_ADMIN']));
        $learner = (new Utilisateur())->setEmail('laurence@example.test')->setPassword('unused')->setPrenom('Laurence')->setNom('Thuret');
        $registration = (new \App\Entity\Inscription())->setSession($this->session)->setEntite($this->entite)->setCreateur($this->admin)->setStagiaire($learner)->setStatus(\App\Enum\StatusInscription::TERMINE);
        $this->session->addInscription($registration);
        $this->em->persist($learner);
        $this->em->persist($registration);
        $signature = (new \App\Entity\Emargement())->setSession($this->session)->setEntite($this->entite)->setCreateur($user)->setUtilisateur($learner)->setRole('stagiaire')->setDateJour(new \DateTimeImmutable('2026-10-05'))->setPeriode(\App\Enum\DemiJournee::AM)->setSignedAt(new \DateTimeImmutable())->setSignatureDataUrl('data:image/png;base64,existing');
        $this->em->persist($signature);
        $this->em->flush();
        $client = new KernelBrowser(self::$kernel);
        $client->disableReboot();
        $client->catchExceptions(false);
        $client->loginUser($user);
        $url = self::getContainer()->get('router')->generate('app_formateur_session_show', ['entite' => $this->entite->getId(), 'id' => $this->session->getId()]);
        $page = $client->request('GET', $url);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Laurence Thuret', $page->text());
        self::assertStringNotContainsString('Aucun stagiaire inscrit.', $page->text());
        self::assertCount(1, $page->filter('img[alt="Signature Matin de Laurence Thuret"]'));
        self::assertCount(1, $page->filter('.trainer-signature[data-period="AM"]'));
        self::assertCount(0, $page->filter('.trainer-signature[data-period="PM"]'));
        $this->session->setTypeFinancement(\App\Enum\TypeFinancement::OUI);
        $this->em->flush();
        $page = $client->request('GET', $url);
        self::assertCount(0, $page->filter('.trainer-signature'));
        $signUrl = self::getContainer()->get('router')->generate('app_formateur_emargement_sign', ['entite' => $this->entite->getId(), 'id' => $this->session->getId()]);
        $client->request('POST', $signUrl, ['date' => '05/10/2026', 'periode' => 'AM', 'signatureData' => 'data:image/png;base64,test']);
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testTrainerSignatureUsesExistingHalfDayStorage(): void
    {
        $user = $this->referent->getUtilisateur();
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->addUtilisateurEntite((new UtilisateurEntite())->setUtilisateur($user)->setEntite($this->entite)->setCreateur($this->admin)->setRoles(['TENANT_ADMIN']));
        foreach ($this->session->getJours() as $slot) {
            $slot->setDateDebut(new \DateTimeImmutable('2107-06-15 ' . $slot->getDateDebut()->format('H:i')));
            $slot->setDateFin(new \DateTimeImmutable('2107-06-15 ' . $slot->getDateFin()->format('H:i')));
        }
        $this->em->flush();
        $path = self::getContainer()->getParameter('kernel.project_dir') . '/public/uploads/emargements/' . $this->session->getId() . '/2107-06-15/trainer-AM-' . $user->getId() . '.png';
        self::assertFileDoesNotExist($path);
        $client = new KernelBrowser(self::$kernel);
        $client->disableReboot();
        $client->catchExceptions(false);
        $client->loginUser($user);
        $url = self::getContainer()->get('router')->generate('app_formateur_session_sign', ['entite' => $this->entite->getId(), 'id' => $this->session->getId()]);
        try {
            $client->request('POST', $url, ['date' => '2107-06-15', 'periode' => 'AM', 'dataUrl' => 'data:image/png;base64,' . base64_encode('test-signature')]);
            self::assertSame(200, $client->getResponse()->getStatusCode());
            self::assertTrue(json_decode($client->getResponse()->getContent(), true)['success']);
            $signature = $this->em->getRepository(\App\Entity\Emargement::class)->findOneBy(['session' => $this->session, 'utilisateur' => $user, 'role' => 'trainer']);
            self::assertNotNull($signature);
            self::assertSame(\App\Enum\DemiJournee::AM, $signature->getPeriode());
            self::assertSame('2107-06-15', $signature->getDateJour()->format('Y-m-d'));
            self::assertFileExists($path);
        } finally {
            if (is_file($path)) unlink($path);
        }
    }

    public function testTraineeUsesCompletedInscriptionAndOnlyActualSignatures(): void
    {
        $user = $this->admin;
        $inscription = (new \App\Entity\Inscription())->setSession($this->session)->setEntite($this->entite)->setCreateur($user)->setStagiaire($user)->setStatus(\App\Enum\StatusInscription::TERMINE);
        $this->em->persist($inscription);
        $signature = (new \App\Entity\Emargement())->setSession($this->session)->setEntite($this->entite)->setCreateur($user)->setUtilisateur($user)->setRole('stagiaire')->setDateJour(new \DateTimeImmutable('2026-10-05'))->setPeriode(\App\Enum\DemiJournee::AM);
        $this->em->persist($signature); $this->em->flush();
        $repo = $this->em->getRepository(\App\Entity\Emargement::class);
        self::assertSame([], $repo->signedPeriodsForUser($this->session, $user));
        $signature->setSignedAt(new \DateTimeImmutable())->setSignatureDataUrl('data:image/png;base64,test');
        $this->em->flush();
        self::assertTrue($repo->signedPeriodsForUser($this->session, $user)['2026-10-05']['AM']);
        $client = new KernelBrowser(self::$kernel); $client->disableReboot(); $client->catchExceptions(false); $client->loginUser($user);
        $params = ['entite' => $this->entite->getId(), 'id' => $this->session->getId()];
        $router = self::getContainer()->get('router');
        $page = $client->request('GET', $router->generate('app_stagiaire_session_show', $params));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('09:00 → 17:00', preg_replace('/\s+/u', ' ', $page->text()));
        $client->request('GET', $router->generate('app_stagiaire_emargement_feed', ['entite' => $this->entite->getId(), 'session' => $this->session->getId(), 'date' => '2026-10-05']));
        self::assertSame('data:image/png;base64,test', json_decode($client->getResponse()->getContent(), true)['me']['am']['url']);
        $url = $router->generate('app_stagiaire_emargement_sign', $params);
        $data = ['date' => '06/10/2026', 'periode' => 'AM', 'signatureData' => 'data:image/png;base64,test'];
        $client->request('POST', $url, $data);
        self::assertSame(400, $client->getResponse()->getStatusCode());
        $this->em->find(Session::class, $this->session->getId())->setTypeFinancement(\App\Enum\TypeFinancement::OUI); $this->em->flush();
        $data['date'] = '05/10/2026';
        $client->request('POST', $url, $data);
        self::assertSame(403, $client->getResponse()->getStatusCode());
        $this->em->remove($this->em->find(\App\Entity\Inscription::class, $inscription->getId())); $this->em->flush();
        $client->request('GET', $router->generate('app_stagiaire_emargement_feed', ['entite' => $this->entite->getId(), 'session' => $this->session->getId()]));
        self::assertSame(403, $client->getResponse()->getStatusCode());
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
