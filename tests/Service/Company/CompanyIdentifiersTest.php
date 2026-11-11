<?php
declare(strict_types=1);
namespace App\Tests\Service\Company;
use App\Service\Company\CompanyIdentifiers as Id;
use PHPUnit\Framework\TestCase;
final class CompanyIdentifiersTest extends TestCase
{
    public function testValidNormalizedIdentity(): void
    {
        self::assertSame([], Id::errors('732 829 320 00074', 'fr 44 732829320'));
        self::assertSame([], Id::errors(null, null));
        self::assertSame([], Id::errors('35600000026404', null));
    }
    public function testInvalidAndConfusedIdentifiers(): void
    {
        foreach (['00000000000000','73282932000075','732829320','abcdefghijklmn'] as $siret) self::assertArrayHasKey('siret', Id::errors($siret, null));
        foreach (['73282932000074','FR00732829320','ZZ44732829320','FR44000000000'] as $vat) self::assertArrayHasKey('numeroTVA', Id::errors(null, $vat));
        self::assertArrayHasKey('numeroTVA', Id::errors('35600000000048', 'FR44732829320'));
    }
}
