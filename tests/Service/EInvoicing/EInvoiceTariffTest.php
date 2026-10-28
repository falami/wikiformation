<?php
declare(strict_types=1);

namespace App\Tests\Service\EInvoicing;

use App\Entity\Entite;
use App\Service\EInvoicing\EInvoiceTariff;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EInvoiceTariffTest extends TestCase
{
    #[DataProvider('organisations')]
    public function testAllocationRequiresBothOwnerIdAndLegalIdentity(?int $id, ?string $siret, int $price, int $share): void
    {
        $entite = (new Entite())->setNom('I.T. For You')->setSiret($siret);
        (new \ReflectionProperty($entite, 'id'))->setValue($entite, $id);
        self::assertSame($price, EInvoiceTariff::unitPriceCents($entite));
        self::assertSame(['providerCostCents' => 1, 'platformShareCents' => $share, 'paymentProcessingReserveCents' => $share, 'version' => 2], EInvoiceTariff::allocation($entite));
        $allocation = EInvoiceTariff::allocation($entite);
        self::assertSame($price, $allocation['providerCostCents'] + $allocation['platformShareCents'] + $allocation['paymentProcessingReserveCents']);
    }

    public static function organisations(): iterable
    {
        yield 'owner exact identity' => [1, '80813612100023', 1, 0];
        yield 'owner formatted SIRET' => [1, '808 136 121 00023', 1, 0];
        yield 'other tenant same name and SIRET' => [2, '80813612100023', 3, 1];
        yield 'owner id wrong legal identity' => [1, '12345678900012', 3, 1];
        yield 'owner id no identity' => [1, null, 3, 1];
        yield 'owner id another branch' => [1, '80813612100031', 3, 1];
        yield 'unpersisted organisation' => [null, '80813612100023', 3, 1];
        yield 'ordinary organisation' => [42, '12345678900012', 3, 1];
    }
}
