<?php

namespace App\Tests\Unit;

use App\Service\Depense\ReceiptParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReceiptParserTest extends TestCase
{
    private function parse(string $text): array
    {
        return (new ReceiptParser())->parse(['text' => $text]);
    }

    public function testHtAndPercentageAreNotConfusedWithTotalOrTaxAmount(): void
    {
        $r = $this->parse("Commerce Exemple\nDate 26/10/2026\nTOTAL HT 100,00\nTVA 20,00% 20,00\nTOTAL TTC 120,00\nEspèces 200,00\nRendu 80,00");
        self::assertSame(12000, $r['montantTtcCents']);
        self::assertSame(10000, $r['montantHtCents']);
        self::assertSame(2000, $r['montantTvaCents']);
        self::assertSame(20.0, $r['tauxTva']);
        self::assertSame('2026-10-26', $r['date']);
        self::assertSame([], $r['warnings']);
    }

    public function testDecimalPercentageDoesNotBecomeTaxAmount(): void
    {
        $r = $this->parse("Total TTC 42,20\nTVA 5,50% 2,20");
        self::assertSame(220, $r['montantTvaCents']);
        self::assertSame(4000, $r['montantHtCents']);
        self::assertSame(5.5, $r['tauxTva']);
    }

    public function testUnknownTaxRemainsUnknown(): void
    {
        $r = $this->parse('TOTAL TTC 50,00');
        self::assertSame(5000, $r['montantTtcCents']);
        self::assertNull($r['montantHtCents']);
        self::assertNull($r['montantTvaCents']);
        self::assertNull($r['tauxTva']);
        self::assertNotEmpty($r['warnings']);
    }

    public function testMultipleRatesAreNotCollapsedToOneRate(): void
    {
        $r = $this->parse("TOTAL TTC 23,00\nTVA 20% 2,00\nTVA 10% 1,00");
        self::assertTrue($r['multipleVatRates']);
        self::assertSame(300, $r['montantTvaCents']);
        self::assertSame(2000, $r['montantHtCents']);
        self::assertNull($r['tauxTva']);
        self::assertNotEmpty($r['warnings']);
    }

    public function testExplicitTaxTotalIsNotAddedToDetailedRows(): void
    {
        $r = $this->parse("TVA 20% 2,00\nTVA 10% 1,00\nTOTAL TVA 3,00\nTOTAL TTC 23,00");
        self::assertSame(300, $r['montantTvaCents']);
    }

    public function testInconsistentTaxAmountsAreNotProposed(): void
    {
        $r = $this->parse("TOTAL TTC 120,00\nTOTAL HT 100,00\nTVA 20% 80,00");
        self::assertSame(12000, $r['montantTtcCents']);
        self::assertNull($r['montantHtCents']);
        self::assertNull($r['montantTvaCents']);
        self::assertNull($r['tauxTva']);
        self::assertNotEmpty($r['warnings']);
    }

    public function testAmountIsNotGuessedFromLargestNumber(): void
    {
        $r = $this->parse("Date 26.10.2026\nArticle 12,00\nEspèces 50,00\nSous-total 12,00\nTotal HT 10,00");
        self::assertNull($r['montantTtcCents']);
    }

    public function testConflictingTotalsDoNotSilentlyUseTheLastReceipt(): void
    {
        $r = $this->parse("TOTAL TTC 120,00\nAutre facture\nTOTAL TTC 240,00");
        self::assertNull($r['montantTtcCents']);
        self::assertNotEmpty($r['warnings']);
        self::assertSame(12000, $this->parse("TOTAL TTC 120,00\nTOTAL TTC 120,00")['montantTtcCents']);
    }

    #[DataProvider('amountFormats')]
    public function testMoneyFormats(string $raw, int $expected): void
    {
        self::assertSame($expected, $this->parse('Net à payer '.$raw)['montantTtcCents']);
    }

    public static function amountFormats(): iterable
    {
        yield ['1 234,56', 123456];
        yield ["1\u{202F}234,56", 123456];
        yield ['1.234,56', 123456];
        yield ['1,234.56', 123456];
        yield ['1234.56', 123456];
        yield ['120 €', 12000];
    }

    public function testAmountOnNextLineAndZeroVatAreSupported(): void
    {
        $r = $this->parse("TOTAL TTC\n12,00\nTVA 0% 0,00");
        self::assertSame(1200, $r['montantTtcCents']);
        self::assertSame(1200, $r['montantHtCents']);
        self::assertSame(0, $r['montantTvaCents']);
        self::assertSame(0.0, $r['tauxTva']);
    }

    public function testImpossibleDateIsNotAcceptedAndDueDateIsSkipped(): void
    {
        $r = $this->parse("Échéance 30/11/2026\nDate 31/02/2026\nAchat 26-10-2026\nTOTAL TTC 12,00");
        self::assertSame('2026-10-26', $r['date']);
        self::assertNull($this->parse('Date 29/02/2025')['date']);
        self::assertSame('2024-02-29', $this->parse('Date 2024-02-29')['date']);
    }

    public function testNegativeTotalIsNotTurnedIntoPositiveExpense(): void
    {
        $r = $this->parse('TOTAL TTC -120,00');
        self::assertNull($r['montantTtcCents']);
        self::assertNotEmpty($r['warnings']);
    }

    public function testMalformedResponseDoesNotCrashOrProduceTaxDefaults(): void
    {
        $r = (new ReceiptParser())->parse(['text' => ['wrong'], 'lines' => [null, [], 123]]);
        self::assertNull($r['montantTtcCents']);
        self::assertNull($r['tauxTva']);
        self::assertSame(0, $r['confidence']);
    }
}
