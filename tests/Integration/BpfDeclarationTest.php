<?php

declare(strict_types=1);
namespace App\Tests\Integration;
use App\Entity\{Entite, Utilisateur, UtilisateurEntite, BpfDeclaration, Session, SessionJour, Facture, AuditLog};
use App\Service\Bpf\{BpfCalculator, BpfSchema};
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BpfDeclarationTest extends KernelTestCase
{
    private array $database;
    private $em;
    private $client;
    private Entite $entite;
    private Utilisateur $user;
    private string $url;
    protected function setUp(): void
    {
        $this->database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->user = (new Utilisateur())->setEmail('bpf@example.test')->setPassword('unused')->setPrenom('Camille')->setNom('Martin')->setRoles(['ROLE_USER']);
        $this->entite = (new Entite())->setNom('Organisme de démonstration')->setPublic(false)->setCreateur($this->user)->setSiret('12345678900012')->setNumeroDeclarant('12345678901')->setFormeJuridique('SAS')->setEmail('contact@example.test');
        $this->em->persist($this->user); $this->em->persist($this->entite); $this->em->flush();
        $this->user->setEntite($this->entite);
        $this->em->persist((new UtilisateurEntite())->setUtilisateur($this->user)->setEntite($this->entite)->setCreateur($this->user)->setRoles(['TENANT_ADMIN']));
        $this->em->persist((new \App\Entity\Billing\EntiteSubscription())->setEntite($this->entite)->setStatus('active'));
        $this->em->flush();
        $this->client = self::getContainer()->get('test.client'); $this->client->disableReboot(); $this->client->catchExceptions(false); $this->client->loginUser($this->user);
        $this->url = '/fr/administrateur/'.$this->entite->getId().'/bpf?start=2025-07-01&end=2026-06-30';
    }
    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV','SERVER'] as $i => $name) {
            if ($this->database[$i] === null) unset($GLOBALS['_'.$name]['DATABASE_URL']); else $GLOBALS['_'.$name]['DATABASE_URL'] = $this->database[$i];
        }
    }
    public function testDraftPersistenceValidationAndPdfUseSavedValues(): void
    {
        $page = $this->client->request('GET', $this->url);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $form = $page->selectButton('Enregistrer la préparation')->form();
        $form['bpf_declaration[c_1]'] = '1250';
        $form['bpf_declaration[f1_a_count]'] = '3';
        $this->client->submit($form);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $draft = $this->em->getRepository(BpfDeclaration::class)->findOneBy(['entite' => $this->entite]);
        self::assertSame(1250, $draft->getData()['c_1']); self::assertSame(0, $draft->getData()['c_a']);
        self::assertArrayHasKey('_generated', $draft->getData());
        self::assertSame('2025-07-01', $draft->getStartDate()->format('Y-m-d'));
        $page = $this->client->request('GET', $this->url);
        if (getenv('BPF_REVIEW_DIR')) file_put_contents(getenv('BPF_REVIEW_DIR').'/bpf-preview.html', $this->client->getResponse()->getContent());
        $pdf = $page->selectLink('Exporter la version enregistrée')->link()->getUri();
        $this->client->request('GET', $pdf);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringStartsWith('%PDF-', $this->client->getResponse()->getContent());
        if (getenv('BPF_REVIEW_DIR')) file_put_contents(getenv('BPF_REVIEW_DIR').'/bpf-preview.pdf', $this->client->getResponse()->getContent());
        $page = $this->client->request('GET', $this->url);
        $invalid = $page->selectButton('Enregistrer la préparation')->form(); $invalid['bpf_declaration[c_1]'] = '-10';
        $this->client->submit($invalid); self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(1250, $draft->getData()['c_1']);
        $page = $this->client->request('GET', $this->url);
        $stale = $page->selectButton('Enregistrer la préparation')->form();
        $stale['bpf_declaration[_version]'] = '0'; $stale['bpf_declaration[c_1]'] = '50';
        $this->client->submit($stale);
        self::assertStringContainsString('Une autre personne', $this->client->getResponse()->getContent());
        self::assertSame(1250, $draft->getData()['c_1']);
    }
    public function testCsrfTenantIsolationAndInvalidDates(): void
    {
        $page = $this->client->request('GET', $this->url);
        $form = $page->selectButton('Enregistrer la préparation')->form(); $form['bpf_declaration[_token]'] = 'invalid';
        $this->client->submit($form);
        self::assertCount(0, $this->em->getRepository(BpfDeclaration::class)->findAll());
        self::assertCount(0, $this->em->getRepository(AuditLog::class)->findAll());
        $other = (new Entite())->setNom('Autre organisme')->setPublic(false)->setCreateur($this->em->find(Utilisateur::class, $this->user->getId()));
        $this->em->persist($other); $this->em->flush();
        $this->client->catchExceptions(true);
        $this->client->request('GET', '/fr/administrateur/'.$other->getId().'/bpf');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', '/fr/administrateur/'.$this->entite->getId().'/bpf?start=2026-02-31&end=2026-06-30');
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }
    public function testSavedVersionsPreservePriorValuesAndCannotCrossPeriodsOrTenants(): void
    {
        $renderedHtml = null;
        $pdf = $this->createMock(\App\Service\Pdf\PdfManager::class);
        $pdf->method('createPortrait')->willReturnCallback(static function (string $html, string $name) use (&$renderedHtml): \Symfony\Component\HttpFoundation\Response {
            $renderedHtml = $html;
            return new \Symfony\Component\HttpFoundation\Response('%PDF-test');
        });
        self::getContainer()->set(\App\Service\Pdf\PdfManager::class, $pdf);
        $page = $this->client->request('GET', $this->url);
        $form = $page->selectButton('Enregistrer la préparation')->form(['bpf_declaration[c_1]' => '1250']);
        $this->client->submit($form);
        $draft = $this->em->getRepository(BpfDeclaration::class)->findOneBy(['entite' => $this->entite]);
        $eventName = 'BPF_VERSION_'.$draft->getId();
        $first = $this->em->getRepository(AuditLog::class)->findOneBy(['event' => $eventName]);
        self::assertSame(1250, $first->getPayload()['data']['c_1']);
        $page = $this->client->request('GET', $this->url);
        $this->client->submit($page->selectButton('Enregistrer la préparation')->form(['bpf_declaration[c_1]' => '1500']));
        $this->em->clear();
        $events = $this->em->getRepository(AuditLog::class)->findBy(['event' => $eventName], ['id' => 'ASC']);
        self::assertCount(2, $events);
        self::assertSame(1250, $events[0]->getPayload()['data']['c_1']);
        self::assertSame(1500, $events[1]->getPayload()['data']['c_1']);
        self::assertSame(2, $events[1]->getPayload()['version']);
        self::assertArrayHasKey('evidence', $events[0]->getPayload()['data']['_generated']);
        $this->entite = $this->em->find(Entite::class, $this->entite->getId());
        $this->entite->setNom('Nouvelle dénomination');
        $this->em->flush();
        $pdfBase = '/fr/administrateur/'.$this->entite->getId().'/bpf/pdf?start=2025-07-01&end=2026-06-30&revision=';
        $this->client->request('GET', $pdfBase.$events[0]->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringStartsWith('%PDF-', $this->client->getResponse()->getContent());
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'));
        self::assertStringContainsString('1 250 €', $renderedHtml);
        self::assertStringNotContainsString('1 500 €', $renderedHtml);
        self::assertStringContainsString('Organisme de démonstration', $renderedHtml);
        self::assertStringNotContainsString('Nouvelle dénomination', $renderedHtml);
        self::assertStringContainsString('Version 1', $renderedHtml);
        self::assertStringContainsString('BPF-'.$this->entite->getId().'-2026-v1', $renderedHtml);
        self::assertStringContainsString('01/07/2025', $renderedHtml);
        self::assertStringContainsString('30/06/2026', $renderedHtml);
        $this->entite = $this->em->find(Entite::class, $this->entite->getId());
        $this->user = $this->em->find(Utilisateur::class, $this->user->getId());
        $other = (new Entite())->setNom('Autre organisme')->setPublic(false)->setCreateur($this->user);
        $this->em->persist($other);
        $foreign = (new AuditLog())->setEntite($other)->setCreateur($this->user)->setActor($this->user)->setEvent($eventName)->setCreatedAt(new \DateTimeImmutable())->setPayload($events[0]->getPayload());
        $wrongPeriod = (new AuditLog())->setEntite($this->entite)->setCreateur($this->user)->setActor($this->user)->setEvent('BPF_VERSION_999999')->setCreatedAt(new \DateTimeImmutable())->setPayload($events[0]->getPayload());
        $this->em->persist($foreign); $this->em->persist($wrongPeriod); $this->em->flush();
        $this->client->catchExceptions(true);
        foreach ([$foreign, $wrongPeriod] as $event) {
            $this->client->request('GET', $pdfBase.$event->getId());
            self::assertSame(404, $this->client->getResponse()->getStatusCode());
        }
    }
    public function testSourceIncludesInvoicesWithoutSessionsAndFreeTitleButExcludesDraftAndFuture(): void
    {
        $invoice = (new Facture())->setEntite($this->entite)->setCreateur($this->user)->setNumero('F-BPF')->setDateEmission(new \DateTimeImmutable('2025-10-01'))->setMontantHtCents(10000)->setMontantTvaCents(2000)->setMontantTtcCents(12000);
        $this->em->persist($invoice); $this->em->flush();
        $calc = self::getContainer()->get(BpfCalculator::class);
        $start = new \DateTimeImmutable('2025-07-01'); $end = new \DateTimeImmutable('2026-06-30');
        self::assertSame(10000, $calc->computeForPeriod($this->entite, $start, $end)['produits']['htCents']);
        $site = (new \App\Entity\Site())->setNom('Salle BPF')->setSlug('salle-bpf')->setEntite($this->entite)->setCreateur($this->user);
        $this->em->persist($site);
        foreach ([\App\Enum\StatusSession::DONE, \App\Enum\StatusSession::DRAFT, \App\Enum\StatusSession::CANCELED] as $i => $status) {
            $session = (new Session())->setEntite($this->entite)->setCreateur($this->user)->setCode('BPF-'.$i)->setSite($site)->setFormationIntituleLibre('Formation libre')->setStatus($status);
            $session->addJour((new SessionJour())->setEntite($this->entite)->setCreateur($this->user)->setDateDebut(new \DateTimeImmutable('2025-09-10 09:00'))->setDateFin(new \DateTimeImmutable('2025-09-10 12:00')));
            $this->em->persist($session);
        }
        $this->em->flush(); $result = $calc->computeForPeriod($this->entite, $start, $end);
        self::assertSame(1, $result['nbSessions']); self::assertSame(3.0, $result['heuresFormation']);
        self::assertSame('Formation libre', $result['formations'][0]['titre']);
    }
    public function testGenerationClassificationsAndManualCorrectionsSurviveRefresh(): void
    {
        $site = (new \App\Entity\Site())->setNom('Salle')->setSlug('bpf-auto')->setEntite($this->entite)->setCreateur($this->user);
        $session = (new Session())->setEntite($this->entite)->setCreateur($this->user)->setCode('AUTO-BPF')->setSite($site)->setFormationIntituleLibre('Bureautique')->setStatus(\App\Enum\StatusSession::DONE);
        $jour = (new SessionJour())->setEntite($this->entite)->setCreateur($this->user)->setDateDebut(new \DateTimeImmutable('2025-09-10 09:00'))->setDateFin(new \DateTimeImmutable('2025-09-10 12:00'));
        $session->addJour($jour);
        $inscription = (new \App\Entity\Inscription())->setSession($session)->setEntite($this->entite)->setCreateur($this->user)->setStagiaire($this->user)->setStatus(\App\Enum\StatusInscription::TERMINE)->setModeFinancement(\App\Enum\ModeFinancement::ENTREPRISE);
        $inscription->enregistrerPresenceManuelle('2025-09-10:AM', 'paper', 'Feuille test', $this->user);
        $session->addInscription($inscription);
        $invoice = (new Facture())->setEntite($this->entite)->setCreateur($this->user)->setNumero('AUTO-F')->setDateEmission(new \DateTimeImmutable('2025-10-01'))->setMontantHtCents(10000)->setMontantTvaCents(2000)->setMontantTtcCents(12000)->addInscription($inscription);
        foreach ([$site, $session, $inscription, $invoice] as $record) $this->em->persist($record);
        $this->em->flush();
        $generator = self::getContainer()->get(\App\Service\Bpf\BpfPrefill::class);
        $rules = ['rule_invoice_'.$invoice->getId() => 'c_1', 'rule_formation_session_'.$session->getId().'_objective' => 'f3_d', 'rule_formation_session_'.$session->getId().'_nsf' => '324', 'rule_inscription_'.$inscription->getId().'_public' => 'a'];
        $result = $generator->generate($this->entite, new \DateTimeImmutable('2025-07-01'), new \DateTimeImmutable('2026-06-30'), $rules);
        self::assertSame(100, $result['data']['c_1']);
        self::assertSame(1, $result['data']['f1_a_count']); self::assertSame(3, $result['data']['f1_a_hours']);
        self::assertSame(3, $result['data']['f3_d_hours']); self::assertSame('324', $result['data']['f4_1_code']);
        self::assertSame(0, $result['data']['g_count']);
        $page = $this->client->request('GET', $this->url);
        $form = $page->selectButton('Générer et enregistrer mon BPF')->form();
        foreach ($rules as $key => $value) $form['bpf_declaration['.$key.']'] = $value;
        $form['bpf_declaration[c_1]'] = '150'; // accountant's correction must win
        $this->client->submit($form);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $draft = $this->em->getRepository(BpfDeclaration::class)->findOneBy(['entite' => $this->entite]);
        self::assertSame(150, $draft->getData()['c_1']); self::assertSame(3, $draft->getData()['f1_a_hours']);
        self::assertSame(100, $draft->getData()['_generated']['baseline']['c_1']);
        $page = $this->client->request('GET', $this->url);
        $this->client->submit($page->selectButton('Générer et enregistrer mon BPF')->form());
        self::assertSame(150, $draft->getData()['c_1']);
        if (getenv('BPF_REVIEW_DIR')) {
            $this->client->request('GET', $this->url);
            file_put_contents(getenv('BPF_REVIEW_DIR').'/bpf-generated.html', $this->client->getResponse()->getContent());
            $this->client->request('GET', '/fr/administrateur/'.$this->entite->getId().'/bpf/pdf?start=2025-07-01&end=2026-06-30');
            file_put_contents(getenv('BPF_REVIEW_DIR').'/bpf-generated.pdf', $this->client->getResponse()->getContent());
        }
        // Exact boundaries and absence of speculative attendance.
        $outside = $generator->generate($this->entite, new \DateTimeImmutable('2025-10-01'), new \DateTimeImmutable('2025-10-31'), $rules);
        self::assertSame(0, $outside['data']['f1_a_count']); self::assertSame(100, $outside['data']['c_1']);
    }
    public function testFinancialPrefillSubtractsFullCreditsAndClassifiesExpenses(): void
    {
        $invoice = (new Facture())->setEntite($this->entite)->setCreateur($this->user)->setNumero('CREDIT-F')->setDateEmission(new \DateTimeImmutable('2025-10-01'))->setMontantHtCents(10000)->setMontantTvaCents(2000)->setMontantTtcCents(12000);
        $credit = (new \App\Entity\Avoir())->setEntite($this->entite)->setCreateur($this->user)->setNumero('CREDIT-A')->setFactureOrigine($invoice)->setDateEmission(new \DateTimeImmutable('2025-11-01'))->setMontantTtcCents(12000);
        $expense = (new \App\Entity\Depense())->setEntite($this->entite)->setCreateur($this->user)->setLibelle('Honoraires')->setDateDepense(new \DateTimeImmutable('2025-11-01'))->setMontantHtCents(45000);
        foreach ([$invoice,$credit,$expense] as $item) $this->em->persist($item);
        $this->em->flush();
        $rules = ['rule_invoice_'.$invoice->getId() => 'c_1', 'rule_expense_'.$expense->getId() => 'd_purchase'];
        $generator = self::getContainer()->get(\App\Service\Bpf\BpfPrefill::class);
        $start = new \DateTimeImmutable('2025-07-01'); $end = new \DateTimeImmutable('2026-06-30');
        $result = $generator->generate($this->entite, $start, $end, $rules);
        self::assertSame(0, $result['data']['c_1']); self::assertSame(360, $result['data']['d_purchase']); self::assertSame(360, $result['data']['d_total']);
        foreach ([0 => 0, 50 => 225, 100 => 450] as $pct => $expected) {
            $expense->setTvaDeductiblePct($pct)->setTvaDeductible(true);
            $this->em->flush();
            $allocated = $generator->generate($this->entite, $start, $end, $rules);
            self::assertSame($expected, $allocated['data']['d_total']);
            self::assertSame($expected, $allocated['data']['d_purchase']);
        }
        $expense->setTvaDeductible(false);
        $this->em->flush();
        $excluded = $generator->generate($this->entite, $start, $end, ['rule_invoice_'.$invoice->getId() => 'c_1']);
        self::assertSame(0, $excluded['data']['d_total']);
        self::assertSame(0, $excluded['data']['d_purchase']);
        self::assertStringContainsString('Déd. = Non', implode(' ', array_column($excluded['evidence'], 'detail')));
        $credit->setMontantTtcCents(6000);
        $result = $generator->generate($this->entite, $start, $end, $rules);
        self::assertSame(100, $result['data']['c_1']);
        self::assertStringContainsString('ventilation à déduire manuellement', implode(' ', $result['issues']));
        $other = (new Entite())->setNom('Isolé')->setPublic(false)->setCreateur($this->user);
        $this->em->persist($other); $this->em->flush();
        $result = $generator->generate($other, $start, $end, $rules);
        self::assertSame(0, $result['data']['c_1']); self::assertSame(0, $result['data']['d_total']); self::assertCount(0, $result['evidence']);
    }

    public function testGenerationDoesNotInventFinancePublicOrUndeliveredTrainerHours(): void
    {
        $site = (new \App\Entity\Site())->setNom('Salle')->setSlug('bpf-audit')->setEntite($this->entite)->setCreateur($this->user);
        $trainer = (new \App\Entity\Formateur())->setEntite($this->entite)->setCreateur($this->user)->setUtilisateur($this->user);
        $second = (new Utilisateur())->setEmail('second@example.test')->setPassword('unused')->setPrenom('Alex')->setNom('Test');
        $session = (new Session())->setEntite($this->entite)->setCreateur($this->user)->setCode('AUDIT-BPF')->setSite($site)->setFormateur($trainer)->setFormationIntituleLibre('Formation test')->setStatus(\App\Enum\StatusSession::DONE);
        foreach (['2025-10-01', '2025-10-02'] as $date) $session->addJour((new SessionJour())->setEntite($this->entite)->setCreateur($this->user)->setDateDebut(new \DateTimeImmutable($date.' 09:00'))->setDateFin(new \DateTimeImmutable($date.' 10:30')));
        $inscriptions = [];
        foreach ([$this->user, $second] as $person) {
            $i = (new \App\Entity\Inscription())->setSession($session)->setEntite($this->entite)->setCreateur($this->user)->setStagiaire($person)->setStatus(\App\Enum\StatusInscription::TERMINE)->setModeFinancement(\App\Enum\ModeFinancement::CPF);
            $i->enregistrerPresenceManuelle('2025-10-01:AM', 'paper', 'Feuille test', $this->user);
            $session->addInscription($i); $inscriptions[] = $i; $this->em->persist($i);
        }
        // This invoice could be a personal CPF co-payment, not CDC funding.
        $invoice = (new Facture())->setEntite($this->entite)->setCreateur($this->user)->setNumero('AUDIT-F')->setDestinataire($this->user)->setDateEmission(new \DateTimeImmutable('2025-10-01'))->setMontantHtCents(10000)->setMontantTvaCents(0)->setMontantTtcCents(10000)->addInscription($inscriptions[0]);
        foreach ([$site, $trainer, $second, $session, $invoice] as $record) $this->em->persist($record);
        $this->em->flush();
        $generator = self::getContainer()->get(\App\Service\Bpf\BpfPrefill::class);
        $rules = ['rule_formation_session_'.$session->getId().'_objective' => 'f3_d', 'rule_formation_session_'.$session->getId().'_nsf' => '324', 'rule_trainer_'.$trainer->getId() => 'internal', 'rule_session_'.$session->getId().'_outsourced' => 'no'];
        $start = new \DateTimeImmutable('2025-10-01');
        $unknown = $generator->generate($this->entite, $start, $start, $rules);
        self::assertNull($unknown['data']['c_e']);
        self::assertNull($unknown['data']['f1_a_count']);
        self::assertContains('C', $unknown['incompleteSections']);
        self::assertContains('F1', $unknown['incompleteSections']);
        $rules['rule_inscription_'.$inscriptions[0]->getId().'_public'] = 'a';
        $rules['rule_inscription_'.$inscriptions[1]->getId().'_public'] = 'd';
        $classified = $generator->generate($this->entite, $start, $start, $rules);
        $totals = BpfSchema::totals($classified['data']);
        self::assertSame(3, $totals['f1_hours']);
        self::assertSame(3, $totals['f3_hours']);
        self::assertSame(3, $totals['f4_hours']);
        self::assertSame(2, $totals['f1_count']);
        $partial = $generator->generate($this->entite, $start, new \DateTimeImmutable('2025-10-02'), $rules);
        self::assertSame(2, $partial['data']['e_internal_hours']); // Only the documented first day, not both planned days.
        self::assertContains('F1', $partial['incompleteSections']);
        self::assertNull(BpfSchema::totals($partial['data'])['f1_hours']);
        $session->setTypeFinancement(\App\Enum\TypeFinancement::OUI);
        $subcontract = $generator->generate($this->entite, $start, $start, $rules);
        self::assertSame(2, $subcontract['data']['g_count']);
        self::assertSame(3, $subcontract['data']['g_hours']);
        self::assertSame(0, BpfSchema::totals($subcontract['data'])['f1_count']);
    }
}
