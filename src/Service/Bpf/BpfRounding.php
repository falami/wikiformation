<?php

declare(strict_types=1);
namespace App\Service\Bpf;

/** Allocate whole hours without changing the rounded total of an activity. */
final class BpfRounding
{
    public static function distribution(array $hours, ?int $target = null): array
    {
        $rounded = array_map(static fn($value) => (int) floor($value), $hours);
        $target ??= (int) round(array_sum($hours));
        $remaining = $target - array_sum($rounded);
        $fractions = [];
        foreach ($hours as $key => $value) $fractions[$key] = $value - $rounded[$key];
        arsort($fractions, SORT_NUMERIC);
        foreach ($fractions as $key => $fraction) {
            if ($remaining <= 0) break;
            ++$rounded[$key]; --$remaining;
        }
        return $rounded;
    }
}
