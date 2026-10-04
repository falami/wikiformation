<?php

namespace App\Tests\Integration;

use App\Entity\{ConventionContrat, Devis, Entite, Formation, Inscription, Session, SessionJour, Site, Utilisateur, UtilisateurEntite};
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\{KernelBrowser, Test\KernelTestCase};

final class DevisListTrainingTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Entite $entite;
    private Entite $other;
    private Utilisateur $admin;
    private Formation $formation;
    private Site $site;
    private array $originalDatabase;

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
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $i => $type) {
            if ($this->originalDatabase[$i] === null) unset($GLOBALS['_' . $type]['DATABASE_URL']);
            else $GLOBALS['_' . $type]['DATABASE_URL'] = $this->originalDatabase[$i];
        }
    }

    public function testListsAllLinkedSessionsOnceAndSupportsSearchWithoutBreakingPagination(): void
    {
        $devis = $this->quote('DEV-MULTIPLE')->setFormation($this->formation)->setMontantTtcCents(50000);
        $first = $this->session('SES-OCT', ['2026-10-07', '2026-10-09']);
        $second = $this->session('SES-NOV', ['2026-11-10']);
        $unplanned = $this->session('SES-UNPLANNED', []);
        foreach ([$first, $first, $second, $unplanned] as $index => $session) {
            $convention = (new ConventionContrat())->setEntite($this->entite)->setCreateur($this->admin)->setSession($session)->setDevis($devis)->setNumero('CONV-' . $index)->setIntituleFormation('H0B0 sur mesure');
            $this->em->persist($convention);
        }
        $this->em->persist((new Inscription())->setEntite($this->entite)->setCreateur($this->admin)->setStagiaire($this->admin)->setSession($first)->addDevi($devis));
        $this->quote('DEV-PLAIN')->setFormation($this->formation)->setMontantTtcCents(10000);
        $this->quote('DEV-FOREIGN', $this->other)->setFormation($this->formation);
        $this->em->flush();
        $client = $this->client();
        $data = $this->table($client, ['search' => ['value' => 'H0B0'], 'length' => 1]);
        self::assertSame(2, $data['recordsTotal']);
        self::assertSame(1, $data['recordsFiltered']);
        self::assertCount(1, $data['data']);
        self::assertSame("Habilitation électrique\nH0B0 sur mesure", $data['data'][0]['formation']);
        self::assertSame("SES-OCT · 07/10/2026 → 09/10/2026\nSES-NOV · 10/11/2026\nSES-UNPLANNED · À planifier", $data['data'][0]['dates']);
        self::assertStringContainsString('disabled', $data['data'][0]['actions']);
        $data = $this->table($client, ['search' => ['value' => 'Habilitation'], 'length' => 1, 'start' => 1, 'order' => [['column' => 0, 'dir' => 'desc']]]);
        self::assertSame(2, $data['recordsFiltered']);
        self::assertSame('DEV-MULTIPLE', $data['data'][0]['numero']);
        self::assertStringNotContainsString('FOREIGN', json_encode($data));
        $data = $this->table($client, ['length' => 1, 'order' => [['column' => 8, 'dir' => 'desc']]]);
        self::assertSame('DEV-MULTIPLE', $data['data'][0]['numero'], 'The TTC sort must use its new column index.');
        $client->request('GET', $this->url('app_administrateur_devis_index'));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('<th>Formation</th>', $client->getResponse()->getContent());
        self::assertStringContainsString('<th>Dates</th>', $client->getResponse()->getContent());
    }

    public function testDirectRegistrationsProvideFallbackTitleAndForeignAssociationsCannotLeak(): void
    {
        $devis = $this->quote('DEV-DIRECT');
        $session = $this->session('SES-DIRECT', ['2026-10-15']);
        $this->em->persist((new Inscription())->setEntite($this->entite)->setCreateur($this->admin)->setStagiaire($this->admin)->setSession($session)->addDevi($devis));
        $foreignFormation = (new Formation())->setEntite($this->other)->setCreateur($this->admin)->setTitre('CONFIDENTIEL')->setSlug('confidentiel');
        $foreignSession = (new Session())->setEntite($this->other)->setCreateur($this->admin)->setFormation($foreignFormation)->setSite($this->site)->setCode('SES-CONFIDENTIEL');
        $this->em->persist($foreignFormation);
        $this->em->persist($foreignSession);
        $this->em->persist((new ConventionContrat())->setEntite($this->other)->setCreateur($this->admin)->setSession($foreignSession)->setDevis($devis)->setNumero('CONV-FOREIGN')->setIntituleFormation('SECRET'));
        $this->em->persist((new Inscription())->setEntite($this->other)->setCreateur($this->admin)->setStagiaire($this->admin)->setSession($foreignSession)->addDevi($devis));
        $this->quote('DEV-UNPLANNED');
        $this->em->flush();
        $client = $this->client();
        $data = $this->table($client, ['search' => ['value' => 'Habilitation']]);
        self::assertSame(1, $data['recordsFiltered']);
        self::assertSame('Habilitation électrique', $data['data'][0]['formation']);
        self::assertSame('15/10/2026', $data['data'][0]['dates']);
        self::assertSame(0, $this->table($client, ['search' => ['value' => 'SECRET']])['recordsFiltered']);
        self::assertSame(0, $this->table($client, ['search' => ['value' => 'CONFIDENTIEL']])['recordsFiltered']);
        $data = $this->table($client, ['search' => ['value' => 'UNPLANNED']]);
        self::assertSame('—', $data['data'][0]['formation']);
        self::assertSame('Non planifiée', $data['data'][0]['dates']);
    }

    public function testQuoteMoneyColumnsAndFormationVat(): void
    {
        $this->quote('DEV-TAX')->setMontantHtCents(10000)->setMontantTvaCents(2000)->setMontantTtcCents(12000);
        $this->formation->setPrixBaseCents(10000)->setTauxTva(5.5);
        $this->em->flush();
        $client = $this->client();
        $row = $this->table($client)['data'][0];
        self::assertSame('100,00 EUR', $row['ht']);
        self::assertSame('20,00 EUR', $row['tva']);
        self::assertSame('120,00 EUR', $row['ttc']);
        $client->request('POST', $this->url('app_administrateur_formation_ajax'));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $row = json_decode($client->getResponse()->getContent(), true)['data'][0];
        self::assertSame('100,00 €', $row['prixBase']);
        self::assertSame('5,50 %', $row['tauxTva']);
        self::assertSame('5,50 €', $row['tva']);
        self::assertSame('105,50 €', $row['ttc']);
        self::assertSame(20.0, (new Formation())->getTauxTva());
        self::assertSame(10000, (new Formation())->setPrixBaseCents(10000)->setTauxTva(0)->getPrixTtcCents());
    }

    public function testTrainerCanReadAndEditOwnReportButNotAnotherTenantReport(): void
    {
        $trainer = (new \App\Entity\Formateur())->setEntite($this->entite)->setCreateur($this->admin)->setUtilisateur($this->admin);
        $session = $this->session('REPORT-SESSION', []);
        $session->setFormateur($trainer);
        $report = (new \App\Entity\RapportFormateur())->setEntite($this->entite)->setCreateur($this->admin)->setFormateur($trainer)->setSession($session)->setSubmittedAt(new \DateTimeImmutable('2026-02-14 00:20'))->setCommentaires('Rapport initial')->setCriteres(['Organisation' => 'Bonne']);
        $this->em->persist($trainer); $this->em->persist($report); $this->em->flush();
        $client = $this->client();
        $base = '/fr/formateur/' . $this->entite->getId() . '/rapport/' . $report->getId();
        $client->request('GET', $base . '/voir');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Rapport initial', $client->getResponse()->getContent());
        self::assertStringContainsString('Organisation', $client->getResponse()->getContent());
        $crawler = $client->request('GET', $base . '/modifier');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $form = $crawler->filter('form[name="rapport_formateur"]')->form();
        $form['rapport_formateur[commentaires]'] = 'Rapport corrigé';
        $form['rapport_formateur[importance]'] = 'urgent';
        $client->submit($form);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $report = $this->em->find(\App\Entity\RapportFormateur::class, $report->getId());
        self::assertSame('Rapport corrigé', $report->getCommentaires());
        self::assertSame('urgent', $report->getImportance());
        self::assertSame(['Organisation' => 'Bonne'], $report->getCriteres());
        self::assertSame('2026-02-14', $report->getSubmittedAt()->format('Y-m-d'));
        $report->setEntite($this->em->find(Entite::class, $this->other->getId())); $this->em->flush();
        $client->catchExceptions(true);
        foreach (['voir', 'modifier'] as $action) {
            $client->request('GET', $base . '/' . $action);
            self::assertSame(404, $client->getResponse()->getStatusCode());
        }
    }

    public function testAdministratorProcessesReportsWithHistoryAndTenantIsolation(): void
    {
        $trainer = (new \App\Entity\Formateur())->setEntite($this->entite)->setCreateur($this->admin)->setUtilisateur($this->admin);
        $report = (new \App\Entity\RapportFormateur())->setEntite($this->entite)->setCreateur($this->admin)->setFormateur($trainer)->setSession($this->session('REPORT-ADMIN', []))->setImportance('urgent')->setSubmittedAt(new \DateTimeImmutable())->setCommentaires('Incident à traiter');
        $this->em->persist($trainer); $this->em->persist($report); $this->em->flush();
        $id = $report->getId();
        $base = '/fr/administrateur/' . $this->entite->getId() . '/rapports-formateurs';
        $client = $this->client();
        $client->request('GET', '/fr/administrateur/' . $this->entite->getId() . '/dashboard');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('1 rapport(s) formateur à traiter', $client->getResponse()->getContent());
        $client->request('GET', $base);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('REPORT-ADMIN', $client->getResponse()->getContent());
        foreach (['in_progress' => 'Contact avec le formateur', 'resolved' => 'Solution mise en place'] as $status => $actions) {
            $crawler = $client->request('GET', $base . '/' . $id . '/traiter');
            self::assertSame(200, $client->getResponse()->getStatusCode());
            $form = $crawler->filter('form[name="form"]')->form();
            $form['form[statut]'] = $status; $form['form[actions]'] = $actions;
            $client->submit($form);
            self::assertSame(302, $client->getResponse()->getStatusCode());
        }
        $client->followRedirect();
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Contact avec le formateur', $client->getResponse()->getContent());
        $report = $this->em->find(\App\Entity\RapportFormateur::class, $id);
        self::assertSame('resolved', $report->getStatutTraitement());
        self::assertCount(2, $report->getHistoriqueTraitement());
        $client->request('GET', $base);
        self::assertStringNotContainsString('REPORT-ADMIN', $client->getResponse()->getContent());
        $client->request('GET', $base . '?statut=all');
        self::assertStringContainsString('REPORT-ADMIN', $client->getResponse()->getContent());
        $report = $this->em->find(\App\Entity\RapportFormateur::class, $id);
        $report->setEntite($this->em->find(Entite::class, $this->other->getId())); $this->em->flush();
        $client->catchExceptions(true);
        $client->request('GET', $base . '/' . $id . '/traiter');
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testQuoteValidityDefaultsToThirtyDaysAndRemainsEditable(): void
    {
        $factory = self::getContainer()->get('form.factory');
        $quote = (new Devis())->setDateEmission(new \DateTimeImmutable('2026-12-15'));
        $form = $factory->create(\App\Form\Administrateur\DevisType::class, $quote, ['entite' => $this->entite]);
        self::assertSame('14/01/2027', $form->get('dateValidite')->getViewData());
        $form->submit(['dateEmission' => '01/02/2028', 'dateValidite' => ''], false);
        self::assertSame('2028-03-02', $quote->getDateValidite()->format('Y-m-d'));
        $quote->setDateValidite(new \DateTimeImmutable('2028-04-01'));
        $form = $factory->create(\App\Form\Administrateur\DevisType::class, $quote, ['entite' => $this->entite]);
        self::assertSame('01/04/2028', $form->get('dateValidite')->getViewData());
        $form->submit(['dateEmission' => '01/02/2028', 'dateValidite' => '15/04/2028'], false);
        self::assertSame('2028-04-15', $quote->getDateValidite()->format('Y-m-d'));
    }

    private function quote(string $numero, ?Entite $entite = null): Devis
    {
        $devis = (new Devis())->setEntite($entite ?? $this->entite)->setCreateur($this->admin)->setNumero($numero)->setDestinataire($this->admin);
        $this->em->persist($devis);
        return $devis;
    }

    private function session(string $code, array $dates): Session
    {
        $session = (new Session())->setEntite($this->entite)->setCreateur($this->admin)->setFormation($this->formation)->setSite($this->site)->setCode($code);
        $this->em->persist($session);
        foreach ($dates as $date) {
            $jour = (new SessionJour())->setEntite($this->entite)->setCreateur($this->admin)->setDateDebut(new \DateTimeImmutable($date . ' 08:30'))->setDateFin(new \DateTimeImmutable($date . ' 17:00'));
            $session->addJour($jour);
        }
        return $session;
    }

    private function client(): KernelBrowser
    {
        $client = new KernelBrowser(self::$kernel);
        $client->disableReboot(); $client->catchExceptions(false); $client->loginUser($this->admin);
        return $client;
    }

    private function url(string $route): string
    {
        return self::getContainer()->get('router')->generate($route, ['entite' => $this->entite->getId()]);
    }

    private function table(KernelBrowser $client, array $parameters = []): array
    {
        $client->request('POST', $this->url('app_administrateur_devis_ajax'), $parameters);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        return json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
