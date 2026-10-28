<?php
declare(strict_types=1);
namespace App\Tests\Integration;

use App\Entity\{Entite, EntitePreferences, Entreprise, Facture, LigneFacture, Utilisateur};
use App\Service\Billing\{InvoiceIssuer, InvoicePreferences, InvoiceReadiness, InvoiceSnapshot};
use App\Service\Sequence\{FactureNumberGenerator, SequenceNumberManager};
use App\Service\EInvoicing\EInvoiceReadiness;
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InvoiceIssuanceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Entite $entity;
    private Entreprise $customer;
    private Utilisateur $user;
    private FactureNumberGenerator $numbers;
    private InvoiceIssuer $issuer;
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
        $this->user = (new Utilisateur())->setEmail('issuance@example.test')->setPassword('unused')->setNom('Admin')->setPrenom('Test');
        $this->entity = (new Entite())->setNom('Organisme original')->setCreateur($this->user)->setPublic(false)
            ->setAdresse('10 rue de la Formation')->setCodePostal('75001')->setVille('Paris')->setPays('France')->setSiret('12345678900012')->setNumeroTva('FR12345678901');
        $this->customer = (new Entreprise())->setEntite($this->entity)->setCreateur($this->user)->setRaisonSociale('Client original')
            ->setAdresse('20 rue du Client')->setCodePostal('69001')->setVille('Lyon')->setPays('France')->setSiret('98765432100012')->setNumeroTVA('FR98765432109');
        $prefs = (new EntitePreferences())->setEntite($this->entity)->setCreateur($this->user)->setInvoicePreferences($this->settings());
        $this->entity->setPreferences($prefs);
        foreach ([$this->user, $this->entity, $this->customer, $prefs] as $row) $this->em->persist($row);
        $this->em->flush();
        $this->numbers = new FactureNumberGenerator(new SequenceNumberManager($this->em->getConnection()));
        $this->issuer = new InvoiceIssuer($this->em, $this->numbers, $this->readiness);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $index => $key) {
            if ($this->database[$index] === null) unset($GLOBALS['_' . $key]['DATABASE_URL']);
            else $GLOBALS['_' . $key]['DATABASE_URL'] = $this->database[$index];
        }
    }

    public function testIssuedPdfAndElectronicDataKeepTheOriginalPartiesTermsAndLines(): void
    {
        $invoice = $this->invoice();
        $this->issuer->persist($invoice);
        $snapshot = InvoiceSnapshot::read($invoice);
        self::assertSame([], InvoiceReadiness::issues($invoice));
        self::assertSame('2026-11-07', $snapshot['terms']['dueDate']);
        self::assertSame('123456789', $snapshot['issuer']['siren']);
        self::assertSame('company', $snapshot['customer']['type']);
        self::assertSame(10000, $snapshot['lines'][0]['htCents']);
        $twig = self::getContainer()->get('twig');
        $original = $twig->render('pdf/facture.html.twig', ['facture' => $invoice, 'entite' => $this->entity]);
        $receiptContext = ['facture' => $invoice, 'entite' => $this->entity, 'paidCents' => 12000, 'datePaiement' => new \DateTimeImmutable('2026-10-08')];
        $originalReceipt = $twig->render('pdf/facture_acquittee.html.twig', $receiptContext);
        self::assertStringContainsString('Client original', $original);
        self::assertStringContainsString('Identité juridique test', $original);
        $this->entity->setNom('Nouveau nom')->setAdresse('Adresse modifiée')->setNumeroTva('FR-NOUVEAU');
        $this->customer->setRaisonSociale('Client renommé')->setAdresse('Nouvelle adresse');
        $invoice->getLignes()->first()->setLabel('Nouvelle désignation')->setPuHtCents(99999);
        $this->entity->getPreferences()->setInvoicePreferences(['confirmed' => true, 'dueDays' => 1]);
        $this->em->flush();
        $this->em->refresh($invoice);
        self::assertSame($snapshot, InvoiceSnapshot::read($invoice), 'The JSON persisted by Doctrine retains the hash and all source facts.');
        self::assertSame($original, $twig->render('pdf/facture.html.twig', ['facture' => $invoice, 'entite' => $this->entity]));
        self::assertSame($originalReceipt, $twig->render('pdf/facture_acquittee.html.twig', $receiptContext));
        self::assertSame([], InvoiceReadiness::issues($invoice), 'Readiness checks use the issued identity, not a changed profile.');
    }

    public function testFailedPersistenceRollsBackTheInvoiceAndItsNumber(): void
    {
        $listener = new class { public function onFlush(): void { throw new \RuntimeException('Persistence failure fixture'); } };
        $events = $this->em->getEventManager();
        $events->addEventListener(['onFlush'], $listener);
        try {
            $this->issuer->persist($this->invoice());
            self::fail('Persistence must fail.');
        } catch (\RuntimeException $error) {
            self::assertSame('Persistence failure fixture', $error->getMessage());
        } finally { $events->removeEventListener(['onFlush'], $listener); }
        $connection = $this->em->getConnection();
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM facture'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM facture_sequence'));
        $number = $connection->transactional(fn() => $this->numbers->nextForEntite($this->entity->getId(), 2026));
        self::assertSame('FAC-E' . $this->entity->getId() . '-2026-0001', $number);
    }

    public function testConsecutiveNumbersAndBackdatingGuard(): void
    {
        $first = $this->invoice(); $second = $this->invoice();
        $this->issuer->persist($first);
        $this->issuer->persist($second);
        self::assertStringEndsWith('-0001', $first->getNumero());
        self::assertStringEndsWith('-0002', $second->getNumero());
        try {
            $this->issuer->persist($this->invoice()->setDateEmission(new \DateTimeImmutable('2026-10-07')));
            self::fail('A backdated invoice must not take the next chronological number.');
        } catch (\DomainException $error) { self::assertStringContainsString('dernière facture', $error->getMessage()); }
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT last FROM facture_sequence'));
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM facture'));
    }

    public function testUnconfirmedDefaultsAndUnknownTaxExemptionAreNeverGuessed(): void
    {
        $this->entity->getPreferences()->setInvoicePreferences(array_replace($this->settings(), ['confirmed' => false]));
        $invoice = $this->invoice()->setMeta([]);
        $invoice->getLignes()->first()->setTva(0);
        InvoicePreferences::apply($invoice);
        self::assertSame([], $invoice->getMeta()['invoice_terms']);
        $issues = implode(' ', InvoiceReadiness::issues($invoice));
        self::assertStringContainsString('Date de prestation', $issues);
        self::assertStringContainsString('aucune exonération n’est présumée', $issues);
        self::assertStringContainsString('Date d’échéance', $issues);
        self::assertNull(InvoiceSnapshot::read($invoice), 'A read never converts a draft or historical invoice into issued data.');
    }

    public function testTamperedSnapshotCannotProduceANewPdf(): void
    {
        $invoice = $this->invoice(); $this->issuer->persist($invoice);
        $meta = $invoice->getMeta(); $meta['invoice_snapshot']['customer']['raisonSociale'] = 'Modified'; $invoice->setMeta($meta);
        self::assertNull(InvoiceSnapshot::read($invoice));
        self::assertStringContainsString('empreinte', InvoiceReadiness::issues($invoice)[0]);
        $this->expectException(\DomainException::class);
        InvoiceSnapshot::forRendering($invoice);
    }

    public function testNumberCannotBeReservedOutsideTheInvoiceTransaction(): void
    {
        $this->expectException(\LogicException::class);
        $this->numbers->nextForEntite($this->entity->getId(), 2026);
    }

    public function testMissingMentionsBlockIssuanceWithoutConsumingANumber(): void
    {
        try {
            $this->issuer->persist($this->invoice()->setMeta([]));
            self::fail('Unknown service date must block issuance.');
        } catch (\DomainException $error) { self::assertStringContainsString('Date de prestation', $error->getMessage()); }
        self::assertTrue($this->em->isOpen());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM facture'));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM facture_sequence'));
        $invoice = $this->invoice(); $this->issuer->persist($invoice);
        self::assertStringEndsWith('-0001', $invoice->getNumero());
    }

    public function testUnconfiguredBillingCannotConsumeANumberOrApplyRelatedChanges(): void
    {
        $this->readiness->expects(self::once())->method('assertCanIssue')->with($this->entity)
            ->willThrowException(new \DomainException('Configurez la facturation électronique.'));
        $invoice = $this->invoice();
        $changed = false;
        try {
            $this->issuer->persist($invoice, function () use (&$changed) { $changed = true; });
            self::fail('An unconfigured organism must not issue an invoice.');
        } catch (\DomainException $error) {
            self::assertSame('Configurez la facturation électronique.', $error->getMessage());
        }
        self::assertFalse($changed);
        self::assertTrue($this->em->isOpen());
        self::assertFalse($this->em->getConnection()->isTransactionActive());
        self::assertNull($invoice->getId());
        self::assertSame('', $invoice->getNumero());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM facture'));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM facture_sequence'));
    }

    public function testDirectDoctrineInsertWithExistingNumberCannotBypassTheGuard(): void
    {
        $this->readiness->expects(self::once())->method('assertCanIssue')->with($this->entity)
            ->willThrowException(new \DomainException('Configurez la facturation électronique.'));
        $invoice = $this->invoice()->setNumero('MANUAL-IMPORT-NUMBER');
        try {
            $this->em->persist($invoice);
            $this->em->flush();
            self::fail('A direct insert must also be rejected.');
        } catch (\DomainException $error) {
            self::assertSame('Configurez la facturation électronique.', $error->getMessage());
        }
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM facture'));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM facture_sequence'));
    }

    public function testExistingInvoiceRemainsReadableWhenBillingIsNoLongerConfigured(): void
    {
        $configured = true;
        $this->readiness->method('assertCanIssue')->willReturnCallback(function () use (&$configured): void {
            if (!$configured) throw new \DomainException('Configuration interrompue.');
        });
        $invoice = $this->invoice();
        $this->issuer->persist($invoice);
        $snapshot = InvoiceSnapshot::read($invoice);
        $configured = false;
        $invoice->setNote('Suivi interne conservé');
        $this->em->flush();
        $html = self::getContainer()->get('twig')->render('pdf/facture.html.twig', ['facture' => $invoice, 'entite' => $this->entity]);
        self::assertSame($snapshot, InvoiceSnapshot::read($invoice));
        self::assertStringContainsString($invoice->getNumero(), $html);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM facture'));
    }

    public function testTaxPointMustBeExplicitAndAcceptsCashAccountingWithoutTruthyCoercion(): void
    {
        $factory = self::getContainer()->get('form.factory');
        $form = $factory->create(\App\Form\Administrateur\InvoiceTermsType::class, null, ['csrf_protection' => false]);
        $form->submit(['vatOnDebits' => '']);
        self::assertTrue($form->isValid());
        self::assertNull($form->getData()['vatOnDebits']);
        $invoice = $this->invoice();
        InvoicePreferences::apply($invoice);
        $meta = $invoice->getMeta(); $meta['invoice_terms']['vatOnDebits'] = null; $invoice->setMeta($meta);
        self::assertStringContainsString('Exigibilité de la TVA', implode(' ', InvoiceReadiness::issues($invoice)));
        $form = $factory->create(\App\Form\Administrateur\InvoiceTermsType::class, null, ['csrf_protection' => false]);
        $form->submit(['vatOnDebits' => '0']);
        self::assertTrue($form->isValid());
        self::assertFalse($form->getData()['vatOnDebits']);
    }

    public function testExplicitExemptionCanBeIssuedWithoutInventingVatRegistration(): void
    {
        $this->entity->setNumeroTva(null);
        $invoice = $this->invoice(); $invoice->getLignes()->first()->setTva(0);
        $invoice->setMeta(['invoice_terms' => ['serviceDate' => '2026-10-07', 'vatCategory' => 'E', 'vatExemptionReason' => 'Exonération confirmée pour le scénario de test']]);
        $this->issuer->persist($invoice);
        self::assertSame([], InvoiceReadiness::issues($invoice));
        self::assertSame(0, $invoice->getMontantTvaCents());
        self::assertSame('E', InvoiceSnapshot::read($invoice)['terms']['vatCategory']);
    }

    private function invoice(): Facture
    {
        return (new Facture())->setEntite($this->entity)->setCreateur($this->user)->setEntrepriseDestinataire($this->customer)->setDateEmission(new \DateTimeImmutable('2026-10-08'))
            ->setMeta(['invoice_terms' => ['serviceDate' => '2026-10-07']])
            ->addLigne((new LigneFacture())->setEntite($this->entity)->setCreateur($this->user)->setLabel('Prestation originale')->setQte(1)->setPuHtCents(10000)->setTva(20));
    }

    private function settings(): array
    {
        return ['confirmed' => true, 'dueDays' => 30, 'paymentMeans' => 'Virement', 'earlyPaymentDiscount' => 'Pas d’escompte',
            'latePaymentPenalty' => 'Taux contractuel vérifié', 'recoveryCompensationCents' => 4000, 'legalIdentity' => 'Identité juridique test', 'vatCategory' => 'S', 'vatOnDebits' => false];
    }
}
