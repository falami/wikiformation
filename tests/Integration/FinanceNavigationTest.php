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
        // Invoice fixtures represent already configured issuers; Stripe readiness has dedicated security tests.
        self::getContainer()->set(\App\Service\EInvoicing\EInvoiceReadiness::class, $this->createMock(\App\Service\EInvoicing\EInvoiceReadiness::class));
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

    public function testSummaryUsesHtAndGroupsCompanyRevenue(): void
    {
        $company = (new \App\Entity\Entreprise())->setEntite($this->entite)->setCreateur($this->admin)->setRaisonSociale('Société exemple');
        $this->em->persist($company);
        $invoice = $this->em->getRepository(Facture::class)->findOneBy(['numero' => 'FAC-AUDIT']);
        $invoice->setDestinataire(null)->setEntrepriseDestinataire($company);
        $this->em->flush();
        $client = $this->client();
        $client->request('GET', $this->url('app_administrateur_finance_api_summary'), ['dateStart'=>'2026-09-01','dateEnd'=>'2026-09-30']);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $charts = json_decode($client->getResponse()->getContent(), true)['charts'];
        self::assertSame(10000, $charts['facMonthly'][0]['ht']);
        self::assertSame(12000, $charts['facMonthly'][0]['ttc']);
        self::assertCount(1, $charts['companyRevenue']);
        self::assertSame('Société exemple', $charts['companyRevenue'][0]['label']);
        self::assertSame(10000, (int) $charts['companyRevenue'][0]['ht']);
        $invoice->setStatus(\App\Enum\FactureStatus::CANCELED);
        $this->em->flush();
        $client->request('GET', $this->url('app_administrateur_finance_api_summary'), ['dateStart'=>'2026-09-01','dateEnd'=>'2026-09-30']);
        self::assertSame([], json_decode($client->getResponse()->getContent(), true)['charts']['companyRevenue']);
    }

    public function testIssuedInvoiceInternalSettingsPreserveDocument(): void
    {
        $invoice = $this->em->getRepository(Facture::class)->findOneBy(['numero' => 'FAC-AUDIT']);
        $id = $invoice->getId();
        $meta = ['invoice_snapshot' => ['original' => true], 'invoice_terms' => ['serviceDate' => '2026-09-21'], 'follow_up' => ['renewalsHandled' => 2], 'follow_up_delivery' => ['status' => 'sent']];
        $invoice->setMeta($meta);
        $this->em->flush();
        $client = $this->client();
        $url = $this->url('app_administrateur_facture_internal_edit', ['id' => $id]);
        $page = $client->request('GET', $url);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $form = $page->selectButton('Enregistrer le suivi')->form();
        $form['form[note]'] = 'Formation à renouveler';
        $form['form[followUp][renewalMonths]'] = '12';
        $form['form[followUp][reminderMonths]'] = '1';
        $client->submit($form);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $this->em->clear();
        $invoice = $this->em->find(Facture::class, $id);
        self::assertSame('Formation à renouveler', $invoice->getNote());
        self::assertSame(12, $invoice->getMeta()['follow_up']['renewalMonths']);
        self::assertSame(2, $invoice->getMeta()['follow_up']['renewalsHandled']);
        self::assertSame($meta['invoice_snapshot'], $invoice->getMeta()['invoice_snapshot']);
        self::assertSame($meta['invoice_terms'], $invoice->getMeta()['invoice_terms']);
        self::assertSame($meta['follow_up_delivery'], $invoice->getMeta()['follow_up_delivery']);
        self::assertSame(12000, $invoice->getMontantTtcCents());
        $client->request('POST', $url, ['form' => ['note' => 'Invalid', '_token' => 'invalid']]);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $client->catchExceptions(true);
        $client->request('GET', '/fr/administrateur/'.$this->other->getId().'/facture/'.$id.'/suivi-interne');
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testQuoteInvoiceDraftCanBeSavedWithoutIssuanceFields(): void
    {
        $this->devis->setStatus(\App\Enum\DevisStatus::SENT);
        $line=(new \App\Entity\LigneDevis())->setEntite($this->entite)->setCreateur($this->admin)->setLabel('Formation')->setQte(1)->setPuHtCents(250000)->setTva(20);
        $this->devis->addLigne($line);
        $this->em->flush();
        $client=$this->client();
        $url='/fr/administrateur/'.$this->entite->getId().'/devis/'.$this->devis->getId().'/facture-brouillon';
        $client->request('GET', $this->url('app_administrateur_finance_dt_devis'));
        $rows = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $actions = new Crawler(end($rows['data'][0]));
        $token = $actions->filter('form[action$="/to-facture"] input')->attr('value');
        $client->request('POST', '/fr/administrateur/'.$this->entite->getId().'/devis/'.$this->devis->getId().'/to-facture', ['_token' => $token]);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('/facture-brouillon', $client->getResponse()->headers->get('Location'));
        self::assertSame(1, $this->em->getRepository(Facture::class)->count([]));
        $page=$client->request('GET',$url);
        self::assertSame(200,$client->getResponse()->getStatusCode());
        $form=$page->filter('form[name="facture"]')->form();
        $form['facture[invoiceTerms][serviceDate]']='';
        $form['facture[invoiceTerms][purchaseOrderReference]']='BON-TEST';
        $client->submit($form);
        self::assertSame(302,$client->getResponse()->getStatusCode());
        self::assertNull($this->devis->getFactureCreee());
        self::assertSame(1,$this->em->getRepository(Facture::class)->count([]));
        $page=$client->request('GET',$url);
        self::assertSame('BON-TEST',$page->filter('[name="facture[invoiceTerms][purchaseOrderReference]"]')->attr('value'));
        $form=$page->filter('form[name="facture"]')->form();
        $client->submit($page->selectButton('Valider et émettre la facture')->form());
        self::assertSame(422,$client->getResponse()->getStatusCode());
        self::assertNull($this->devis->getFactureCreee());

        $this->entite=$this->em->find(Entite::class,$this->entite->getId());
        $this->admin=$this->em->find(Utilisateur::class,$this->admin->getId());
        $this->entite->setAdresse('10 rue du Test')->setCodePostal('75001')->setVille('Paris')->setPays('France')->setSiret('12345678900012')->setNumeroTva('FR12345678901');
        $this->admin->setAdresse('20 rue du Client')->setCodePostal('69001')->setVille('Lyon')->setPays('France');
        $this->em->flush();

        $page=$client->request('GET',$url);
        $form=$page->selectButton('Valider et émettre la facture')->form();
        $form['facture[invoiceTerms][earlyPaymentDiscount]']='Aucun escompte';
        $form['facture[invoiceTerms][legalIdentity]']='Entreprise individuelle';
        $form['facture[invoiceTerms][vatCategory]']='S';
        $form['facture[invoiceTerms][vatOnDebits]']='0';
        $client->submit($form);
        self::assertSame(302,$client->getResponse()->getStatusCode(), strip_tags($client->getResponse()->getContent()));
        $this->devis=$this->em->find(Devis::class,$this->devis->getId());
        self::assertNotNull($this->devis->getFactureCreee());
        self::assertSame('FAC-E'.$this->entite->getId().'-2026-0001',$this->devis->getFactureCreee()->getNumero());
        self::assertArrayNotHasKey('invoice_draft',$this->devis->getMeta());
        $client->request('GET',$url);
        self::assertSame(302,$client->getResponse()->getStatusCode());
        self::assertSame(2,$this->em->getRepository(Facture::class)->count([]));
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

    public function testRolePortalsHaveTheirOwnLandingAndInactiveMembershipCannotSwitch(): void
    {
        $membership = $this->em->getRepository(UtilisateurEntite::class)->findOneBy(['utilisateur' => $this->admin]);
        $home = self::getContainer()->get(\App\Security\MembershipHomeRoute::class);
        $request = new \Symfony\Component\HttpFoundation\Request();
        $request->setSession(new \Symfony\Component\HttpFoundation\Session\Session(new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage()));
        foreach ([UtilisateurEntite::TENANT_OPCO => 'app_opco_dashboard', UtilisateurEntite::TENANT_COMMERCIAL => 'app_commercial_dashboard'] as $role => $route) {
            $membership->setRoles([$role]);
            $this->em->flush();
            self::assertSame($route, $home->forMembership($membership));
            $response = self::getContainer()->get(\App\Security\RedirectAfterLogin::class)->redirect($request, $this->admin);
            self::assertSame(self::getContainer()->get('router')->generate($route, ['entite' => $this->entite->getId()]), $response->getTargetUrl());
        }
        $membership->setRoles([UtilisateurEntite::TENANT_COMMERCIAL, UtilisateurEntite::TENANT_FORMATEUR]);
        self::assertSame('app_commercial_dashboard', $home->forMembership($membership));
        $membership->setRoles(['TENANT_ADMIN']);
        $this->em->flush();
        $client = $this->client();
        $crawler = $client->request('GET', $this->url('app_workspace'));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        // Select the tenant switch form independently of its editorial button label.
        $form = $crawler->filter('form[action$="/workspace/switch/'.$this->entite->getId().'"]')->form();
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
