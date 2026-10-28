<?php
declare(strict_types=1);
namespace App\Service\Marketplace;

use App\Exception\MarketplaceInvoiceUnavailable;

/** No seller/platform issuance until both legal issuers have a verified delivery pipeline. */
class MarketplaceInvoiceReadiness
{
    public const MESSAGE = 'Les achats de cours sont temporairement suspendus pendant la mise en service de la facturation électronique. Vos cours déjà acquis restent accessibles.';

    public function assertAvailable(): void
    {
        // A seller's user or a course's tenant does not establish the legal invoice issuer.
        // A marketplace checkbox or an active Stripe account cannot enable this capability.
        throw new MarketplaceInvoiceUnavailable(self::MESSAGE);
    }

    public function status(): array
    {
        try {
            $this->assertAvailable();
            return ['available' => true, 'message' => null];
        } catch (MarketplaceInvoiceUnavailable $error) {
            return ['available' => false, 'message' => $error->getMessage()];
        }
    }
}
