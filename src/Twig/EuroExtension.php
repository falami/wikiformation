<?php

namespace App\Twig;

use Twig\TwigFilter;
use Twig\Extension\AbstractExtension;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Twig\Attribute\AsTwigExtension;

#[AutoconfigureTag('twig.extension')]
class EuroExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            // Format un montant en centimes (int) vers "1 299,90 €"
            new TwigFilter('euros', [$this, 'formatEuros']),
            // Variante sans symbole monétaire
            new TwigFilter('euros_raw', [$this, 'formatEurosRaw']),
        ];
    }

    /**
     * @param int|null $cents Montant en centimes
     * @param string   $currency Symbole ou code (ex "€" ou "EUR")
     * @param bool     $nbsp Coller le symbole avec une espace insécable
     */
    public function formatEuros(?int $cents, string $currency = '€', bool $nbsp = true): string
    {
        if ($cents === null) {
            return '—';
        }
        $value = $cents / 100;
        $str = number_format($value, 2, ',', ' ');
        return $nbsp ? ($str . "\xc2\xa0" . $currency) : ($str . ' ' . $currency);
    }

    public function formatEurosRaw(?int $cents): string
    {
        if ($cents === null) {
            return '';
        }
        return number_format($cents / 100, 2, ',', ' ');
    }
}
