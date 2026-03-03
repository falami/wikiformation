<?php
// src/Twig/MoneyExtension.php
declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class MoneyExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('cents_to_euros', [$this, 'centsToEuros']),
        ];
    }

    public function centsToEuros(?int $cents, string $currency = '€'): string
    {
        if ($cents === null) return '—';
        $amount = $cents / 100;
        return number_format($amount, 2, ',', ' ') . ' ' . $currency;
    }
}
