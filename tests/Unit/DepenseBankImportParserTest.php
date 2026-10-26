<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\Depense\DepenseBankImportParser;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DepenseBankImportParserTest extends TestCase
{
    private function csv(string $contents): array
    {
        $file = tempnam(sys_get_temp_dir(), 'bank-parser-');
        try {
            file_put_contents($file, $contents);
            return (new DepenseBankImportParser())->parse($file, 'operations.CSV');
        } finally {
            unlink($file);
        }
    }

    public function testCreditAgricolePreambleAndWindowsEncoding(): void
    {
        $csv = "Crédit Agricole\nCompte courant\n\nDate opération;Libellé;Débit euros;Crédit euros;Date valeur\n26/10/2026;Péage;1.234,56;;27/10/2026\n27/10/2026;Virement reçu;;500,00;28/10/2026\n";
        $rows = $this->csv(mb_convert_encoding($csv, 'Windows-1252', 'UTF-8'));
        self::assertCount(1, $rows);
        self::assertSame('2026-10-26', $rows[0]['date']->format('Y-m-d'));
        self::assertSame('Péage', $rows[0]['libelle']);
        self::assertSame(123456, $rows[0]['debitCents']);
    }

    #[DataProvider('moneyFormats')]
    public function testMoneyFormatsAreConvertedWithoutLoss(string $money, int $expected): void
    {
        $rows = $this->csv("\xEF\xBB\xBFDate;Libellé;Débit;Crédit\n26/10/2026;Achat;{$money};\n");
        self::assertSame($expected, $rows[0]['debitCents']);
    }

    public static function moneyFormats(): iterable
    {
        yield ['1 234,56', 123456];
        yield ["1\u{00a0}234,56 €", 123456];
        yield ["1\u{202f}234,56", 123456];
        yield ['1.234,56', 123456];
        yield ['1,234.56', 123456];
        yield ['12,3', 1230];
        yield ['12', 1200];
        yield ['-12,34', 1234];
        yield ['(12,34)', 1234];
        yield ['EUR 12,34', 1234];
        yield ['1.234.567,89', 123456789];
        yield ['1,234,567.89', 123456789];
        yield ['0,01', 1];
    }

    #[DataProvider('delimitedContents')]
    public function testCommaAndTabExportsWithQuotedLabels(string $contents): void
    {
        $rows = $this->csv($contents);
        self::assertSame(123456, $rows[0]['debitCents']);
        self::assertSame("Achat, magasin\nseconde ligne", $rows[0]['libelle']);
    }

    public static function delimitedContents(): iterable
    {
        yield ["Date,Description,Debit,Credit\n2026-10-26,\"Achat, magasin\nseconde ligne\",\"1,234.56\",\n"];
        yield ["Date\tDescription\tDebit\tCredit\n2026-10-26\t\"Achat, magasin\nseconde ligne\"\t1.234,56\t\n"];
    }

    public function testUtf16CsvAndTwoDigitYear(): void
    {
        $rows = $this->csv("\xFF\xFE".mb_convert_encoding("Date;Libellé;Débit\n26/10/26;Électricité;55,50\n", 'UTF-16LE', 'UTF-8'));
        self::assertSame('2026-10-26', $rows[0]['date']->format('Y-m-d'));
        self::assertSame('Électricité', $rows[0]['libelle']);
    }

    public function testSignedAmountOnlyImportsDebits(): void
    {
        $rows = $this->csv("Date;Libellé;Montant;Devise\n26/10/2026;Dépense;-30,00;EUR\n26/10/2026;Remboursement;40;EUR\n\n26/10/2026;Zéro;0;EUR\n");
        self::assertCount(1, $rows);
        self::assertSame(3000, $rows[0]['debitCents']);
    }

    #[DataProvider('invalidRows')]
    public function testInvalidDataIsReportedInsteadOfSilentlyChangingIt(string $row, string $message): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Ligne 2 : '.$message);
        $this->csv("Date;Libellé;Débit;Crédit;Devise\n{$row}\n");
    }

    public static function invalidRows(): iterable
    {
        yield ['31/02/2026;Achat;12;;EUR', 'date invalide'];
        yield ['today;Achat;12;;EUR', 'date invalide'];
        yield ['45199;Achat;12;;EUR', 'date invalide'];
        yield [';Achat;12;;EUR', 'date invalide'];
        yield ['26/10/2026;;12;;EUR', 'le libellé'];
        yield ['26/10/2026;Achat;invalide;;EUR', 'montant invalide'];
        yield ['26/10/2026;Achat;1.23.45;;EUR', 'séparateurs'];
        yield ['26/10/2026;Achat;1.234,567;;EUR', 'montant invalide'];
        yield ['26/10/2026;Achat;999999999999;;EUR', 'montant trop élevé'];
        yield ['26/10/2026;Achat;12;3;EUR', 'un débit et un crédit'];
        yield ['26/10/2026;Achat;12;;USD', 'seules les opérations en EUR'];
        yield ['26/10/2026;Achat;12 $;;EUR', 'montant invalide'];
    }

    public function testTruncatedCsvRowIsNotSilentlyDiscarded(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Ligne 2 : le nombre de colonnes');
        $this->csv("Date;Libellé;Débit\n26/10/2026;Achat\n");
    }

    public function testInvalidWorkbookProducesReadableError(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'bank-invalid-');
        try {
            file_put_contents($file, 'not a workbook');
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('classeur est illisible');
            (new DepenseBankImportParser())->parse($file, 'operations.xlsx');
        } finally {
            unlink($file);
        }
    }

    public function testMissingColumnsAreNotAnEmptySuccessfulImport(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Colonnes non reconnues');
        $this->csv("Date;Libellé;Solde\n26/10/2026;Solde;900\n");
    }

    public function testRowLimitRejectsWholeFileInsteadOfTruncatingIt(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('10 000 opérations');
        $this->csv("Date;Libellé;Débit\n".str_repeat("26/10/2026;Achat;12\n", 10001));
    }

    public function testSpreadsheetSerialDatesAndNumericAmounts(): void
    {
        $rows = $this->workbook(false);
        self::assertCount(1, $rows);
        self::assertSame('2026-10-26', $rows[0]['date']->format('Y-m-d'));
        self::assertSame(123456, $rows[0]['debitCents']);
    }

    public function testSpreadsheetFormulaIsNeverCalculatedForFinancialData(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('montant invalide');
        $this->workbook(true);
    }

    private function workbook(bool $formula): array
    {
        $file = tempnam(sys_get_temp_dir(), 'bank-workbook-');
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([['Export du compte'], ['Date', 'Libellé', 'Débit'], [Date::PHPToExcel(new \DateTimeImmutable('2026-10-26')), 'Achat', 1234.56]]);
        if ($formula) {
            $sheet->setCellValueExplicit('C3', '=1+1', DataType::TYPE_FORMULA);
        }
        try {
            (new Xlsx($spreadsheet))->save($file);
            return (new DepenseBankImportParser())->parse($file, 'operations.xlsx');
        } finally {
            $spreadsheet->disconnectWorksheets();
            unlink($file);
        }
    }
}
