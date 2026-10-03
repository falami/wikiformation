<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\Entity\{Devis, Facture};

/** Monetary calculations for automated invoices, expressed in integer cents. */
final class InvoiceTotals
{
    public static function totalTtc(Facture $invoice): int
    {
        // Imported invoices can have a stored total without any itemized lines.
        return $invoice->getLignes()->isEmpty()
            ? max(0, $invoice->getMontantTtcCents())
            : self::calculate($invoice)['ttcCents'];
    }

    /** @return array{brutHtCents:int,lineDiscountCents:int,netBeforeGlobalCents:int,globalDiscountCents:int,htCents:int,tvaCents:int,ttcCents:int,deboursTtcCents:int,rates:array<string,array{htCents:int,tvaCents:int}>} */
    public static function calculate(Facture|Devis $invoice): array
    {
        $totals = ['brutHtCents' => 0, 'lineDiscountCents' => 0, 'netBeforeGlobalCents' => 0,
            'globalDiscountCents' => 0, 'htCents' => 0, 'tvaCents' => 0, 'ttcCents' => 0,
            'deboursTtcCents' => 0, 'rates' => []];
        if ($invoice->getLignes()->isEmpty()) {
            $totals['brutHtCents'] = $totals['netBeforeGlobalCents'] = $totals['htCents'] = max(0, $invoice->getMontantHtCents());
            $totals['tvaCents'] = max(0, $invoice->getMontantTvaCents());
            $totals['ttcCents'] = max(0, $invoice->getMontantTtcCents());
            return $totals;
        }

        $lines = array_values($invoice->getLignes()->toArray());
        $bases = [];
        foreach ($lines as $index => $line) {
            $bases[$index] = max(0, $line->getTotalHtNetCents());
            $totals['brutHtCents'] += max(0, $line->getTotalHtBrutCents());
            $totals['lineDiscountCents'] += max(0, $line->getRemiseCents());
        }
        $base = $totals['netBeforeGlobalCents'] = array_sum($bases);
        $fixed = $invoice->getRemiseGlobaleMontantCents() ?? 0;
        $discount = $fixed > 0 ? $fixed : (int) round($base * max(0, $invoice->getRemiseGlobalePourcent() ?? 0) / 100);
        $discount = $totals['globalDiscountCents'] = min($base, max(0, $discount));

        // Allocate every discount cent. A remainder must not disappear when rates differ.
        $shares = $remainders = [];
        foreach ($bases as $index => $lineBase) {
            $shares[$index] = $base > 0 ? intdiv($discount * $lineBase, $base) : 0;
            $remainders[$index] = $base > 0 ? ($discount * $lineBase) % $base : 0;
        }
        arsort($remainders, SORT_NUMERIC);
        $remaining = $discount - array_sum($shares);
        foreach ($remainders as $index => $remainder) {
            if ($remaining === 0) break;
            if ($shares[$index] < $bases[$index]) { ++$shares[$index]; --$remaining; }
        }

        foreach ($lines as $index => $line) {
            $ht = $bases[$index] - $shares[$index];
            $rateBp = max(0, (int) round($line->getTva() * 100));
            $vat = (int) round($ht * $rateBp / 10000);
            $key = number_format($rateBp / 100, 2, '.', '');
            $rate = $totals['rates'][$key] ?? ['htCents' => 0, 'tvaCents' => 0];
            $totals['rates'][$key] = ['htCents' => $rate['htCents'] + $ht, 'tvaCents' => $rate['tvaCents'] + $vat];
            $totals['htCents'] += $ht;
            $totals['tvaCents'] += $vat;
            // Reimbursements are already itemized; never add them again to the total.
            if ($line->isDebours()) $totals['deboursTtcCents'] += $ht + $vat;
        }
        $totals['ttcCents'] = $totals['htCents'] + $totals['tvaCents'];
        return $totals;
    }
}
