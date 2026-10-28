<?php
declare(strict_types=1);

namespace App\Twig;

use App\Service\Billing\SubscriptionTerms;
use App\Service\EInvoicing\EInvoiceTariff;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class SubscriptionTermsExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [new TwigFunction('subscription_terms', [SubscriptionTerms::class, 'snapshot']),
            new TwigFunction('einvoice_is_internal', [EInvoiceTariff::class, 'isInternal'])];
    }
}
