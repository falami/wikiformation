<?php
declare(strict_types=1);
namespace App\Service\EInvoicing;

use App\Entity\Entite;

/** Server-owned commercial policy; never accept a price from a submitted form. */
final class EInvoiceTariff
{
    public static function unitPriceCents(Entite $entite): int
    {
        // The owner's preferential price is scoped to both the tenant and its legal identity.
        return $entite->getId() === 1
            && preg_replace('/\s+/', '', $entite->getSiret() ?? '') === '80813612100023' ? 1 : 2;
    }
}
