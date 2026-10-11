<?php

declare(strict_types=1);

namespace App\Tests\Service\Billing;

use App\Entity\{Avoir, Entite, Facture, LigneFacture, Paiement};
use App\Service\Automation\WorkflowBilling;
use App\Service\Billing\InvoiceTotals;
use App\Twig\InvoiceTotalsExtension;
use PHPUnit\Framework\TestCase;
use Twig\{Environment, Loader\FilesystemLoader, TwigFunction};

final class InvoiceTotalsTest extends TestCase
{
    private function line(int $ht, float $vat = 20, bool $debours = false): LigneFacture
    {
        return (new LigneFacture())->setLabel('Formation')->setQte(1)->setPuHtCents($ht)->setTva($vat)->setIsDebours($debours);
    }

    public function testGlobalDiscountReducesVatAndDoesNotCreateFalseUnpaidBalance(): void
    {
        $f = (new Facture())->setRemiseGlobalePourcent(10)->addLigne($this->line(10000));
        $totals = InvoiceTotals::calculate($f);
        self::assertSame(9000, $totals['htCents']);
        self::assertSame(1800, $totals['tvaCents']);
        self::assertSame(10800, $totals['ttcCents']);
        $f->addPaiement((new Paiement())->setMontantCents(10000));
        self::assertSame(800, WorkflowBilling::outstanding($f));
        $f->addAvoir((new Avoir())->setMontantTtcCents(800));
        self::assertSame(0, WorkflowBilling::outstanding($f));
    }

    public function testMixedRatesFixedDiscountAndDeboursAreCountedExactlyOnce(): void
    {
        $f = (new Facture())->setRemiseGlobaleMontantCents(100)
            ->addLigne($this->line(1000))->addLigne($this->line(1000, 5.5))->addLigne($this->line(1000, 0, true));
        $totals = InvoiceTotals::calculate($f);
        self::assertSame(100, $totals['globalDiscountCents']);
        self::assertSame(2900, $totals['htCents']);
        self::assertSame(['htCents' => 966, 'tvaCents' => 193], $totals['rates']['20.00']);
        self::assertSame(['htCents' => 967, 'tvaCents' => 53], $totals['rates']['5.50']);
        self::assertSame(967, $totals['deboursTtcCents']);
        self::assertSame(3146, $totals['ttcCents']);
        self::assertSame(3146, WorkflowBilling::outstanding($f));
    }

    public function testEveryDiscountCentIsAllocatedEvenAcrossManySmallLines(): void
    {
        $f = (new Facture())->setRemiseGlobaleMontantCents(49);
        for ($i = 0; $i < 100; ++$i) $f->addLigne($this->line(1, 0));
        $totals = InvoiceTotals::calculate($f);
        self::assertSame(49, $totals['globalDiscountCents']);
        self::assertSame(51, $totals['ttcCents']);
    }

    public function testLineDiscountComesBeforeGlobalDiscountAndAmountsCannotBeNegative(): void
    {
        $f = (new Facture())->setRemiseGlobalePourcent(10)->addLigne($this->line(10000)->setRemiseMontantCents(1000));
        $totals = InvoiceTotals::calculate($f);
        self::assertSame(1000, $totals['lineDiscountCents']);
        self::assertSame(900, $totals['globalDiscountCents']);
        self::assertSame(9720, $totals['ttcCents']);
        $f->setRemiseGlobaleMontantCents(100000);
        self::assertSame(0, InvoiceTotals::totalTtc($f));
    }

    public function testImportedInvoiceWithoutLinesUsesStoredTotal(): void
    {
        $f = (new Facture())->setMontantHtCents(10000)->setMontantTvaCents(2000)->setMontantTtcCents(12000);
        self::assertSame(12000, InvoiceTotals::totalTtc($f));
        self::assertSame(12000, InvoiceTotals::calculate($f)['ttcCents']);
        $f->addPaiement((new Paiement())->setMontantCents(5000));
        self::assertSame(7000, WorkflowBilling::outstanding($f));
    }

    public function testIssuanceFreezesAmountsSharedByPaymentVatAndPdf(): void
    {
        $invoice = (new Facture())->setNumero('FAC-NEW')->setRemiseGlobaleMontantCents(100)
            ->addLigne($this->line(1000))->addLigne($this->line(1000, 5.5))->addLigne($this->line(1000, 0, true));
        InvoiceTotals::finalizeNewInvoice($invoice);
        self::assertSame(2900, $invoice->getMontantHtCents());
        self::assertSame(246, $invoice->getMontantTvaCents());
        self::assertSame(3146, $invoice->getMontantTtcCents());
        self::assertSame(3146, $invoice->getTtcTotalCents());
        self::assertSame(1933, $invoice->getMontantHtHorsDeboursCents());
        self::assertSame(246, $invoice->getMontantTvaHorsDeboursCents());
        self::assertSame(967, $invoice->getMontantDeboursTtcCents());
        self::assertSame(3146, $invoice->getMontantTtcHorsDeboursCents() + $invoice->getMontantDeboursTtcCents());
        self::assertSame(100, array_sum(array_column($invoice->getIssuedTotals()['lines'], 'globalDiscountCents')));
        self::assertSame(246, array_sum(array_column($invoice->getIssuedTotals()['rates'], 'tvaCents')));
        $invoice->addPaiement((new Paiement())->setMontantCents(3146));
        self::assertSame(0, WorkflowBilling::outstanding($invoice));
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates'));
        $twig->addExtension(new InvoiceTotalsExtension());
        $twig->addFunction(new TwigFunction('asset', static fn(string $s) => $s));
        $twig->addFunction(new TwigFunction('absolute_url', static fn(string $s) => $s));
        $html = $twig->render('pdf/facture.html.twig', ['facture' => $invoice, 'entite' => (new Entite())->setNom('Organisme test')]);
        foreach (['Total HT (net)' => '29,00', 'TVA 20,00%' => '1,93', 'TVA 5,50%' => '0,53', 'Total TTC' => '31,46'] as $label => $amount) {
            self::assertMatchesRegularExpression('~' . preg_quote($label, '~') . '</td>\s*<td[^>]*>' . preg_quote($amount, '~') . ' €</td>~', $html);
        }
        // A persisted document must never be retroactively recalculated.
        (new \ReflectionProperty($invoice, 'id'))->setValue($invoice, 10);
        $this->expectException(\LogicException::class);
        InvoiceTotals::finalizeNewInvoice($invoice);
    }

    public function testLegacyAmountsAndImportedTotalsAreNotChangedOnRead(): void
    {
        $legacy = (new Facture())->setMontantHtCents(10000)->setMontantTvaCents(2000)->setMontantTtcCents(12000)
            ->setRemiseGlobalePourcent(10)->addLigne($this->line(10000));
        self::assertNull($legacy->getIssuedTotals());
        self::assertSame(12000, $legacy->getMontantTtcCents());
        self::assertSame(11000, $legacy->getTtcTotalCents(), 'Legacy values require reconciliation, never a silent retroactive change.');
        self::assertNull($legacy->getMeta());
        $import = (new Facture())->setMontantHtCents(42)->setMontantTvaCents(8)->setMontantTtcCents(50);
        self::assertSame(50, InvoiceTotals::totalTtc($import));
        self::assertNull($import->getMeta());
    }

    public function testPdfPreviewAndDeliveryUseSameCentRoundingForAutomatedInvoice(): void
    {
        $f = (new Facture())->setNumero('FAC-TEST')->addLigne($this->line(3))
            ->setMeta(['workflow_billing' => ['version' => 1, 'createdTotals' => ['ttcCents' => 999]]]);
        $totals = InvoiceTotals::calculate($f);
        self::assertSame(1, $totals['tvaCents']);
        self::assertSame(4, $totals['ttcCents']);
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates'));
        $twig->addExtension(new InvoiceTotalsExtension());
        $twig->addFunction(new TwigFunction('asset', static fn(string $s) => $s));
        $twig->addFunction(new TwigFunction('absolute_url', static fn(string $s) => $s));
        $context = ['facture' => $f, 'entite' => (new Entite())->setNom('Organisme test')];
        $preview = $twig->render('pdf/facture.html.twig', $context);
        $delivery = $twig->render('pdf/facture.html.twig', $context + ['invoiceTotals' => $totals]);
        self::assertSame(trim($preview), trim($delivery));
        self::assertMatchesRegularExpression('~Total TTC</td>\s*<td[^>]*>0,04 €</td>~', $preview);
        self::assertMatchesRegularExpression('~Montant TVA</td>\s*<td[^>]*>0,01 €</td>~', $preview);
        self::assertStringNotContainsString('9,99 €', $preview);
    }
}
