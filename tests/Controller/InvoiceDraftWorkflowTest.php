<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\{Entite, EntitePreferences, Entreprise, Facture, FactureBrouillon, Utilisateur, UtilisateurEntite};
use App\Entity\Billing\{EntiteSubscription, Plan};
use App\Service\EInvoicing\EInvoiceReadiness;
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\{KernelBrowser, Test\KernelTestCase};
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Mailer\MailerInterface;

/** Exercise the real HTTP workflow against a disposable database, with no external delivery. */
final class InvoiceDraftWorkflowTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private KernelBrowser $client;
    private Entite $entite;
    private Entite $other;
    private Utilisateur $admin;
    private Entreprise $customer;
    private Entreprise $foreignCustomer;
    private EInvoiceReadiness $readiness;
    private array $database;

    protected function setUp(): void
    {
        $this->database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->readiness = $this->createMock(EInvoiceReadiness::class);
        self::getContainer()->set(EInvoiceReadiness::class, $this->readiness);
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');
        self::getContainer()->set(MailerInterface::class, $mailer);
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->admin = (new Utilisateur())->setEmail('invoice-draft-admin@example.test')->setPassword('unused')
            ->setPrenom('Admin')->setNom('Brouillon')->setRoles(['ROLE_SUPER_ADMIN']);
        $this->entite = (new Entite())->setNom('Organisme brouillon')->setCreateur($this->admin)->setPublic(false)
            ->setAdresse('10 rue de la Formation')->setCodePostal('75001')->setVille('Paris')->setPays('France')
            ->setSiret('12345678900012')->setNumeroTva('FR12345678901');
        $this->other = (new Entite())->setNom('Organisme étranger')->setCreateur($this->admin)->setPublic(false);
        $this->customer = (new Entreprise())->setEntite($this->entite)->setCreateur($this->admin)->setRaisonSociale('Client brouillon')
            ->setAdresse('20 rue du Client')->setCodePostal('69001')->setVille('Lyon')->setPays('France')->setSiret('98765432100012');
        $this->foreignCustomer = (new Entreprise())->setEntite($this->other)->setCreateur($this->admin)->setRaisonSociale('Client confidentiel étranger');
        $preferences = (new EntitePreferences())->setEntite($this->entite)->setCreateur($this->admin)
            ->setInvoicePreferences(['confirmed' => true, 'dueDays' => 30, 'paymentMeans' => 'Virement bancaire',
                'earlyPaymentDiscount' => 'Aucun escompte', 'latePaymentPenalty' => 'Trois fois le taux légal',
                'recoveryCompensationCents' => 4000, 'legalIdentity' => 'Entreprise individuelle', 'vatCategory' => 'S', 'vatOnDebits' => false]);
        $this->entite->setPreferences($preferences);
        foreach ([$this->admin, $this->entite, $this->other, $this->customer, $this->foreignCustomer, $preferences] as $record) $this->em->persist($record);
        $this->em->flush();
        $this->admin->setEntite($this->entite);
        $this->em->persist((new UtilisateurEntite())->setCreateur($this->admin)->setUtilisateur($this->admin)->setEntite($this->entite)
            ->setRoles(['TENANT_ADMIN'])->setStatus('active'));
        $plan = (new Plan())->setCode('invoice-draft-test')->setName('Test')->setMaxUtilisateurs(5)->setMaxApprenantsAn(100);
        $this->em->persist($plan);
        $this->em->persist((new EntiteSubscription())->setEntite($this->entite)->setStatus('active')->setPlan($plan));
        $this->em->flush();
        $this->client = self::getContainer()->get('test.client');
        $this->client->disableReboot();
        $this->client->loginUser($this->admin);
        $this->client->catchExceptions(false);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $index => $scope) {
            if ($this->database[$index] === null) unset($GLOBALS['_'.$scope]['DATABASE_URL']);
            else $GLOBALS['_'.$scope]['DATABASE_URL'] = $this->database[$index];
        }
    }

    public function testOpeningTheFormAndSavingAnIncompleteDraftNeverIssueOrReserveANumber(): void
    {
        $this->readiness->expects(self::never())->method('assertCanIssue');
        $page = $this->client->request('GET', $this->url('new'));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->em->getRepository(FactureBrouillon::class)->count([]));
        $this->postDraft($this->url('new'), $page, ['note' => 'À terminer après confirmation du client']);
        $this->assertRedirect();
        $draft = $this->lastDraft();
        self::assertSame('À terminer après confirmation du client', $draft->getPayload()['note']);
        self::assertStringStartsWith('BR-', $draft->getReference());
        self::assertNull($draft->getFactureEmise());
        $this->assertNoInvoice();
        $page = $this->client->followRedirect();
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('À terminer après confirmation du client', trim($page->filter('[name="facture[note]"]')->text()));
    }

    public function testMalformedValuesAreRetainedForCorrectionAndCannotBeIssued(): void
    {
        $payload = $this->payload();
        $payload['dateEmission'] = '31/02/2026';
        $payload['lignes'][0]['puHtCents'] = 'cent euros';
        $draft = $this->saveNew($payload);
        self::assertSame('31/02/2026', $draft->getPayload()['dateEmission']);
        self::assertSame('cent euros', $draft->getPayload()['lignes'][0]['puHtCents']);
        $page = $this->client->request('GET', $this->url('draft_edit', $draft));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('31/02/2026', $page->filter('[name="facture[dateEmission]"]')->attr('value'));
        self::assertSame('cent euros', $page->filter('[name="facture[lignes][0][puHtCents]"]')->attr('value'));
        $this->client->request('GET', $this->url('draft_review', $draft));
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertNoInvoice();
    }

    public function testReviewRejectsAClientFromAnotherOrganism(): void
    {
        $payload = $this->payload();
        $payload['entrepriseDestinataire'] = (string) $this->foreignCustomer->getId();
        $draft = $this->saveNew($payload);
        $this->client->request('GET', $this->url('draft_review', $draft));
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('Client confidentiel étranger', $this->client->getResponse()->getContent());
        $this->assertNoInvoice();
    }

    public static function excessiveLineValues(): iterable
    {
        yield 'quantity above supported limit' => ['qte', '1000001'];
        yield 'price above supported total' => ['puHtCents', '10000001,00'];
        yield 'quantity at integer boundary' => ['qte', (string) PHP_INT_MAX];
        yield 'quantity beyond integer capacity' => ['qte', str_repeat('9', 40)];
        yield 'price beyond integer capacity' => ['puHtCents', str_repeat('9', 40).',00'];
    }

    #[DataProvider('excessiveLineValues')]
    public function testExcessiveQuantitiesAndAmountsRemainCorrectableButCannotBeIssued(string $field, string $value): void
    {
        $payload = $this->payload();
        $payload['lignes'][0][$field] = $value;
        $draft = $this->saveNew($payload);
        self::assertSame($value, $draft->getPayload()['lignes'][0][$field]);
        $page = $this->client->request('GET', $this->url('draft_review', $draft));
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertCount(0, $page->filter('#invoice-draft-issue'));
        $this->assertNoInvoice();
        $stack = self::getContainer()->get('request_stack');
        $stack->push($this->client->getRequest());
        try { $token = self::getContainer()->get('security.csrf.token_manager')->getToken('invoice_draft_issue_'.$draft->getId())->getValue(); }
        finally { $this->client->getRequest()->getSession()->save(); $stack->pop(); }
        $this->client->request('POST', $this->url('draft_issue', $draft), ['_token' => $token, 'version' => $draft->getVersion(),
            'confirm_issue' => '1', 'review_token' => 'not-a-valid-review']);
        self::assertSame(409, $this->client->getResponse()->getStatusCode(), 'A direct POST cannot bypass the blocked review.');
        $this->assertNoInvoice();
    }

    public function testInvalidJsonCharactersReturnAValidationErrorWithoutSavingOrIssuing(): void
    {
        $page = $this->client->request('GET', $this->url('new'));
        $payload = $this->payload();
        $payload['note'] = "Invalid UTF-8: \xC3\x28";
        $this->postDraft($this->url('new'), $page, $payload, 'review');
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('caractères invalides', $this->client->getResponse()->getContent());
        self::assertSame(0, $this->em->getRepository(FactureBrouillon::class)->count([]));
        $this->assertNoInvoice();
    }

    public function testInvalidCsrfCannotCreateOrOverwriteADraft(): void
    {
        $this->client->catchExceptions(true);
        $this->client->request('POST', $this->url('new'), ['_draft_token' => 'invalid', '_draft_version' => '1', 'draft_action' => 'save', 'facture' => ['note' => 'Forbidden']]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->em->getRepository(FactureBrouillon::class)->count([]));
        $draft = $this->saveNew(['note' => 'Original']);
        $this->client->request('POST', $this->url('draft_edit', $draft), ['_draft_token' => 'invalid', '_draft_version' => $draft->getVersion(), 'draft_action' => 'save', 'facture' => ['note' => 'Forbidden']]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $draft = $this->em->find(FactureBrouillon::class, $draft->getId());
        self::assertSame('Original', $draft->getPayload()['note']);
        $this->assertNoInvoice();
    }

    public function testReviewRequiresExplicitConsentAndRepeatedConfirmationIssuesOnlyOnce(): void
    {
        $draft = $this->saveNew($this->payload());
        $page = $this->client->request('GET', $this->url('draft_review', $draft));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $page->filter('#invoice-draft-issue'));
        $this->assertNoInvoice();
        $confirmation = $page->filter('#invoice-draft-issue')->form()->getPhpValues();
        $this->client->catchExceptions(true);
        $this->client->request('POST', $this->url('draft_issue', $draft), array_replace($confirmation, ['_token' => 'invalid', 'confirm_issue' => '1']));
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertNoInvoice();
        $this->client->catchExceptions(false);
        $this->client->request('POST', $this->url('draft_issue', $draft), $confirmation);
        self::assertContains($this->client->getResponse()->getStatusCode(), [400, 409, 422]);
        $this->assertNoInvoice();

        $page = $this->client->request('GET', $this->url('draft_review', $draft));
        $confirmation = $page->filter('#invoice-draft-issue')->form()->getPhpValues();
        $confirmation['confirm_issue'] = '1';
        $this->client->request('POST', $this->url('draft_issue', $draft), $confirmation);
        $this->assertRedirect();
        self::assertSame(1, $this->em->getRepository(Facture::class)->count([]));
        $draft = $this->em->find(FactureBrouillon::class, $draft->getId());
        $invoice = $draft->getFactureEmise();
        self::assertNotNull($invoice);
        self::assertSame(12000, $invoice->getMontantTtcCents());
        self::assertStringEndsWith('-0001', $invoice->getNumero());
        $this->client->request('POST', $this->url('draft_issue', $draft), $confirmation);
        $this->assertRedirect();
        self::assertSame(1, $this->em->getRepository(Facture::class)->count([]));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT last FROM facture_sequence'));
    }

    public function testReviewOfAChangedCustomerCannotBeConfirmedWithoutAnotherRead(): void
    {
        $draft = $this->saveNew($this->payload());
        $page = $this->client->request('GET', $this->url('draft_review', $draft));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $confirmation = $page->filter('#invoice-draft-issue')->form()->getPhpValues();
        $confirmation['confirm_issue'] = '1';
        $this->customer = $this->em->find(Entreprise::class, $this->customer->getId());
        $this->customer->setAdresse('Adresse modifiée après la relecture');
        $this->em->flush();
        $this->client->request('POST', $this->url('draft_issue', $draft), $confirmation);
        self::assertContains($this->client->getResponse()->getStatusCode(), [409, 422]);
        $this->assertNoInvoice();
    }

    public function testDraftPdfIsMarkedOnEveryPageAndNeverBecomesAnIssuedDocument(): void
    {
        if (getenv('INVOICE_DRAFT_PREVIEW_DIR')) {
            $pdf = new class(self::getContainer()->get('twig'), sys_get_temp_dir()) extends \App\Service\Pdf\PdfManager {
                public function createPortrait(string $html, string $nameFile): \Symfony\Component\HttpFoundation\Response
                {
                    file_put_contents(getenv('INVOICE_DRAFT_PREVIEW_DIR').'/invoice-draft-preview.html', $html);
                    return parent::createPortrait($html, $nameFile);
                }
            };
            self::getContainer()->set(\App\Service\Pdf\PdfManager::class, $pdf);
        }
        $payload = $this->payload();
        $payload['lignes'] = [];
        for ($line = 1; $line <= 45; ++$line) {
            $payload['lignes'][] = ['label' => 'Prestation de formation '.$line, 'qte' => '1', 'puHtCents' => '100,00', 'tvaBp' => '2000'];
        }
        $draft = $this->saveNew($payload);
        $this->client->request('GET', $this->url('draft_pdf', $draft));
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        self::assertStringContainsString('BROUILLON_', $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('noindex', $response->headers->get('X-Robots-Tag'));
        self::assertStringContainsString('nofollow', $response->headers->get('X-Robots-Tag'));
        if ($previewDir = getenv('INVOICE_DRAFT_PREVIEW_DIR')) file_put_contents($previewDir.'/invoice-draft-preview.pdf', $response->getContent());
        $pdf = (new \Smalot\PdfParser\Parser())->parseContent($response->getContent());
        self::assertGreaterThan(1, count($pdf->getPages()), $pdf->getText());
        foreach ($pdf->getPages() as $page) {
            self::assertStringContainsString('BROUILLON', $page->getText());
            self::assertStringContainsString('NON ÉMIS', $page->getText());
        }
        $this->assertNoInvoice();
        $draft = $this->em->find(FactureBrouillon::class, $draft->getId());
        self::assertNull($draft->getFactureEmise());
    }

    public function testAnOlderEditingTabCannotOverwriteTheLatestDraft(): void
    {
        $draft = $this->saveNew(['note' => 'Initial']);
        $oldPage = $this->client->request('GET', $this->url('draft_edit', $draft));
        $post = ['_draft_token' => $oldPage->filter('[name="_draft_token"]')->attr('value'),
            '_draft_version' => $oldPage->filter('[name="_draft_version"]')->attr('value'), 'draft_action' => 'save'];
        $this->client->request('POST', $this->url('draft_edit', $draft), $post + ['facture' => ['note' => 'Dernière version']]);
        $this->assertRedirect();
        $conflict = $this->client->request('POST', $this->url('draft_edit', $draft), $post + ['facture' => ['note' => 'Ancien onglet']]);
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        self::assertSame($post['_draft_version'], $conflict->filter('[name="_draft_version"]')->attr('value'), 'A conflict must not silently authorize the stale values against the current version.');
        $this->postDraft($this->url('draft_edit', $draft), $conflict, ['note' => 'Ancien onglet réessayé']);
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        $draft = $this->em->find(FactureBrouillon::class, $draft->getId());
        self::assertSame('Dernière version', $draft->getPayload()['note']);
        $this->assertNoInvoice();
    }

    public function testLearnerCannotOpenTheDraftWorkspace(): void
    {
        $learner = (new Utilisateur())->setEmail('invoice-draft-learner@example.test')->setPassword('unused')
            ->setPrenom('Stagiaire')->setNom('Test')->setEntite($this->entite);
        $this->em->persist($learner);
        $this->em->persist((new UtilisateurEntite())->setCreateur($this->admin)->setUtilisateur($learner)->setEntite($this->entite)
            ->setRoles(['TENANT_STAGIAIRE'])->setStatus('active'));
        $this->em->flush();
        $this->client->loginUser($learner);
        $this->client->catchExceptions(true);
        foreach (['new', 'draft_index'] as $route) {
            $this->client->request('GET', $this->url($route));
            self::assertSame(403, $this->client->getResponse()->getStatusCode());
        }
        self::assertSame(0, $this->em->getRepository(FactureBrouillon::class)->count([]));
    }

    public function testForeignDraftCannotBeViewedEditedOrIssuedEvenByAnAdministratorOfTheRequestedOrganism(): void
    {
        $foreign = new FactureBrouillon($this->other, $this->admin);
        $foreign->savePayload(['note' => 'Confidential foreign draft'], [], $this->admin);
        $this->em->persist($foreign);
        $this->em->flush();
        $this->client->catchExceptions(true);
        foreach ([['GET', 'draft_edit'], ['GET', 'draft_review'], ['GET', 'draft_pdf'], ['POST', 'draft_edit'], ['POST', 'draft_issue'], ['POST', 'draft_archive'], ['POST', 'draft_delete']] as [$method, $route]) {
            $this->client->request($method, $this->url($route, $foreign));
            self::assertSame(404, $this->client->getResponse()->getStatusCode(), $route);
            self::assertStringNotContainsString('Confidential foreign draft', $this->client->getResponse()->getContent());
        }
        $foreign = $this->em->find(FactureBrouillon::class, $foreign->getId());
        self::assertFalse($foreign->isArchived());
        self::assertNull($foreign->getFactureEmise());
        $this->assertNoInvoice();
    }

    public static function conventionSources(): array { return [[false], [true]]; }

    #[\PHPUnit\Framework\Attributes\DataProvider('conventionSources')]
    public function testConventionCreatesOneDraftAndOnlyIssuesAfterExplicitReview(bool $linked): void
    {
        $convention = $this->convention();
        if ($linked) {
            $quote = (new \App\Entity\Devis())->setEntite($this->entite)->setCreateur($this->admin)->setNumero('DEV-ISSUE-CONVENTION')
                ->setStatus(\App\Enum\DevisStatus::ACCEPTED)->setEntrepriseDestinataire($this->customer)
                ->setMontantHtCents(22400)->setMontantTvaCents(4480)->setMontantTtcCents(26880);
            $this->em->persist($quote);
            $convention->setDevis($quote);
            $this->em->flush();
        }
        $this->openConventionDraft($convention);
        $draft = $this->lastDraft();
        self::assertSame($convention->getId(), $draft->getSourceConvention()->getId());
        self::assertSame((string) $this->customer->getId(), $draft->getPayload()['entrepriseDestinataire']);
        self::assertSame('1', (string) $draft->getPayload()['lignes'][0]['qte']);
        self::assertSame(12000, $draft->getSummary()['totalTtcCents']);
        self::assertCount(1, $draft->getPayload()['inscriptions']);
        $this->assertNoInvoice();
        $page = $this->client->followRedirect();
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('CONV-DRAFT-TEST', $page->text());
        $this->openConventionDraft($convention);
        self::assertSame(1, $this->em->getRepository(FactureBrouillon::class)->count([]));
        $page = $this->client->followRedirect();
        $payload = $draft->getPayload();
        $payload['invoiceTerms'] = $this->payload()['invoiceTerms'];
        $this->postDraft($this->url('draft_edit', $draft), $page, $payload, 'review');
        $review = $this->client->followRedirect();
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), $review->text());
        $this->assertNoInvoice();
        $form = $review->filter('#invoice-draft-issue')->form();
        $values = $form->getPhpValues();
        $values['confirm_issue'] = '1';
        $this->client->request('POST', $this->url('draft_issue', $draft), $values);
        $this->assertRedirect();
        self::assertSame(1, $this->em->getRepository(Facture::class)->count([]));
        $invoice = $this->em->getRepository(Facture::class)->findOneBy([]);
        self::assertSame(12000, $invoice->getMontantTtcCents());
        self::assertCount(1, $invoice->getInscriptions());
        $this->openConventionDraft($convention);
        self::assertStringContainsString('/facture/'.$invoice->getId(), $this->client->getResponse()->headers->get('Location'));
        self::assertSame(1, $this->em->getRepository(Facture::class)->count([]));
        $this->client->request('POST', '/fr/administrateur/'.$this->entite->getId().'/conventions/ajax', ['search' => ['value' => $invoice->getNumero()]]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $listing = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(1, $listing['data']);
        self::assertStringContainsString('Facture '.$invoice->getNumero(), $listing['data'][0]['numero']);
        self::assertStringContainsString('/facture/'.$invoice->getId(), $listing['data'][0]['numero']);
        if ($linked) self::assertStringContainsString('Devis DEV-ISSUE-CONVENTION', $listing['data'][0]['numero']);
        else self::assertStringNotContainsString('Devis ', $listing['data'][0]['numero']);
    }

    public function testConventionWithoutPriceRequiresCompletionAndArchivedDraftIsReused(): void
    {
        $convention = $this->convention();
        $convention->setMontantHtCents(null);
        $this->em->flush();
        $this->openConventionDraft($convention);
        $draft = $this->lastDraft();
        self::assertSame('', $draft->getPayload()['lignes'][0]['puHtCents']);
        $this->client->request('GET', $this->url('draft_review', $draft));
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $draft = $this->em->find(FactureBrouillon::class, $draft->getId());
        self::getContainer()->get(\App\Service\Billing\InvoiceDraftManager::class)->archive($draft, $draft->getVersion(), $this->em->find(Utilisateur::class, $this->admin->getId()));
        $this->openConventionDraft($convention);
        self::assertStringContainsString('archived=1', $this->client->getResponse()->headers->get('Location'));
        self::assertSame(1, $this->em->getRepository(FactureBrouillon::class)->count([]));
        $this->assertNoInvoice();
    }

    public function testConventionConversionRejectsGetInvalidCsrfAndForeignTenant(): void
    {
        $convention = $this->convention();
        $this->client->catchExceptions(true);
        $url = $this->conventionUrl($convention, 'invoice_draft');
        $this->client->request('GET', $url);
        self::assertSame(405, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', $url, ['_token' => 'invalid']);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $convention = $this->em->find(\App\Entity\ConventionContrat::class, $convention->getId());
        $convention->setEntite($this->em->find(Entite::class, $this->other->getId()));
        $this->em->flush();
        $this->client->request('POST', $url);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->em->getRepository(FactureBrouillon::class)->count([]));
        $this->assertNoInvoice();
    }

    public function testIndividualConventionCopiesTheLearnerAndLearnerCannotConvertIt(): void
    {
        $convention = $this->convention();
        $learner = (new Utilisateur())->setEmail('convention-learner@example.test')->setPassword('unused')
            ->setPrenom('Stagiaire')->setNom('Convention')->setEntite($this->entite);
        $this->em->persist($learner);
        $this->em->persist((new UtilisateurEntite())->setCreateur($this->admin)->setUtilisateur($learner)->setEntite($this->entite)
            ->setRoles(['TENANT_STAGIAIRE'])->setStatus('active'));
        $convention->setEntreprise(null)->setStagiaire($learner);
        $convention->getInscriptions()->first()->setEntreprise(null)->setStagiaire($learner);
        $this->em->flush();
        $this->openConventionDraft($convention);
        $draft = $this->lastDraft();
        self::assertSame((string) $learner->getId(), $draft->getPayload()['destinataire']);
        self::assertEmpty($draft->getPayload()['entrepriseDestinataire']);
        $this->assertNoInvoice();
        $this->client->loginUser($learner);
        $this->client->catchExceptions(true);
        $this->client->request('POST', $this->conventionUrl($convention, 'invoice_draft'));
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, $this->em->getRepository(FactureBrouillon::class)->count([]));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('conventionSources')]
    public function testConventionWithQuoteUsesConventionPriceAndReusesOneDraft(bool $existing): void
    {
        $convention = $this->convention();
        $convention->setMontantHtCents(8800);
        $quote = (new \App\Entity\Devis())->setEntite($this->entite)->setCreateur($this->admin)->setNumero('DEV-CONVENTION')
            ->setStatus(\App\Enum\DevisStatus::ACCEPTED)->setEntrepriseDestinataire($this->customer)
            ->setMontantHtCents(22400)->setMontantTvaCents(4480)->setMontantTtcCents(26880);
        $this->em->persist($quote);
        $convention->setDevis($quote);
        $this->em->flush();
        if ($existing) {
            $oldDraft = (new FactureBrouillon($this->entite, $this->admin))->setSourceDevis($quote);
            $oldPayload = $this->payload();
            $oldPayload['lignes'][0]['puHtCents'] = '224.00';
            self::getContainer()->get(\App\Service\Billing\InvoiceDraftManager::class)->save($oldDraft, $oldPayload, 1, $this->admin);
        }
        $this->openConventionDraft($convention);
        $this->assertRedirect();
        $draft = $this->lastDraft();
        if ($existing) self::assertSame($oldDraft->getId(), $draft->getId());
        self::assertSame($quote->getId(), $draft->getSourceDevis()->getId());
        self::assertSame($convention->getId(), $draft->getSourceConvention()->getId());
        self::assertSame(10560, $draft->getSummary()['totalTtcCents']);
        $this->openConventionDraft($convention);
        $this->client->followRedirect();
        self::assertSame(1, $this->em->getRepository(FactureBrouillon::class)->count([]));
        $this->assertNoInvoice();
    }

    public function testConventionWithCanceledQuoteOpensDraftWithoutQuoteRouteOrIssuing(): void
    {
        $convention = $this->convention();
        $quote = (new \App\Entity\Devis())->setEntite($this->entite)->setCreateur($this->admin)
            ->setNumero('DEV-CONVENTION-CANCELED')->setStatus(\App\Enum\DevisStatus::CANCELED)
            ->setEntrepriseDestinataire($this->customer);
        $this->em->persist($quote);
        $convention->setDevis($quote);
        $this->em->flush();

        $this->client->request('GET', self::getContainer()->get('router')->generate('app_administrateur_devis_invoice_draft', [
            'entite' => $this->entite->getId(), 'id' => $quote->getId(),
        ]));
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->em->getRepository(FactureBrouillon::class)->count([]));

        $this->openConventionDraft($convention);
        $draft = $this->lastDraft();
        self::assertStringContainsString('/facture/', $this->client->getResponse()->headers->get('Location'));
        $this->client->followRedirect();
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame($convention->getId(), $draft->getSourceConvention()->getId());
        self::assertSame(\App\Enum\DevisStatus::CANCELED, $quote->getStatus());
        $this->assertNoInvoice();
        $this->openConventionDraft($convention);
        self::assertSame(1, $this->em->getRepository(FactureBrouillon::class)->count([]));
    }

    private function convention(): \App\Entity\ConventionContrat
    {
        $site = (new \App\Entity\Site())->setEntite($this->entite)->setCreateur($this->admin)->setNom('Site')->setSlug('site');
        $session = (new \App\Entity\Session())->setEntite($this->entite)->setCreateur($this->admin)->setSite($site)->setCode('SES-CONVENTION');
        $registration = (new \App\Entity\Inscription())->setEntite($this->entite)->setCreateur($this->admin)
            ->setSession($session)->setStagiaire($this->admin)->setEntreprise($this->customer);
        $convention = (new \App\Entity\ConventionContrat())->setEntite($this->entite)->setCreateur($this->admin)->setSession($session)
            ->setEntreprise($this->customer)->setNumero('CONV-DRAFT-TEST')->setIntituleFormation('Formation convention')
            ->setMontantHtCents(10000)->setTauxTva(20)->addInscription($registration);
        foreach ([$site, $session, $registration, $convention] as $record) $this->em->persist($record);
        $this->em->flush();
        return $convention;
    }

    private function conventionUrl(\App\Entity\ConventionContrat $convention, string $route): string
    {
        return self::getContainer()->get('router')->generate('app_administrateur_convention_'.$route, ['entite' => $this->entite->getId(), 'id' => $convention->getId()]);
    }

    private function openConventionDraft(\App\Entity\ConventionContrat $convention): void
    {
        $page = $this->client->request('GET', $this->conventionUrl($convention, 'show'));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $action = $this->conventionUrl($convention, 'invoice_draft');
        $form = $page->filter('form[action="'.$action.'"]');
        self::assertCount(1, $form);
        $this->client->request('POST', $action, ['_token' => $form->filter('[name="_token"]')->attr('value')]);
        $this->assertRedirect();
    }

    public function testDeleteButtonRemovesOnlyTheDraftAndAllowsConventionConversionAgain(): void
    {
        $convention = $this->convention();
        $this->openConventionDraft($convention);
        $draft = $this->lastDraft();
        $page = $this->client->request('GET', $this->url('draft_index'));
        $form = $page->filter('form[action="'.$this->url('draft_delete', $draft).'"]')->form();
        $this->client->submit($form);
        $this->assertRedirect();
        self::assertSame(0, $this->em->getRepository(FactureBrouillon::class)->count([]));
        self::assertNotNull($this->em->find(\App\Entity\ConventionContrat::class, $convention->getId()));
        $this->assertNoInvoice();
        $this->openConventionDraft($convention);
        self::assertSame(1, $this->em->getRepository(FactureBrouillon::class)->count([]));
    }

    private function saveNew(array $payload): FactureBrouillon
    {
        $page = $this->client->request('GET', $this->url('new'));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->postDraft($this->url('new'), $page, $payload);
        $this->assertRedirect();
        return $this->lastDraft();
    }

    private function postDraft(string $url, Crawler $page, array $payload, string $action = 'save'): void
    {
        $this->client->request('POST', $url, ['_draft_token' => $page->filter('[name="_draft_token"]')->attr('value'),
            '_draft_version' => $page->filter('[name="_draft_version"]')->attr('value'), 'draft_action' => $action, 'facture' => $payload]);
    }

    private function lastDraft(): FactureBrouillon
    {
        $draft = $this->em->getRepository(FactureBrouillon::class)->findOneBy(['entite' => $this->entite], ['id' => 'DESC']);
        self::assertNotNull($draft);
        return $draft;
    }

    private function payload(): array
    {
        return ['entrepriseDestinataire' => (string) $this->customer->getId(), 'dateEmission' => '01/11/2026', 'devise' => 'EUR', 'status' => 'due',
            'montantHtCents' => '100,00', 'montantTvaCents' => '20,00', 'montantTtcCents' => '120,00',
            'invoiceTerms' => ['dueDate' => '2026-12-01', 'serviceDate' => '2026-10-31', 'paymentMeans' => 'Virement bancaire',
                'earlyPaymentDiscount' => 'Aucun escompte', 'latePaymentPenalty' => 'Trois fois le taux légal',
                'recoveryCompensationCents' => '40,00', 'legalIdentity' => 'Entreprise individuelle', 'vatCategory' => 'S', 'vatOnDebits' => '0'],
            'lignes' => [['label' => 'Formation Excel', 'qte' => '1', 'puHtCents' => '100,00', 'tvaBp' => '2000']]];
    }

    private function url(string $route, ?FactureBrouillon $draft = null): string
    {
        $parameters = ['entite' => $this->entite->getId()];
        if ($draft) $parameters['draftId'] = $draft->getId();
        return self::getContainer()->get('router')->generate('app_administrateur_facture_'.$route, $parameters);
    }

    private function assertRedirect(): void
    {
        self::assertContains($this->client->getResponse()->getStatusCode(), [302, 303], strip_tags($this->client->getResponse()->getContent()));
    }

    private function assertNoInvoice(): void
    {
        self::assertSame(0, $this->em->getRepository(Facture::class)->count([]));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM facture_sequence'));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM ligne_facture'));
    }
}
