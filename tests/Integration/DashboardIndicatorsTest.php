<?php
namespace App\Tests\Integration;
use App\Entity\{Entite, Formation, Inscription, Session, SessionJour, Site, Utilisateur, UtilisateurEntite, Facture, Paiement, Qcm, QcmAssignment, QcmAttempt};
use App\Enum\{StatusSession, TypeFinancement};
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\{KernelBrowser, Test\KernelTestCase};
final class DashboardIndicatorsTest extends KernelTestCase
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
        // Invoice fixtures represent already configured issuers; Stripe readiness has dedicated security tests.
        self::getContainer()->set(\App\Service\EInvoicing\EInvoiceReadiness::class, $this->createMock(\App\Service\EInvoicing\EInvoiceReadiness::class));
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
        $this->internal = $this->session('SES-ONE', $this->entite, false);
        $this->subcontracted = $this->session('SES-TWO', $this->entite, false);
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


    public function testEvaluatedDenominatorDeduplicatesDaysAndQuestionnairesAndSupportsMultiFilters(): void
    {
        $registrations = $this->em->getRepository(Inscription::class)->findBy(['session' => $this->internal]);
        $passed = $registrations[0];
        $passed->setReussi(true);
        $failed = (new Inscription())->setSession($this->internal)->setStagiaire($this->admin)->setEntite($this->entite)->setCreateur($this->admin);
        $this->em->persist($failed);
        $qcm = (new Qcm())->setTitre('Évaluation')->setEntite($this->entite)->setCreateur($this->admin);
        $this->em->persist($qcm);
        $notSubmitted = $this->em->getRepository(Inscription::class)->findOneBy(['session' => $this->subcontracted]);
        $notSubmitted->setReussi(true);
        foreach ([[$passed, true], [$passed, true], [$failed, true], [$notSubmitted, false]] as [$registration, $submitted]) {
            $assignment = (new QcmAssignment())->setSession($registration->getSession())->setInscription($registration)->setQcm($qcm)->setEntite($this->entite)->setCreateur($this->admin);
            $attempt = (new QcmAttempt())->setAssignment($assignment)->setEntite($this->entite)->setCreateur($this->admin)->setSubmittedAt($submitted ? new \DateTimeImmutable() : null);
            $assignment->setAttempt($attempt);
            $this->em->persist($assignment);
            $this->em->persist($attempt);
        }
        $this->em->flush();
        $client = $this->client();
        $filters = ['from' => date('Y').'-01-01', 'to' => date('Y').'-12-31', 'sessionStatus' => '["draft","published"]'];
        $result = $this->getJson($client, 'app_administrateur_dashboard_kpis', $filters);
        self::assertSame(3, $result['inscriptions']);
        self::assertSame(2, $result['evaluated']);
        self::assertSame(1, $result['reussis']);
        self::assertEquals(50, $result['tauxReussite']);
        foreach (['formation', 'formateur', 'sessionStatus', 'inscriptionStatus'] as $key) {
            $none = $this->getJson($client, 'app_administrateur_dashboard_kpis', array_replace($filters, [$key => '[]']));
            self::assertSame(0, $none['evaluated']);
            self::assertNull($none['tauxReussite']);
            self::assertSame(0, $none['caFactureCents']);
        }
        $charts = $this->getJson($client, 'app_administrateur_dashboard_charts', $filters);
        self::assertNotEmpty($charts);
    }

    public function testAccountingParityIncludesStandaloneInvoicesAndUsesPaymentDatesAndHt(): void
    {
        $year = date('Y');
        $invoice = (new Facture())->setEntite($this->entite)->setCreateur($this->admin)->setNumero('FAC-DASH')->setDateEmission(new \DateTimeImmutable($year.'-01-10'))->setMontantHtCents(10000)->setMontantTvaCents(2000)->setMontantTtcCents(15000)->setDestinataire($this->admin);
        $this->em->persist($invoice);
        $other = (new Entite())->setNom('Other')->setCreateur($this->admin)->setPublic(false);
        $this->em->persist($other); $this->em->flush();
        foreach ([[$other, $year.'-01-10'], [$this->entite, ((int) $year - 1).'-01-10']] as [$entity, $date]) {
            $excluded = (new Facture())->setEntite($entity)->setCreateur($this->admin)->setNumero('EXCLUDED-'.$date.$entity->getNom())->setDateEmission(new \DateTimeImmutable($date))->setMontantHtCents(99999)->setMontantTvaCents(0)->setMontantTtcCents(99999)->setDestinataire($this->admin);
            $this->em->persist($excluded);
        }

        $payment = (new Paiement())->setEntite($this->entite)->setCreateur($this->admin)->setFacture($invoice)->setDatePaiement(new \DateTimeImmutable($year.'-02-12'))->setMontantCents(7500)->setVentilationHtHorsDeboursCents(5000)->setVentilationTvaHorsDeboursCents(1000)->setVentilationDeboursCents(1500);
        $this->em->persist($payment);
        $this->em->flush();
        $client = $this->client();
        $filters = ['from' => $year.'-01-01', 'to' => $year.'-12-31'];
        $dashboard = $this->getJson($client, 'app_administrateur_dashboard_kpis', $filters);
        $finance = $this->getJson($client, 'app_administrateur_finance_api_summary', ['start' => $filters['from'], 'end' => $filters['to']]);
        self::assertSame(10000, $dashboard['caFactureCents']);
        self::assertSame(5000, $dashboard['encaisseCents']);
        self::assertSame($finance['kpis']['caHtHorsDebours'], $dashboard['caFactureCents']);
        self::assertSame($finance['kpis']['caHtEncaisse'], $dashboard['encaisseCents']);
        $jan = $this->getJson($client, 'app_administrateur_dashboard_kpis', ['from' => $year.'-01-01', 'to' => $year.'-01-31']);
        self::assertSame(10000, $jan['caFactureCents']);
        self::assertSame(0, $jan['encaisseCents']);
        // A pedagogical filter excludes standalone invoices; clearing it includes them again.
        $filtered = $this->getJson($client, 'app_administrateur_dashboard_kpis', $filters + ['formation' => (string) $this->internal->getFormation()->getId()]);
        self::assertSame(0, $filtered['caFactureCents']);
        $invoice = $this->em->find(Facture::class, $invoice->getId());
        foreach ($this->em->getRepository(Inscription::class)->findAll() as $registration) $invoice->addInscription($registration);
        $this->em->flush();
        $filtered = $this->getJson($client, 'app_administrateur_dashboard_kpis', $filters + ['formation' => json_encode([$this->internal->getFormation()->getId(), $this->subcontracted->getFormation()->getId()])]);
        self::assertSame(10000, $filtered['caFactureCents']);
        self::assertSame(5000, $filtered['encaisseCents']);
        $charts = $this->getJson($client, 'app_administrateur_dashboard_charts', $filters);
        self::assertEquals([50], $charts['cashByMonth']['data']);
    }

    private function getJson(KernelBrowser $client, string $route, array $params): array
    {
        $client->request('GET', $this->url($route), $params);
        self::assertSame(200, $client->getResponse()->getStatusCode(), substr($client->getResponse()->getContent(), 0, 2500));
        return json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
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
        foreach ([$site, $formation, $session, $inscription] as $entity) $this->em->persist($entity);
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
