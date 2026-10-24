<?php

declare (strict_types=1);
namespace App\Service\Partner;

final class CommissionMath
{
    public static function percentage(int $amount, int $bps): int
    {
        if ($amount < 0 || $bps < 0 || $bps > 10000) {
            throw new \InvalidArgumentException('Montant ou pourcentage invalide.');
        }
        return intdiv($amount * $bps + 5000, 10000);
    }
    /** Only positively identified base-plan prices, with exclusive VAT; discounts are line allocations. */
    public static function base(array $invoice, array $planPrices): int
    {
        if (($invoice['status'] ?? '') !== 'paid' || ($invoice['currency'] ?? '') !== 'eur') {
            throw new \DomainException('Facture non réglée en euros.');
        }
        if (!empty($invoice['_hasCreditNotes'])) {
            throw new \DomainException('Avoir Stripe : rapprochement de la base HT nécessaire.');
        }
        $base = 0;
        $found = false;
        foreach ($invoice['_lines'] as $line) {
            $price = $line['_price'] ?? [];
            if (!in_array($price['id'] ?? '', $planPrices, true)) {
                continue;
            }
            $found = true;
            if (($price['tax_behavior'] ?? '') !== 'exclusive') {
                throw new \DomainException('Tarif sans TVA exclusive : contrôle nécessaire.');
            }
            $base += (int) $line['amount'] - array_sum(array_column($line['discount_amounts'] ?? [], 'amount'));
        }
        if (!$found) {
            throw new \DomainException('Tarif d’abonnement non identifié : contrôle nécessaire.');
        }
        if ((int) ($invoice['amount_paid'] ?? 0) <= 0) {
            return 0;
        }
        // Credits and partial/multiple settlements must not create commission on uncollected money.
        if ((int) ($invoice['amount_paid'] ?? 0) !== (int) ($invoice['total'] ?? -1) || (int) ($invoice['starting_balance'] ?? 0) !== 0 || (int) ($invoice['ending_balance'] ?? 0) !== 0) {
            throw new \DomainException('Paiement partiel ou crédit client : rapprochement nécessaire.');
        }
        return max(0, $base);
    }
    public static function net(int $gross, int $fixed, int $bps): array
    {
        $fee = min($gross, max(0, $fixed) + self::percentage($gross, $bps));
        return ['fee' => $fee, 'net' => $gross - $fee];
    }
}
