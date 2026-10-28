<?php
declare(strict_types=1);
namespace App\Service\EInvoicing;

use App\Entity\Entite;

/** Server-owned commercial policy; never accept a price from a submitted form. */
final class EInvoiceTariff
{
    public const STANDARD_UNIT_PRICE_CENTS = 3;
    public const INTERNAL_UNIT_PRICE_CENTS = 1;

    public static function unitPriceCents(Entite $entite): int
    {
        return self::isInternal($entite) ? self::INTERNAL_UNIT_PRICE_CENTS : self::STANDARD_UNIT_PRICE_CENTS;
    }

    /** Commercial allocation frozen before transmission; neither a settlement nor an actual provider bill. */
    public static function allocation(Entite $entite): array
    {
        $internal = self::isInternal($entite);
        return [
            'providerCostCents' => 1,
            'platformShareCents' => $internal ? 0 : 1,
            // A provision included in the service price, not a payment-method surcharge or actual Stripe fee.
            'paymentProcessingReserveCents' => $internal ? 0 : 1,
            'version' => 2,
        ];
    }

    public static function isInternal(Entite $entite): bool
    {
        // The owner's provider-cost tariff still requires Stripe billing and consent.
        // Scope it to both the owner tenant and its legal identity.
        return $entite->getId() === 1
            && preg_replace('/\s+/', '', $entite->getSiret() ?? '') === '80813612100023';
    }
}
