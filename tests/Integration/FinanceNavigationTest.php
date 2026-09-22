<?php

namespace App\Tests\Integration;

use App\Entity\{Avoir, Devis, Entite, Facture, Utilisateur, UtilisateurEntite};
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\{KernelBrowser, Test\KernelTestCase};
use Symfony\Component\DomCrawler\Crawler;

/** Exercise accounting navigation against a disposable database only. */
final class FinanceNavigationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Entite $entite;
    private Entite $other;
    private Utilisateur $admin;
    private Avoir $avoir;
    private Avoir $foreignAvoir;
    private Devis $devis;
    private array $originalDatabase;

    protected function setUp(): void
    {
        $this->originalDatabase = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        self::getContainer()->set(\Symfony\Component\Mailer\MailerInterface::class, $this->createMock(\Symfony\Component\Mailer\MailerInterface::class));
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->admin = (new Utilisateur())->setEmail('finance@example.test')->setPassword('unused')->setPrenom('Finance')->setNom('Test')->setRoles(['ROLE_SUPER_ADMIN']);
        $this->entite = (new Entite())->setNom('Organisme A')->setPublic(false)->setCreateur($this->admin);
        $this->other = (new Entite())->setNom('Organisme B')->setPublic(false)->setCreateur($this->admin);
        foreach ([$this->admin, $this->entite, $this->other] as $entity) $this->em->persist($entity);
        $this->em->flush();
        $this->admin->setEntite($this->entite);
        $this->em->persist((new UtilisateurEntite())->setCreateur($this->admin)->setUtilisateur($this->admin)->setEntite($this->entite)->setRoles(['TENANT_ADMIN'])->setStatus('active'));
        $plan = (new \App\Entity\Billing\Plan())->setCode('test')->setName('Test');
        $this->em->persist($plan);
        $this->em->persist((new \App\Entity\Billing\EntiteSubscription())->setEntite($this->entite)->setStatus('active')->setPlan($plan));
        $facture = (new Facture())->setEntite($this->entite)->setCreateur($this->admin)->setNumero('FAC-AUDIT')->setDateEmission(new \DateTimeImmutable('2026-09-21'))->setMontantHtCents(10000)->setMontantTvaCents(2000)->setMontantTtcCents(12000)->setDestinataire($this->admin);
        $this->em->persist($facture);
        $this->avoir = (new Avoir())->setEntite($this->entite)->setCreateur($this->admin)->setNumero('AV-AUDIT')->setFactureOrigine($facture)->setDateEmission(new \DateTimeImmutable('2026-09-21'))->setMontantTtcCents(1200);
        $this->foreignAvoir = (new Avoir())->setEntite($this->other)->setCreateur($this->admin)->setNumero('AV-FOREIGN')->setDateEmission(new \DateTimeImmutable('2026-09-21'))->setMontantTtcCents(2400);
        $this->devis = (new Devis())->setEntite($this->entite)->setCreateur($this->admin)->setNumero('DEV-AUDIT')->setDestinataire($this->admin)->setPdfPath('uploads/devis/old.pdf');
        foreach ([$this->avoir, $this->foreignAvoir, $this->devis] as $entity) $this->em->persist($entity);
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

    public function testFinanceAndVatCreditTablesSearchAndOpenOnlyOwnDocuments(): void
    {
        $client = $this->client();
        foreach (['app_administrateur_finance_dt_avoirs', 'app_administrateur_tva_dt_avoirs'] as $route) {
            foreach (['', 'AUDIT', 'absent'] as $search) {
                $client->request('GET', $this->url($route), ['search' => ['value' => $search]]);
                self::assertSame(200, $client->getResponse()->getStatusCode());
                $data = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
                self::assertSame($search === 'absent' ? 0 : 1, $data['recordsFiltered']);
                if ($route === 'app_administrateur_finance_dt_avoirs') self::assertSame(1, $data['recordsTotal']);
                self::assertStringNotContainsString('AV-FOREIGN', $client->getResponse()->getContent());
                if ($data['data']) {
                    $actions = (new Crawler(end($data['data'][0])))->filter('a');
                    self::assertSame($this->url('app_administrateur_avoir_show', ['id' => $this->avoir->getId()]), $actions->attr('href'));
                }
            }
        }
        $client->request('GET', $this->url('app_administrateur_avoir_show', ['id' => $this->avoir->getId()]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('AV-AUDIT', $client->getResponse()->getContent());
        $client->catchExceptions(true);
        $client->request('GET', $this->url('app_administrateur_avoir_show', ['id' => $this->foreignAvoir->getId()]));
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testQuoteFinanceActionsUsePostAndAuthenticatedPdf(): void
    {
        $client = $this->client();
        $client->request('GET', $this->url('app_administrateur_finance_dt_devis'));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $actions = new Crawler(end($data['data'][0]));
        self::assertSame('post', $actions->filter('form')->attr('method'));
        self::assertSame($this->url('app_administrateur_devis_to_facture', ['id' => $this->devis->getId()]), $actions->filter('form')->attr('action'));
        self::assertNotEmpty($actions->filter('form input[name="_token"]')->attr('value'));
        self::assertSame($this->url('app_administrateur_devis_pdf', ['id' => $this->devis->getId()]), $actions->filter('a[title="PDF"]')->attr('href'));
        $delete = $actions->filter('button.js-dt-delete');
        $client->request('POST', $delete->attr('data-url'), ['_token' => $delete->attr('data-token')]);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame(0, $this->em->getRepository(Devis::class)->count([]));
    }

    public function testFinanceDateFiltersRejectInvalidDatesAndSupportOneSidedRanges(): void
    {
        $client = $this->client();
        foreach ([['dateStart' => '2026-09-22'], ['dateEnd' => '2026-09-20']] as $filter) {
            $client->request('GET', $this->url('app_administrateur_finance_dt_avoirs'), $filter);
            self::assertSame(200, $client->getResponse()->getStatusCode());
            $data = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(0, $data['recordsFiltered']);
            self::assertSame(1, $data['recordsTotal']);
        }
        $client->catchExceptions(true);
        foreach ([['dateStart' => 'not-a-date'], ['dateStart' => '2026-02-31'], ['dateStart' => '2026-10-01', 'dateEnd' => '2026-09-01']] as $filter) {
            $client->request('GET', $this->url('app_administrateur_finance_dt_avoirs'), $filter);
            self::assertSame(400, $client->getResponse()->getStatusCode());
        }
    }

    public function testIndividualDocumentsOpenAConfirmationAndCreateOnlyOnPost(): void
    {
        $sequence = $this->createMock(\App\Service\Sequence\SequenceNumberManager::class);
        $counter = 0;
        $sequence->method('next')->willReturnCallback(static function () use (&$counter) { return [2026, ++$counter]; });
        self::getContainer()->set(\App\Service\Sequence\ConventionContratNumberGenerator::class, new \App\Service\Sequence\ConventionContratNumberGenerator($sequence));
        $site = (new \App\Entity\Site())->setEntite($this->entite)->setCreateur($this->admin)->setNom('Salle')->setSlug('salle');
        $formation = (new \App\Entity\Formation())->setEntite($this->entite)->setCreateur($this->admin)->setTitre('Formation test')->setSlug('formation-test');
        $session = (new \App\Entity\Session())->setEntite($this->entite)->setCreateur($this->admin)->setSite($site)->setFormation($formation)->setCode('SES-NAVIGATION');
        $company = (new \App\Entity\Entreprise())->setEntite($this->entite)->setCreateur($this->admin)->setRaisonSociale('Employeur');
        foreach ([$site, $formation, $session, $company] as $entity) $this->em->persist($entity);
        $this->em->flush();
        $sessionId = $session->getId();
        $companyId = $company->getId();
        $client = $this->client();
        foreach ([\App\Enum\ModeFinancement::INDIVIDUEL, \App\Enum\ModeFinancement::CPF] as $index => $mode) {
            $this->admin = $this->em->find(Utilisateur::class, $this->admin->getId());
            $this->entite = $this->em->find(Entite::class, $this->entite->getId());
            $session = $this->em->find(\App\Entity\Session::class, $sessionId);
            $company = $this->em->find(\App\Entity\Entreprise::class, $companyId);
            $learner = (new Utilisateur())->setEmail('participant-' . $index . '@example.test')->setPassword('unused')->setPrenom('Stagiaire')->setNom((string) $index);
            $inscription = (new \App\Entity\Inscription())->setEntite($this->entite)->setCreateur($this->admin)->setSession($session)->setStagiaire($learner)->setEntreprise($company)->setModeFinancement($mode);
            $this->em->persist($learner);
            $this->em->persist($inscription);
            $this->em->flush();
            $client->request('GET', $this->url('app_administrateur_inscription_documents_generate', ['id' => $inscription->getId()]));
            self::assertSame(302, $client->getResponse()->getStatusCode());
            $crawler = $client->followRedirect();
            self::assertSame(200, $client->getResponse()->getStatusCode());
            self::assertSame($index, $this->em->getRepository(\App\Entity\ConventionContrat::class)->count([]));
            self::assertSame(0, $this->em->getRepository(\App\Entity\ContratStagiaire::class)->count([]));
            $client->submit($crawler->selectButton('Créer la convention')->form());
            self::assertSame(302, $client->getResponse()->getStatusCode());
            $created = $this->em->getRepository(\App\Entity\ConventionContrat::class)->findOneBy(['stagiaire' => $learner]);
            self::assertNotNull($created);
            self::assertNull($created->getEntreprise(), 'An individual/CPF contract must retain the trainee as its recipient.');
            self::assertCount(1, $created->getInscriptions());
            $client->followRedirect();
            self::assertSame(200, $client->getResponse()->getStatusCode());
        }
    }

    public function testUnimplementedRolePortalsHaveASafeLandingAndInactiveMembershipCannotSwitch(): void
    {
        $membership = $this->em->getRepository(UtilisateurEntite::class)->findOneBy(['utilisateur' => $this->admin]);
        $home = self::getContainer()->get(\App\Security\MembershipHomeRoute::class);
        $request = new \Symfony\Component\HttpFoundation\Request();
        $request->setSession(new \Symfony\Component\HttpFoundation\Session\Session(new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage()));
        foreach ([UtilisateurEntite::TENANT_OPCO, UtilisateurEntite::TENANT_COMMERCIAL] as $role) {
            $membership->setRoles([$role]);
            $this->em->flush();
            self::assertNull($home->forMembership($membership));
            $response = self::getContainer()->get(\App\Security\RedirectAfterLogin::class)->redirect($request, $this->admin);
            self::assertSame(self::getContainer()->get('router')->generate('app_workspace'), $response->getTargetUrl());
        }
        $membership->setRoles([UtilisateurEntite::TENANT_COMMERCIAL, UtilisateurEntite::TENANT_FORMATEUR]);
        self::assertSame('app_formateur_dashboard', $home->forMembership($membership));
        $membership->setRoles(['TENANT_ADMIN']);
        $this->em->flush();
        $client = $this->client();
        $crawler = $client->request('GET', $this->url('app_workspace'));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $form = $crawler->selectButton('Accéder au workspace')->form();
        $membership = $this->em->find(UtilisateurEntite::class, $membership->getId());
        $membership->setStatus(UtilisateurEntite::STATUS_SUSPENDED);
        $this->em->flush();
        self::assertNull($home->forMembership($membership));
        $client->catchExceptions(true);
        $client->submit($form);
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    private function client(): KernelBrowser
    {
        $client = new KernelBrowser(self::$kernel);
        $client->disableReboot(); $client->catchExceptions(false); $client->loginUser($this->admin);
        return $client;
    }

    private function url(string $route, array $parameters = []): string
    {
        return self::getContainer()->get('router')->generate($route, ['entite' => $this->entite->getId()] + $parameters);
    }
}
