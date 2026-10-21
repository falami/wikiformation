<?php
declare(strict_types=1);
namespace App\Service\EInvoicing;

use App\Entity\Entite;

/** Server-owned commercial policy; never accept a price from a submitted form. */
final class EInvoiceTariff
{
    public static function unitPriceCents(Entite $entite): int
    {
        return self::isInternal($entite) ? 0 : 2;
    }

    public static function isInternal(Entite $entite): bool
    {
        // Scope the exemption to both the owner tenant and its legal identity.
        return $entite->getId() === 1
            && preg_replace('/\s+/', '', $entite->getSiret() ?? '') === '80813612100023';
    }
}
