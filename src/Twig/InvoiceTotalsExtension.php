<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Billing\InvoiceTotals;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class InvoiceTotalsExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [new TwigFunction('invoice_totals', [InvoiceTotals::class, 'calculate'])];
    }
}
