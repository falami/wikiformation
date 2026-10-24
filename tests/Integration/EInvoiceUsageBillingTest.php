<?php
declare(strict_types=1);
namespace App\Tests\Integration;

use App\Command\EInvoiceUsageBillingCommand;
use App\Entity\{Entite, Utilisateur, Facture, EInvoiceConnection, EInvoiceTransmission, EInvoiceUsage, EInvoiceUsageBatch};
use App\Entity\Billing\EntiteSubscription;
use App\Service\EInvoicing\{EInvoiceUsageBilling, ConnectionLease, UsageInvoiceGateway};
use App\Repository\Billing\EntiteSubscriptionRepository;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class EInvoiceUsageBillingTest extends KernelTestCase
{
    private array $database;
    private $em;
    private Entite $entite;
    private Utilisateur $user;
    private EInvoiceConnection $connection;
    private EntiteSubscription $sub;
    private EInvoiceUsageBilling $billing;
    private UsageInvoiceGateway $gateway;
    protected function setUp(): void
    {
        $this->database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel(); $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->user = (new Utilisateur())->setEmail('usage@example.test')->setPassword('fixture')->setNom('Usage')->setPrenom('Test');
        $this->entite = (new Entite())->setNom('Usage fixture')->setPublic(false)->setCreateur($this->user);
        $this->em->persist($this->user); $this->em->persist($this->entite); $this->em->flush();
        $this->connection = new EInvoiceConnection($this->entite, 'production'); $this->connection->consentToBilling($this->user->getId());
        $this->sub = (new EntiteSubscription())->setEntite($this->entite)->setStatus('active')->setStripeCustomerId('cus_fixture')->setStripeSubscriptionId('sub_fixture');
        $this->em->persist($this->connection); $this->em->persist($this->sub); $this->em->flush();
        $this->gateway = new class implements UsageInvoiceGateway {
            public int $validations = 0; public int $creates = 0; public int $finds = 0; public bool $fail = false; public ?string $found = null; public array $payloads = [];
            public function validate(EInvoiceUsageBatch $b): void { ++$this->validations; }
            public function create(EInvoiceUsageBatch $b): string { ++$this->creates; $this->payloads[] = $b->getPayload(); if ($this->fail) throw new \RuntimeException('Unknown result'); return 'ii_fixture'.$b->getId(); }
            public function find(EInvoiceUsageBatch $b): ?string { ++$this->finds; return $this->found; }
        };
        $this->billing = new EInvoiceUsageBilling($this->em, self::getContainer()->get(EntiteSubscriptionRepository::class), self::getContainer()->get(ConnectionLease::class), $this->gateway);
    }
    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV','SERVER'] as $i => $name) { if ($this->database[$i] === null) unset($GLOBALS['_'.$name]['DATABASE_URL']); else $GLOBALS['_'.$name]['DATABASE_URL'] = $this->database[$i]; }
    }
    private function transmission(?EInvoiceConnection $connection = null, bool $prepare = true): EInvoiceTransmission
    {
        $connection ??= $this->connection;
        $f = (new Facture())->setEntite($connection->getEntite())->setCreateur($this->user)->setNumero('FIX-'.bin2hex(random_bytes(5)))->setMontantHtCents(1000)->setMontantTvaCents(200)->setMontantTtcCents(1200);
        $this->em->persist($f); $this->em->flush();
        $t = new EInvoiceTransmission($connection, $f); $t->prepared('<Invoice/>');
        $this->em->persist($t); $this->em->flush();
        if ($prepare) $this->billing->prepare($t);
        $t->begin(); $this->em->flush(); return $t;
    }
    private function accept(EInvoiceTransmission $t, string $id = '100', string $date = '2020-09-20T12:00:00+02:00'): void
    {
        $t->accepted($id); (new \ReflectionProperty($t, 'acceptedAt'))->setValue($t, new \DateTimeImmutable($date));
        $this->billing->record($t); $this->em->flush();
    }
    public function testOwnerExemptionSkipsNewChargesButPreservesFrozenHistory(): void
    {
        $old = $this->transmission();
        $this->entite->setSiret('80813612100023'); $this->em->flush();
        self::assertSame(1, $this->entite->getId());
        self::assertSame(0, $this->connection->getUnitPriceCents());
        $this->accept($old, '801');
        $this->em->remove($this->sub); $this->em->flush();
        self::assertNull($this->billing->assertEligible($this->connection));
        $this->accept($this->transmission(), '802');
        self::assertSame(1, $this->em->getRepository(EInvoiceUsage::class)->count([]));
        $summary = $this->billing->summary($this->entite, new \DateTimeImmutable('2020-09-30'));
        self::assertSame(0, $summary['unitPriceCents']);
        self::assertSame(2, $summary['amountCents']);
        $this->billing->process($this->connection, '2020-09', true);
        $prices = array_column($this->gateway->payloads, 'unit_amount_decimal'); sort($prices);
        self::assertSame(['2'], $prices);
        $other = (new Entite())->setNom('Other')->setSiret('80813612100023')->setPublic(false)->setCreateur($this->user);
        $this->em->persist($other); $this->em->flush();
        self::assertSame(2, \App\Service\EInvoicing\EInvoiceTariff::unitPriceCents($other));
    }

    public function testConfirmedUsageIsUniqueAndSnapshotSurvivesChanges(): void
    {
        $t = $this->transmission(); $this->sub->setStripeSubscriptionId('sub_changed'); $this->connection->disconnect();
        $this->accept($t); $this->billing->record($t); $this->billing->record($t); $this->em->flush();
        $usage = $this->em->getRepository(EInvoiceUsage::class)->findOneBy(['transmission' => $t]);
        self::assertSame('sub_fixture', $usage->getStripeSubscriptionId()); self::assertSame(2, $usage->getUnitPriceCents());
        self::assertSame(1, $this->em->getRepository(EInvoiceUsage::class)->count([])); self::assertSame(1200, $t->getFacture()->getMontantTtcCents());
        self::assertSame(['unitPriceCents'=>2,'period'=>'2020-09','quantity'=>1,'amountCents'=>2], $this->billing->summary($this->entite, new \DateTimeImmutable('2020-09-30')));
        self::assertSame(0, $this->gateway->creates);
    }
    public function testTermsAreRefreshedBeforeAttemptAndImmutableAfter(): void
    {
        $f = (new Facture())->setEntite($this->entite)->setCreateur($this->user)->setNumero('TERMS')->setMontantHtCents(1000)->setMontantTvaCents(200)->setMontantTtcCents(1200);
        $this->em->persist($f); $this->em->flush();
        $t = new EInvoiceTransmission($this->connection, $f); $t->prepared('<Invoice/>');
        $this->billing->prepare($t); self::assertSame('sub_fixture', $t->getUsageTerms()['subscription']);
        self::assertSame(['providerCostCents' => 1, 'platformShareCents' => 1, 'version' => 1], $t->getUsageTerms()['internalAllocation']);
        $this->sub->setStripeSubscriptionId('sub_replacement'); $this->em->flush();
        $this->billing->prepare($t); self::assertSame('sub_replacement', $t->getUsageTerms()['subscription']);
        $t->begin(); $terms = $t->getUsageTerms();
        try { $t->captureUsageTerms($terms + ['unexpected' => true]); self::fail('Attempt must freeze terms'); } catch (\LogicException) { self::assertSame($terms, $t->getUsageTerms()); }
    }
    public function testSameProviderIdentifierCannotChargeTwice(): void
    {
        $this->accept($this->transmission(), '900');
        $this->accept($this->transmission(), '900');
        self::assertSame(1, $this->em->getRepository(EInvoiceUsage::class)->count([]));
        self::assertSame(2, $this->billing->process($this->connection, '2020-09')['amountCents']);
    }
    public function testSandboxUncertainAndHistoricalAreNotCharged(): void
    {
        $sandbox = new EInvoiceConnection($this->entite, 'sandbox'); $this->em->persist($sandbox); $this->em->flush();
        $this->accept($this->transmission($sandbox), '101');
        $this->accept($this->transmission(prepare: false), '102');
        $unknown = $this->transmission(); $unknown->uncertain(); $this->billing->record($unknown); $this->em->flush();
        self::assertSame(0, $this->em->getRepository(EInvoiceUsage::class)->count([]));
        $this->accept($unknown, '103'); self::assertSame(1, $this->em->getRepository(EInvoiceUsage::class)->count([]));
    }
    public function testEligibilityNeedsExplicitConsentAndStripeSubscription(): void
    {
        $other = (new Entite())->setNom('Other')->setPublic(false)->setCreateur($this->user); $this->em->persist($other); $this->em->flush();
        $connection = new EInvoiceConnection($other, 'production');
        try { $this->billing->assertEligible($connection); self::fail('Consent required'); } catch (\DomainException $e) { self::assertStringContainsString('Acceptez', $e->getMessage()); }
        $connection->consentToBilling($this->user->getId());
        try { $this->billing->assertEligible($connection); self::fail('Subscription required'); } catch (\DomainException $e) { self::assertStringContainsString('abonnement Stripe', $e->getMessage()); }
        $this->sub->setStatus('canceled'); $this->em->flush();
        $this->expectException(\DomainException::class); $this->billing->assertEligible($this->connection);
    }
    public function testMonthlyAggregationAndDryRunAreIdempotentAndDoNotTouchInvoice(): void
    {
        $this->accept($this->transmission(), '201'); $this->accept($this->transmission(), '202');
        $result = $this->billing->process($this->connection, '2020-09');
        self::assertSame(4, $result['amountCents']); self::assertSame(0, $this->gateway->validations); self::assertSame(0, $this->em->getRepository(EInvoiceUsageBatch::class)->count([]));
        $result = $this->billing->process($this->connection, '2020-09', true);
        self::assertSame(1, $result['recorded']); self::assertSame(1, $this->gateway->creates);
        $this->billing->process($this->connection, '2020-09', true); self::assertSame(1, $this->gateway->creates);
        self::assertSame(2, $this->gateway->payloads[0]['quantity']); self::assertSame('2', $this->gateway->payloads[0]['unit_amount_decimal']);
        self::assertStringNotContainsString('internalAllocation', json_encode($this->gateway->payloads[0]));
        self::assertStringNotContainsString('providerCostCents', json_encode($this->gateway->payloads[0]));
        self::assertSame('sub_fixture', $this->gateway->payloads[0]['subscription']); self::assertSame('exclusive', $this->gateway->payloads[0]['tax_behavior']);
        $this->accept($this->transmission(), '203'); $this->billing->process($this->connection, '2020-09', true);
        self::assertSame(2, $this->gateway->creates); self::assertSame(1, $this->gateway->payloads[1]['quantity']);
        self::assertNotSame($this->gateway->payloads[0]['metadata']['wf_einvoice_batch'], $this->gateway->payloads[1]['metadata']['wf_einvoice_batch']);
    }
    public function testUncertainStripeResultIsReconciledWithoutReposting(): void
    {
        $this->accept($this->transmission()); $this->gateway->fail = true;
        self::assertSame(1, $this->billing->process($this->connection, '2020-09', true)['uncertain']);
        $batch = $this->em->getRepository(EInvoiceUsageBatch::class)->findOneBy([]);
        (new \ReflectionProperty($batch, 'attemptedAt'))->setValue($batch, new \DateTimeImmutable('-3 days')); $this->em->flush();
        self::assertSame(1, $this->billing->process($this->connection, '2020-09', true)['uncertain']);
        self::assertSame(1, $this->gateway->creates);
        self::assertSame(1, $this->billing->process($this->connection, '2020-09', true, true)['uncertain']);
        $this->gateway->found = 'ii_reconciled';
        self::assertSame(1, $this->billing->process($this->connection, '2020-09', true, true)['recorded']);
        self::assertSame('ii_reconciled', $batch->getStripeInvoiceItemId()); self::assertSame(1, $this->gateway->creates);
    }
    public function testOuterTransactionCannotStartBillingBeforeItsCommit(): void
    {
        $this->accept($this->transmission());
        $db = $this->em->getConnection();
        $db->beginTransaction();
        try {
            $db->executeStatement('UPDATE entite SET nom = ? WHERE id = ?', ['Uncommitted fixture', $this->entite->getId()]);
            try {
                $this->billing->process($this->connection, '2020-09', true);
                self::fail('Billing must refuse an outer transaction');
            } catch (\LogicException $e) {
                self::assertStringContainsString('hors transaction', $e->getMessage());
            }
            self::assertTrue($db->isTransactionActive(), 'The caller retains ownership of its transaction.');
            self::assertSame(0, $this->gateway->validations);
            self::assertSame(0, $this->gateway->creates);
            self::assertSame(0, $this->gateway->finds);
            self::assertSame(0, $this->em->getRepository(EInvoiceUsageBatch::class)->count([]));
            self::assertNull($db->fetchOne('SELECT worker_token FROM einvoice_connection WHERE id = ?', [$this->connection->getId()]));
        } finally {
            if ($db->isTransactionActive()) $db->rollBack();
        }
        self::assertFalse($db->isTransactionActive());
        self::assertSame('Usage fixture', $db->fetchOne('SELECT nom FROM entite WHERE id = ?', [$this->entite->getId()]));
        self::assertSame(1, $this->em->getRepository(EInvoiceUsage::class)->count([]), 'Previously committed usage is retained.');
        self::assertSame(0, $this->em->getRepository(EInvoiceUsageBatch::class)->count([]));
    }
    public function testConnectionLeasePreventsConcurrentBilling(): void
    {
        $this->accept($this->transmission()); $lease = self::getContainer()->get(ConnectionLease::class); $token = $lease->acquire($this->connection->getId());
        try { $this->billing->process($this->connection, '2020-09', true); self::fail('Concurrent billing denied'); } catch (\RuntimeException $e) { self::assertStringContainsString('déjà en cours', $e->getMessage()); }
        finally { $lease->release($this->connection->getId(), $token); }
        self::assertSame(0, $this->gateway->creates); self::assertSame(0, $this->em->getRepository(EInvoiceUsageBatch::class)->count([]));
    }
    public function testCalendarUsesParisAndTenantSummaryIsolated(): void
    {
        $this->accept($this->transmission(), '301', '2020-09-30T22:30:00+00:00');
        self::assertSame(0, $this->billing->process($this->connection, '2020-09')['quantity']);
        self::assertSame(1, $this->billing->process($this->connection, '2020-10')['quantity']);
        $other = (new Entite())->setNom('Private')->setPublic(false)->setCreateur($this->user); $this->em->persist($other); $this->em->flush();
        self::assertSame(0, $this->billing->summary($other, new \DateTimeImmutable('2020-10-05'))['quantity']);
    }
    public function testCommandDefaultsToSimulationAndRejectsCurrentMonth(): void
    {
        $this->accept($this->transmission());
        $command = new CommandTester(new EInvoiceUsageBillingCommand($this->em, $this->billing));
        self::assertSame(0, $command->execute(['--month'=>'2020-09','--entite'=>(string) $this->entite->getId()]));
        self::assertStringContainsString('Simulation', $command->getDisplay()); self::assertSame(0, $this->gateway->creates);
        self::assertSame(0, $this->em->getRepository(EInvoiceUsageBatch::class)->count([]));
        self::assertSame(2, $command->execute(['--month'=>(new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('Y-m')]));
    }
}
