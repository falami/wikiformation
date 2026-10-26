<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\Depense\ExpenseImportInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExpenseImportInputTest extends TestCase
{
    private function row(array $changes = []): array
    {
        return array_replace([
            'date' => '2026-10-26', 'libelle' => '  Facture fournisseur  ', 'devise' => 'eur',
            'montantHtCents' => '10000', 'montantTvaCents' => '2000', 'montantTtcCents' => '12000',
            'tauxTva' => '20', 'tvaDeductiblePct' => '0', 'accept' => '1',
            'tvaDeductible' => '1', 'allowDuplicate' => '0',
            'categorieId' => '12', 'fournisseurId' => '', 'payeurId' => '34',
        ], $changes);
    }

    public function testNormalizesValidEditedRowWithoutReplacingZeroDeductibility(): void
    {
        $row = (new ExpenseImportInput())->validate($this->row(['tauxTva' => '5,5']));
        self::assertSame('Facture fournisseur', $row['libelle']);
        self::assertSame('EUR', $row['devise']);
        self::assertSame('2026-10-26 00:00:00', $row['dateObject']->format('Y-m-d H:i:s'));
        self::assertSame(12000, $row['montantTtcCents']);
        self::assertSame(5.5, $row['tauxTva']);
        self::assertSame(0.0, $row['tvaDeductiblePct']);
        self::assertTrue($row['tvaDeductible']);
        self::assertTrue($row['accept']);
        self::assertFalse($row['allowDuplicate']);
        self::assertSame(12, $row['categorieId']);
        self::assertSame(34, $row['payeurId']);
        self::assertNull($row['fournisseurId']);
    }

    #[DataProvider('invalidDates')]
    public function testInvalidDatesDoNotRollOverOrThrow(string $date): void
    {
        self::assertNull(ExpenseImportInput::date($date));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Date invalide');
        (new ExpenseImportInput())->validate($this->row(['date' => $date]));
    }

    public static function invalidDates(): iterable
    {
        foreach (['', '2026-02-29', '2026-02-31', '2026-13-01', '26/10/2026', '2026-1-1', 'tomorrow', "2026-10-26\0", '2026-10-26T12:00:00'] as $date) {
            yield [$date];
        }
    }

    public function testLeapDayIsValid(): void
    {
        self::assertSame('2024-02-29', ExpenseImportInput::date('2024-02-29')?->format('Y-m-d'));
    }

    #[DataProvider('invalidFields')]
    public function testMalformedDataCannotBecomeAnExpense(string $field, mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ExpenseImportInput())->validate($this->row([$field => $value]));
    }

    public static function invalidFields(): iterable
    {
        foreach (['montantHtCents', 'montantTvaCents', 'montantTtcCents'] as $field) {
            foreach (['abc', '-1', '12.5', '1e4', '2 000', '2000000001', '', ['1200']] as $value) {
                yield [$field, $value];
            }
        }
        yield ['montantTtcCents', '0'];
        foreach (['tauxTva', 'tvaDeductiblePct'] as $field) {
            foreach (['-1', '100.1', 'NaN', 'INF', '1e2'] as $value) yield [$field, $value];
        }
        foreach (['accept', 'tvaDeductible', 'allowDuplicate'] as $field) {
            foreach (['true', 'false', 'yes', '2'] as $value) yield [$field, $value];
        }
        foreach (['categorieId', 'fournisseurId', 'payeurId'] as $field) {
            foreach (['-1', '1e3', '2.5', '../12', ['12']] as $value) yield [$field, $value];
        }
        yield ['devise', 'ZZZ'];
        yield ['devise', ''];
        yield ['libelle', '  '];
        yield ['libelle', str_repeat('a', 2001)];
    }
}
