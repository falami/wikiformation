<?php
declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\{Devis, Entite, EntitePreferences, Entreprise, Facture, FactureBrouillon, Inscription, LigneFacture, Paiement, Session, Site, Utilisateur};
use App\Enum\DevisStatus;
use App\Service\Billing\{InscriptionBillingSync, InvoiceDraftManager, InvoiceIssuer, InvoiceSnapshot};
use App\Service\EInvoicing\EInvoiceReadiness;
use App\Service\Sequence\{FactureNumberGenerator, SequenceNumberManager};
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InvoiceDraftTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Entite $entity;
    private Entreprise $customer;
    private Utilisateur $user;
    private InvoiceDraftManager $drafts;
    private EInvoiceReadiness $readiness;
    private array $database;

    protected function setUp(): void
    {
        $this->database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->readiness = $this->createMock(EInvoiceReadiness::class);
        self::getContainer()->set(EInvoiceReadiness::class, $this->readiness);
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->user = (new Utilisateur())->setEmail('draft-admin@example.test')->setPassword('unused')->setNom('Admin')->setPrenom('Test');
        $this->entity = (new Entite())->setNom('Organisme test')->setCreateur($this->user)->setPublic(false)
            ->setAdresse('10 rue de la Formation')->setCodePostal('75001')->setVille('Paris')->setPays('France')->setSiret('12345678900012')->setNumeroTva('FR12345678901');
        $this->customer = (new Entreprise())->setEntite($this->entity)->setCreateur($this->user)->setRaisonSociale('Client test')
            ->setAdresse('20 rue du Client')->setCodePostal('69001')->setVille('Lyon')->setPays('France')->setSiret('98765432100012')->setNumeroTVA('FR98765432109');
        $prefs = (new EntitePreferences())->setEntite($this->entity)->setCreateur($this->user)->setInvoicePreferences([
            'confirmed' => true, 'dueDays' => 30, 'paymentMeans' => 'Virement', 'earlyPaymentDiscount' => 'Pas d’escompte',
            'latePaymentPenalty' => 'Taux contractuel vérifié', 'recoveryCompensationCents' => 4000, 'legalIdentity' => 'Identité juridique test', 'vatCategory' => 'S', 'vatOnDebits' => false,
        ]);
        $this->entity->setPreferences($prefs);
        foreach ([$this->user, $this->entity, $this->customer, $prefs] as $row) $this->em->persist($row);
        $this->em->flush();
        $issuer = new InvoiceIssuer($this->em, new FactureNumberGenerator(new SequenceNumberManager($this->em->getConnection())), $this->readiness);
        $this->drafts = new InvoiceDraftManager($this->em, $issuer, new InscriptionBillingSync($this->em, $this->em->getRepository(Paiement::class)));
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $index => $key) {
            if ($this->database[$index] === null) unset($GLOBALS['_' . $key]['DATABASE_URL']);
            else $GLOBALS['_' . $key]['DATABASE_URL'] = $this->database[$index];
        }
    }

    public function testIncompleteDraftSurvivesReloadWithoutInvoiceNumberOrBillingConfiguration(): void
    {
        $this->readiness->expects(self::never())->method('assertCanIssue');
        $draft = new FactureBrouillon($this->entity, $this->user);
        $this->drafts->save($draft, ['dateEmission' => '', 'lignes' => [['label' => 'À préciser', 'qte' => '']], '_token' => 'secret', 'invoiceTerms' => ['_token' => 'nested-secret']], 1, $this->user);
        $this->em->refresh($draft);
        self::assertSame('', $draft->getPayload()['dateEmission']);
        self::assertArrayNotHasKey('_token', $draft->getPayload());
        self::assertArrayNotHasKey('_token', $draft->getPayload()['invoiceTerms']);
        self::assertStringStartsWith('BR-', $draft->getReference());
        self::assertNull($draft->getFactureEmise());
        self::assertSame(0, $this->em->getRepository(Facture::class)->count([]));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM facture_sequence'));
    }

    public function testStaleSaveDoesNotOverwriteNewerDraft(): void
    {
        $draft = $this->draft();
        $version = $draft->getVersion();
        $this->drafts->save($draft, ['note' => 'Nouvelle version'], $version, $this->user);
        try {
            $this->drafts->save($draft, ['note' => 'Ancien onglet'], $version, $this->user);
            self::fail('A stale editor must not overwrite a newer version.');
        } catch (\DomainException $error) { self::assertStringContainsString('autre onglet', $error->getMessage()); }
        self::assertTrue($this->em->isOpen());
        $this->em->refresh($draft);
        self::assertSame(['note' => 'Nouvelle version'], $draft->getPayload());
        self::assertSame($version + 1, $draft->getVersion());
    }

    public function testConcurrentDatabaseChangeIsRefreshedBeforeSavingOrIssuing(): void
    {
        $draft = $this->draft();
        $version = $draft->getVersion();
        $this->em->getConnection()->executeStatement('UPDATE facture_brouillon SET version = version + 1, payload = ? WHERE id = ?', [json_encode(['note' => 'Autre utilisateur']), $draft->getId()]);
        try {
            $this->drafts->issue($draft, $version, $this->user, function (): Facture { self::fail('A stale confirmation must not build an invoice.'); });
            self::fail('A stale confirmation must be rejected.');
        } catch (\DomainException $error) { self::assertStringContainsString('autre onglet', $error->getMessage()); }
        self::assertSame(['note' => 'Autre utilisateur'], $draft->getPayload());
        self::assertSame(0, $this->em->getRepository(Facture::class)->count([]));
    }

    public function testConfirmationIsAtomicAndReplayDoesNotIssueASecondInvoice(): void
    {
        $draft = $this->draft();
        $version = $draft->getVersion();
        $invoice = $this->drafts->issue($draft, $version, $this->user, fn(FactureBrouillon $fresh): Facture => $this->invoice()->setNote($fresh->getPayload()['note']));
        self::assertStringEndsWith('-0001', $invoice->getNumero());
        self::assertNotNull(InvoiceSnapshot::read($invoice));
        self::assertSame($invoice, $draft->getFactureEmise());
        $this->em->refresh($draft);
        self::assertSame($invoice, $this->drafts->issue($draft, $version, $this->user, function (): Facture { self::fail('A repeated confirmation must not rebuild or number an invoice.'); }));
        self::assertSame(1, $this->em->getRepository(Facture::class)->count([]));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT last FROM facture_sequence'));
        self::assertSame(['created', 'saved', 'issued'], array_column($draft->getHistory(), 'event'));
        self::assertSame(0, $this->em->getRepository(FactureBrouillon::class)->countActiveForEntite($this->entity));
    }

    public function testFailedReviewOrReadinessKeepsTheDraftAndConsumesNoNumber(): void
    {
        $draft = $this->draft();
        $version = $draft->getVersion();
        try {
            $this->drafts->issue($draft, $version, $this->user, function (): Facture { throw new \DomainException('Les coordonnées ont changé depuis la relecture.'); });
            self::fail('A changed review must fail.');
        } catch (\DomainException $error) { self::assertStringContainsString('relecture', $error->getMessage()); }
        $this->readiness->method('assertCanIssue')->willThrowException(new \DomainException('Configuration manquante.'));
        try {
            $this->drafts->issue($draft, $version, $this->user, fn(): Facture => $this->invoice());
            self::fail('Missing readiness must fail.');
        } catch (\DomainException $error) { self::assertSame('Configuration manquante.', $error->getMessage()); }
        self::assertTrue($this->em->isOpen());
        $this->em->refresh($draft);
        self::assertNull($draft->getFactureEmise());
        self::assertSame($version, $draft->getVersion());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM facture_sequence'));
    }

    public function testDatabaseFailureRollsBackDraftLinkInvoiceAndSequence(): void
    {
        $draft = $this->draft();
        $listener = new class { public function onFlush(): void { throw new \RuntimeException('Persistence failure'); } };
        $this->em->getEventManager()->addEventListener(['onFlush'], $listener);
        try {
            $this->drafts->issue($draft, $draft->getVersion(), $this->user, fn(): Facture => $this->invoice());
            self::fail('Persistence must fail.');
        } catch (\RuntimeException $error) { self::assertSame('Persistence failure', $error->getMessage()); }
        finally { $this->em->getEventManager()->removeEventListener(['onFlush'], $listener); }
        $connection = $this->em->getConnection();
        self::assertNull($connection->fetchOne('SELECT facture_emise_id FROM facture_brouillon WHERE id = ?', [$draft->getId()]));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM facture'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM facture_sequence'));
        self::assertFalse($connection->isTransactionActive());
    }

    public function testEmissionUpdatesAnAlreadyInitializedRegistrationInvoiceCollectionExactlyOnce(): void
    {
        $inscription = $this->inscription();
        self::assertCount(0, $inscription->getFactures());
        $draft = $this->draft();
        $version = $draft->getVersion();
        $invoice = $this->drafts->issue($draft, $version, $this->user, fn(): Facture => $this->invoice()->addInscription($inscription));
        self::assertSame(12000, $inscription->getMontantDuCents());
        self::assertCount(1, $inscription->getFactures());
        self::assertSame(12000, (int) $this->em->getConnection()->fetchOne('SELECT montant_du_cents FROM inscription WHERE id = ?', [$inscription->getId()]));
        self::assertSame($invoice, $this->drafts->issue($draft, $version, $this->user, function (): Facture { self::fail('Do not repeat conversion on replay.'); }));
        self::assertSame(12000, $inscription->getMontantDuCents());
        self::assertCount(1, $inscription->getFactures());
    }

    public function testProjectionFailureRollsBackTheAlreadyFlushedInvoiceAndNumber(): void
    {
        $inscription = $this->inscription();
        $draft = $this->draft();
        $listener = new class {
            private int $flushes = 0;
            public function onFlush(): void { if (++$this->flushes === 2) throw new \RuntimeException('Projection failure'); }
        };
        $this->em->getEventManager()->addEventListener(['onFlush'], $listener);
        try {
            $this->drafts->issue($draft, $draft->getVersion(), $this->user, fn(): Facture => $this->invoice()->addInscription($inscription));
            self::fail('Projection must fail after the invoice flush.');
        } catch (\RuntimeException $error) { self::assertSame('Projection failure', $error->getMessage()); }
        finally { $this->em->getEventManager()->removeEventListener(['onFlush'], $listener); }
        $connection = $this->em->getConnection();
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM facture'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM facture_sequence'));
        self::assertNull($connection->fetchOne('SELECT facture_emise_id FROM facture_brouillon WHERE id = ?', [$draft->getId()]));
        self::assertNull($connection->fetchOne('SELECT montant_du_cents FROM inscription WHERE id = ?', [$inscription->getId()]));
        self::assertFalse($this->em->isOpen());
        self::assertFalse($connection->isTransactionActive());
    }

    public function testArchivedDraftCannotBeIssuedAndIsExcludedFromActiveWorklist(): void
    {
        $draft = $this->draft();
        $this->drafts->archive($draft, $draft->getVersion(), $this->user);
        self::assertSame([], $this->em->getRepository(FactureBrouillon::class)->findActiveForEntite($this->entity));
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('archivé');
        $this->drafts->issue($draft, $draft->getVersion(), $this->user, fn(): Facture => $this->invoice());
    }

    public function testEmittedDraftCannotBeModified(): void
    {
        $draft = $this->draft();
        $this->drafts->issue($draft, $draft->getVersion(), $this->user, fn(): Facture => $this->invoice());
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('déjà été émis');
        $this->drafts->save($draft, ['note' => 'Forbidden'], $draft->getVersion(), $this->user);
    }

    public function testArchivedQuoteDraftCanBeRestoredAndEmittedWithoutChangingItsIdentity(): void
    {
        $devis = $this->quote();
        $draft = (new FactureBrouillon($this->entity, $this->user))->setSourceDevis($devis);
        $this->drafts->save($draft, ['note' => 'Depuis un devis'], 1, $this->user);
        $reference = $draft->getReference();
        $this->drafts->archive($draft, $draft->getVersion(), $this->user);
        $archivedVersion = $draft->getVersion();
        try {
            $this->drafts->restore($draft, $archivedVersion - 1, $this->user);
            self::fail('A stale action must not restore a draft.');
        } catch (\DomainException $error) { self::assertStringContainsString('autre onglet', $error->getMessage()); }
        $this->drafts->restore($draft, $archivedVersion, $this->user);
        self::assertFalse($draft->isArchived());
        self::assertSame($reference, $draft->getReference());
        self::assertSame($devis, $draft->getSourceDevis());
        self::assertSame(1, $this->em->getRepository(FactureBrouillon::class)->countActiveForEntite($this->entity));
        self::assertSame(['created', 'saved', 'archived', 'restored'], array_column($draft->getHistory(), 'event'));
        $invoice = $this->drafts->issue($draft, $draft->getVersion(), $this->user, fn(): Facture => $this->invoice());
        self::assertSame($invoice, $draft->getFactureEmise());
        self::assertSame($invoice, $devis->getFactureCreee());
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('déjà été émis');
        $this->drafts->restore($draft, $draft->getVersion(), $this->user);
    }

    public function testConcurrentLegacyQuoteIssueIsReconciledWithoutAnotherInvoice(): void
    {
        $devis = $this->quote();
        $draft = (new FactureBrouillon($this->entity, $this->user))->setSourceDevis($devis);
        $this->drafts->save($draft, [], 1, $this->user);
        $invoice = $this->invoice();
        (new InvoiceIssuer($this->em, new FactureNumberGenerator(new SequenceNumberManager($this->em->getConnection())), $this->readiness))->persist($invoice);
        // Represents a quote linked by another workflow after this draft was read.
        $this->em->getConnection()->executeStatement('UPDATE devis SET facture_creee_id = ?, status = ? WHERE id = ?', [$invoice->getId(), DevisStatus::INVOICED->value, $devis->getId()]);
        $result = $this->drafts->issue($draft, $draft->getVersion(), $this->user, function (): Facture { self::fail('The quote was already invoiced; do not invoke the factory.'); });
        self::assertSame($invoice, $result);
        self::assertSame($invoice, $draft->getFactureEmise());
        self::assertSame(1, $this->em->getRepository(Facture::class)->count([]));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT last FROM facture_sequence'));
    }

    public function testQuoteConversionAndDraftEmissionShareOneTransaction(): void
    {
        $devis = $this->quote();
        $draft = (new FactureBrouillon($this->entity, $this->user))->setSourceDevis($devis);
        $this->drafts->save($draft, ['note' => 'Depuis un devis'], 1, $this->user);
        $invoice = $this->drafts->issue($draft, $draft->getVersion(), $this->user, fn(): Facture => $this->invoice());
        $this->em->refresh($devis);
        self::assertSame($invoice, $devis->getFactureCreee());
        self::assertSame(DevisStatus::INVOICED, $devis->getStatus());
        self::assertArrayNotHasKey('invoice_draft', $devis->getMeta());
        self::assertSame('preserved', $devis->getMeta()['other']);
    }

    public function testChangedQuoteStatusBlocksTheDraftWithoutNumbering(): void
    {
        $devis = $this->quote();
        $draft = (new FactureBrouillon($this->entity, $this->user))->setSourceDevis($devis);
        $this->drafts->save($draft, [], 1, $this->user);
        $devis->setStatus(DevisStatus::CANCELED);
        $this->em->flush();
        try {
            $this->drafts->issue($draft, $draft->getVersion(), $this->user, fn(): Facture => $this->invoice());
            self::fail('A canceled quote must not issue.');
        } catch (\DomainException $error) { self::assertStringContainsString('devis ne peut plus', $error->getMessage()); }
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM facture_sequence'));
    }

    public function testCrossTenantInvoiceCannotBeLinkedToDraft(): void
    {
        $other = (new Entite())->setNom('Other')->setPublic(false)->setCreateur($this->user);
        $this->em->persist($other); $this->em->flush();
        $draft = $this->draft();
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('autre organisme');
        $this->drafts->issue($draft, $draft->getVersion(), $this->user, fn(): Facture => $this->invoice()->setEntite($other));
    }

    public function testDeletionRejectsStaleVersionsAndIssuedInvoices(): void
    {
        $draft = $this->draft();
        try {
            $this->drafts->delete($draft, $draft->getVersion() - 1);
            self::fail('Stale deletion must fail.');
        } catch (\DomainException $error) { self::assertStringContainsString('autre onglet', $error->getMessage()); }
        $invoice = $this->drafts->issue($draft, $draft->getVersion(), $this->user, fn(): Facture => $this->invoice());
        try {
            $this->drafts->delete($draft, $draft->getVersion());
            self::fail('Issued invoices must remain.');
        } catch (\DomainException $error) { self::assertStringContainsString('déjà émise', $error->getMessage()); }
        self::assertSame(1, $this->em->getRepository(FactureBrouillon::class)->count([]));
        self::assertSame($invoice, $draft->getFactureEmise());
    }

    private function draft(): FactureBrouillon
    {
        $draft = new FactureBrouillon($this->entity, $this->user);
        $this->drafts->save($draft, ['note' => 'Version enregistrée'], 1, $this->user);
        return $draft;
    }

    private function invoice(): Facture
    {
        return (new Facture())->setEntite($this->entity)->setCreateur($this->user)->setEntrepriseDestinataire($this->customer)->setDateEmission(new \DateTimeImmutable('2026-11-01'))
            ->setMeta(['invoice_terms' => ['serviceDate' => '2026-10-31']])
            ->addLigne((new LigneFacture())->setEntite($this->entity)->setCreateur($this->user)->setLabel('Prestation test')->setQte(1)->setPuHtCents(10000)->setTva(20));
    }

    private function quote(): Devis
    {
        $devis = (new Devis())->setEntite($this->entity)->setCreateur($this->user)->setNumero('DEV-DRAFT-TEST')->setStatus(DevisStatus::ACCEPTED)
            ->setEntrepriseDestinataire($this->customer)->setMontantHtCents(10000)->setMontantTvaCents(2000)->setMontantTtcCents(12000)
            ->setMeta(['invoice_draft' => ['note' => 'legacy'], 'other' => 'preserved']);
        $this->em->persist($devis); $this->em->flush();
        return $devis;
    }

    private function inscription(): Inscription
    {
        $site = (new Site())->setEntite($this->entity)->setCreateur($this->user)->setNom('Site test')->setSlug('site-test');
        $session = (new Session())->setEntite($this->entity)->setCreateur($this->user)->setSite($site)->setCode('SESSION-DRAFT-TEST');
        $inscription = (new Inscription())->setEntite($this->entity)->setCreateur($this->user)->setSession($session)->setStagiaire($this->user);
        foreach ([$site, $session, $inscription] as $record) $this->em->persist($record);
        $this->em->flush();
        return $inscription;
    }
}
