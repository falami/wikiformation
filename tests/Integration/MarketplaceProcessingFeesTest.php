<?php
declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\{Entite, Utilisateur};
use App\Entity\Elearning\ElearningCourse;
use App\Entity\Marketplace\{Access, Purchase, Seller, Settings};
use App\Service\Marketplace\{Invoicing, MarketplaceInvoiceReadiness, Payments, Policy, StripeGateway};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class MarketplaceProcessingFeesTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private array $environment;
    private Purchase $purchase;

    protected function setUp(): void
    {
        $this->environment = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $buyer = (new Utilisateur())->setEmail('fee-buyer@example.test')->setPassword('unused')->setPrenom('Buyer')->setNom('Fixture');
        $trainer = (new Utilisateur())->setEmail('fee-seller@example.test')->setPassword('unused')->setPrenom('Seller')->setNom('Fixture');
        $entity = (new Entite())->setNom('Fee fixture')->setCreateur($trainer)->setPublic(false);
        foreach ([$buyer, $trainer, $entity] as $row) $this->em->persist($row);
        $course = (new ElearningCourse())->setEntite($entity)->setCreateur($trainer)->setTitre('Course')->setSlug('fee-course');
        $this->em->persist($course);
        $seller = new Seller();
        $seller->user = $trainer;
        $seller->accountId = 'acct_seller';
        $this->em->persist($seller);
        $settings = new Settings();
        $settings->enabled = true;
        $this->em->persist($settings);
        $this->em->flush();
        $this->purchase = new Purchase();
        $this->purchase->id = 'processing-fees-fixture';
        $this->purchase->buyer = $buyer;
        $this->purchase->seller = $seller;
        $this->purchase->createdAt = new \DateTimeImmutable('-20 days');
        $this->purchase->checkoutId = 'cs_fees';
        $this->purchase->totalCents = 12000;
        $this->purchase->commissionCents = 2000;
        $this->purchase->commissionTaxCents = 400;
        $this->purchase->items = [['course' => $course->getId(), 'snapshot' => ['title' => 'Course']]];
        $this->em->persist($this->purchase);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $index => $name) {
            if ($this->environment[$index] === null) unset($GLOBALS['_'.$name]['DATABASE_URL']);
            else $GLOBALS['_'.$name]['DATABASE_URL'] = $this->environment[$index];
        }
    }

    private static function balance(int $fee = 210): array
    {
        return ['id' => 'txn_fees', 'object' => 'balance_transaction', 'source' => 'ch_fees',
            'amount' => 12000, 'currency' => 'eur', 'fee' => $fee, 'net' => 12000 - $fee,
            'status' => 'available', 'available_on' => time() - 86400];
    }

    private static function payment(mixed $balance, int $refund = 0): array
    {
        return ['id' => 'pi_fees', 'status' => 'succeeded', 'currency' => 'eur', 'amount_received' => 12000,
            'latest_charge' => ['id' => 'ch_fees', 'created' => time() - 20 * 86400, 'amount_refunded' => $refund,
                'balance_transaction' => $balance]];
    }

    private function gateway(): StripeGateway
    {
        $gateway = $this->createMock(StripeGateway::class);
        $gateway->method('session')->willReturn(['id' => 'cs_fees', 'metadata' => ['marketplace_purchase' => $this->purchase->id],
            'amount_total' => 12000, 'currency' => 'eur', 'payment_status' => 'paid', 'payment_intent' => 'pi_fees']);
        $gateway->expects(self::never())->method('checkout');
        return $gateway;
    }

    private function service(StripeGateway $gateway): Payments
    {
        return new Payments($this->em, $gateway, new Policy($this->em), self::getContainer()->get(UrlGeneratorInterface::class),
            self::getContainer()->get(Invoicing::class), self::getContainer()->get(MarketplaceInvoiceReadiness::class));
    }

    public function testAsynchronousFeesWaitUntilNextReconciliationWithoutLosingPaidAccess(): void
    {
        $gateway = $this->gateway();
        // On the old path the second response arrived after delta was computed without fees.
        $gateway->expects(self::exactly(2))->method('payment')->willReturn(self::payment(null), self::payment(self::balance()));
        $gateway->expects(self::once())->method('transfer')->with('acct_seller', 9390, 'ch_fees', self::anything())->willReturn('tr_fees');
        $service = $this->service($gateway);
        $service->settle($this->purchase);
        self::assertSame('paid', $this->purchase->status);
        self::assertSame('pending', $this->purchase->consent['stripe_processing_fees']['status']);
        self::assertSame(0, $this->purchase->transferredCents);
        self::assertSame(0, $this->purchase->sellerCents);
        self::assertSame(1, $this->em->getRepository(Access::class)->count([]));
        $service->settle($this->purchase);
        self::assertSame(210, $this->purchase->stripeFeeCents);
        self::assertSame(9390, $this->purchase->transferredCents);
        self::assertSame('verified', $this->purchase->consent['stripe_processing_fees']['status']);
    }

    #[DataProvider('invalidBalances')]
    public function testInconsistentFeesNeverDisburseOrEraseTheConfirmedPayment(mixed $balance): void
    {
        $gateway = $this->gateway();
        $gateway->expects(self::once())->method('payment')->willReturn(self::payment($balance));
        $gateway->expects(self::never())->method('transfer');
        $gateway->expects(self::never())->method('reverse');
        $this->service($gateway)->settle($this->purchase);
        self::assertSame('paid', $this->purchase->status);
        self::assertSame('reconciliation_required', $this->purchase->consent['stripe_processing_fees']['status']);
        self::assertSame(0, $this->purchase->transferredCents);
        self::assertSame(1, $this->em->getRepository(Access::class)->count([]));
    }

    public static function invalidBalances(): iterable
    {
        yield 'unexpanded' => ['txn_fees'];
        foreach ([
            'foreign source' => ['source' => 'ch_other'], 'missing source' => ['source' => null],
            'foreign currency' => ['currency' => 'usd'], 'missing fee' => ['fee' => null],
            'string fee' => ['fee' => '210'], 'float fee' => ['fee' => 210.5], 'negative fee' => ['fee' => -1],
            'wrong amount' => ['amount' => 11999], 'wrong net' => ['net' => 12000],
            'wrong object' => ['object' => 'charge'], 'wrong id' => ['id' => 'ch_fees'],
            'missing availability' => ['available_on' => null], 'unknown status' => ['status' => 'unknown'],
        ] as $name => $override) yield $name => [array_replace(self::balance(), $override)];
    }

    public function testARealZeroFeeIsAcceptedAndNeverDeductedTwice(): void
    {
        $gateway = $this->gateway();
        $gateway->method('payment')->willReturn(self::payment(self::balance(0)));
        $gateway->expects(self::once())->method('transfer')->with('acct_seller', 9600, 'ch_fees', self::anything())->willReturn('tr_zero');
        $gateway->expects(self::never())->method('reverse');
        $service = $this->service($gateway);
        $service->settle($this->purchase);
        $service->settle($this->purchase);
        self::assertSame(0, $this->purchase->stripeFeeCents);
        self::assertSame(9600, $this->purchase->transferredCents);
        self::assertSame('verified', $this->purchase->consent['stripe_processing_fees']['status']);
    }

    public function testUpdatedActualFeeAdjustsOnlyTheDifferenceOnReplay(): void
    {
        $fee = 210;
        $gateway = $this->gateway();
        $gateway->method('payment')->willReturnCallback(function () use (&$fee) { return self::payment(self::balance($fee)); });
        $gateway->expects(self::once())->method('transfer')->with('acct_seller', 9390, 'ch_fees', self::anything())->willReturn('tr_adjustment');
        $gateway->method('transferState')->willReturn(['amount' => 9390, 'currency' => 'eur', 'destination' => 'acct_seller', 'amount_reversed' => 0]);
        $gateway->expects(self::once())->method('reverse')->with('tr_adjustment', 90, self::anything());
        $service = $this->service($gateway);
        $service->settle($this->purchase);
        $fee = 300;
        $service->settle($this->purchase);
        $service->settle($this->purchase);
        self::assertSame(9300, $this->purchase->transferredCents);
        self::assertSame(300, $this->purchase->stripeFeeCents);
        self::assertSame(90, $this->purchase->payoutHistory[0]['reversed']);
    }

    public function testChangedBalanceIdentityBlocksAnyNewTransfer(): void
    {
        $balance = self::balance();
        $balance['status'] = 'pending';
        $gateway = $this->gateway();
        $gateway->method('payment')->willReturnCallback(function () use (&$balance) { return self::payment($balance); });
        $gateway->expects(self::never())->method('transfer');
        $service = $this->service($gateway);
        $service->settle($this->purchase);
        $balance['status'] = 'available';
        $balance['id'] = 'txn_foreign';
        $service->settle($this->purchase);
        self::assertSame('reconciliation_required', $this->purchase->consent['stripe_processing_fees']['status']);
        self::assertSame('txn_fees', $this->purchase->consent['stripe_processing_fees']['lastVerified']['id']);
    }

    public function testVerifiedFeesDoNotAuthorizeFundsBeforeTheirAvailabilityDate(): void
    {
        $balance = self::balance();
        $balance['available_on'] = time() + 86400;
        $gateway = $this->gateway();
        $gateway->method('payment')->willReturn(self::payment($balance));
        $gateway->expects(self::never())->method('transfer');
        $this->service($gateway)->settle($this->purchase);
        self::assertSame('verified', $this->purchase->consent['stripe_processing_fees']['status']);
        self::assertSame(0, $this->purchase->transferredCents);
    }

    public function testPreviouslyVerifiedFeesAreNotUsedWhenCurrentFeesAreUnavailable(): void
    {
        $balance = self::balance();
        $balance['status'] = 'pending';
        $gateway = $this->gateway();
        $gateway->method('payment')->willReturn(self::payment($balance), self::payment(null));
        $gateway->expects(self::never())->method('transfer');
        $service = $this->service($gateway);
        $service->settle($this->purchase);
        $service->settle($this->purchase);
        self::assertSame(210, $this->purchase->stripeFeeCents);
        self::assertSame('pending', $this->purchase->consent['stripe_processing_fees']['status']);
        self::assertSame(210, $this->purchase->consent['stripe_processing_fees']['lastVerified']['fee']);
        self::assertSame(0, $this->purchase->transferredCents);
    }

    public function testFullRefundStillRecoversTransferredMoneyWhenFeesAreUnavailable(): void
    {
        $gateway = $this->gateway();
        $gateway->method('payment')->willReturn(self::payment(self::balance()), self::payment(null, 12000));
        $gateway->expects(self::once())->method('transfer')->with('acct_seller', 9390, 'ch_fees', self::anything())->willReturn('tr_refund');
        $gateway->method('transferState')->willReturn(['amount' => 9390, 'currency' => 'eur', 'destination' => 'acct_seller', 'amount_reversed' => 0]);
        $gateway->expects(self::once())->method('reverse')->with('tr_refund', 9390, self::anything());
        $service = $this->service($gateway);
        $service->settle($this->purchase);
        $service->settle($this->purchase);
        self::assertSame('refunded', $this->purchase->status);
        self::assertSame(0, $this->purchase->transferredCents);
        self::assertTrue($this->em->getRepository(Access::class)->findOneBy([])->suspended);
    }

    #[DataProvider('invalidRefundTotals')]
    public function testInvalidOrRegressingRefundCannotChangeConfirmedStateOrTransfer(int $confirmedRefund, mixed $reportedRefund): void
    {
        // Keep document generation suspended as in production. A previously issued credit
        // must not be needed to protect the independently confirmed payment/refund ledger.
        $this->purchase->billingVersion = 1;
        $this->em->flush();
        $payment = self::payment(self::balance(), $confirmedRefund);
        $gateway = $this->gateway();
        $gateway->method('payment')->willReturnCallback(function () use (&$payment) { return $payment; });
        $gateway->expects(self::never())->method('findTransfer');
        $gateway->expects(self::never())->method('transfer');
        $gateway->expects(self::never())->method('reverse');
        $service = $this->service($gateway);
        $service->sync($this->purchase);
        $access = $this->em->getRepository(Access::class)->findOneBy([]);
        self::assertSame($confirmedRefund === 12000, $access->suspended);
        self::assertSame('blocked', $this->purchase->consent['invoice_processing']['status']);
        $db = $this->em->getConnection();
        $purchaseBefore = $db->fetchAssociative('SELECT * FROM marketplace_purchase WHERE id = ?', [$this->purchase->id]);
        $accessBefore = $db->fetchAllAssociative('SELECT * FROM marketplace_access');
        $invoicesBefore = $db->fetchAllAssociative('SELECT * FROM marketplace_invoice');

        if ($reportedRefund === null) unset($payment['latest_charge']['amount_refunded']);
        else $payment['latest_charge']['amount_refunded'] = $reportedRefund;
        try {
            $service->settle($this->purchase);
            self::fail('An incomplete or regressing refund must require reconciliation.');
        } catch (\DomainException $error) {
            self::assertStringContainsString('remboursement Stripe incohérent', $error->getMessage());
        }

        self::assertSame($purchaseBefore, $db->fetchAssociative('SELECT * FROM marketplace_purchase WHERE id = ?', [$this->purchase->id]));
        self::assertSame($accessBefore, $db->fetchAllAssociative('SELECT * FROM marketplace_access'));
        self::assertSame($invoicesBefore, $db->fetchAllAssociative('SELECT * FROM marketplace_invoice'));
        self::assertSame($confirmedRefund, $this->purchase->refundedCents);
        self::assertSame($confirmedRefund === 12000, $access->suspended);
    }

    public static function invalidRefundTotals(): iterable
    {
        foreach ([0, 6000, 12000] as $confirmed) {
            foreach (['missing' => null, 'string' => (string) $confirmed, 'float' => (float) $confirmed,
                'boolean' => false, 'negative' => -1, 'excess' => 12001] as $name => $reported) {
                yield $confirmed.' '.$name => [$confirmed, $reported];
            }
            if ($confirmed > 0) yield $confirmed.' regression' => [$confirmed, $confirmed - 1];
        }
    }

    #[DataProvider('invalidTransferAmounts')]
    public function testMalformedTransferRecoveryNeverChangesLedgerOrMovesMoney(bool $knownTransfer, array $override): void
    {
        $this->purchase->status = 'paid';
        $this->purchase->paidAt = new \DateTimeImmutable('-20 days');
        $this->purchase->paymentId = 'pi_fees';
        $this->purchase->chargeId = 'ch_fees';
        if ($knownTransfer) {
            $this->purchase->transferId = 'tr_fees';
            $this->purchase->transferredCents = 9390;
            $this->purchase->payoutHistory = [['id'=>'tr_fees','amount'=>9390,'reversed'=>0]];
        }
        $this->em->flush();
        $db = $this->em->getConnection();
        $before = $db->fetchAssociative('SELECT * FROM marketplace_purchase WHERE id = ?', [$this->purchase->id]);
        $remote = array_replace(['id'=>'tr_fees','amount'=>9390,'currency'=>'eur',
            'destination'=>'acct_seller','source_transaction'=>'ch_fees','amount_reversed'=>0], $override);
        $gateway = $this->gateway();
        $gateway->method('payment')->willReturn(self::payment(self::balance(), 12000));
        if ($knownTransfer) $gateway->expects(self::once())->method('transferState')->with('tr_fees')->willReturn($remote);
        else $gateway->expects(self::once())->method('findTransfer')->willReturn($remote);
        $gateway->expects(self::never())->method('transfer');
        $gateway->expects(self::never())->method('reverse');
        try {
            $this->service($gateway)->settle($this->purchase);
            self::fail('An invalid remote monetary amount must not be coerced into the ledger.');
        } catch (\DomainException) {
            self::assertSame($before, $db->fetchAssociative('SELECT * FROM marketplace_purchase WHERE id = ?', [$this->purchase->id]));
            self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM marketplace_access'));
        }
    }

    public static function invalidTransferAmounts(): iterable
    {
        foreach ([false, true] as $known) {
            foreach ([
                'string transfer'=>['amount'=>'9390'], 'float transfer'=>['amount'=>9390.5],
                'missing reversed'=>['amount_reversed'=>null], 'string reversed'=>['amount_reversed'=>'0'],
                'float reversed'=>['amount_reversed'=>0.5], 'boolean reversed'=>['amount_reversed'=>false],
                'negative reversed'=>['amount_reversed'=>-1], 'excess reversed'=>['amount_reversed'=>9391],
            ] as $name => $override) yield ($known ? 'recorded ' : 'lost ').$name => [$known, $override];
        }
    }

    public function testLegacyPlatformFeeAgreementIsNotChangedByCurrentSettings(): void
    {
        $this->purchase->feesPayer = 'platform';
        $this->em->flush();
        $gateway = $this->gateway();
        $gateway->method('payment')->willReturn(self::payment(self::balance()));
        $gateway->expects(self::once())->method('transfer')->with('acct_seller', 9600, 'ch_fees', self::anything())->willReturn('tr_legacy');
        $this->service($gateway)->settle($this->purchase);
        self::assertSame('platform', $this->purchase->feesPayer);
        self::assertSame(210, $this->purchase->stripeFeeCents);
        self::assertSame(9600, $this->purchase->transferredCents);
    }

    public function testNegativeNetDoesNotCauseAnAdditionalChargeOrNegativeTransfer(): void
    {
        $gateway = $this->gateway();
        $gateway->method('payment')->willReturn(self::payment(self::balance(12500)));
        $gateway->expects(self::never())->method('transfer');
        $gateway->expects(self::never())->method('reverse');
        $this->service($gateway)->settle($this->purchase);
        self::assertSame(12500, $this->purchase->stripeFeeCents);
        self::assertSame(0, $this->purchase->sellerCents);
        self::assertSame(0, $this->purchase->transferredCents);
    }
}
