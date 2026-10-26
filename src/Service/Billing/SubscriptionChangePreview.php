<?php
namespace App\Service\Billing;

/** Separates renewal charges from adjustments in Stripe's upcoming invoice. */
final class SubscriptionChangePreview
{
    public static function summarize(\Stripe\Invoice $invoice): array
    {
        $result = ['proration_cents' => 0, 'proration_tax_cents' => 0,
            'next_period_cents' => 0, 'next_tax_cents' => 0,
            'amount_due_cents' => (int) ($invoice->amount_due ?? 0), 'lines' => []];
        foreach ($invoice->lines->data ?? [] as $line) {
            $proration = (bool) ($line->parent->subscription_item_details->proration
                ?? $line->parent->invoice_item_details->proration ?? $line->proration ?? false);
            $amount = (int) ($line->amount ?? 0);
            $tax = 0;
            foreach ($line->taxes ?? $line->tax_amounts ?? [] as $entry) {
                $tax += (int) ($entry->amount ?? 0);
            }
            $result[$proration ? 'proration_cents' : 'next_period_cents'] += $amount;
            $result[$proration ? 'proration_tax_cents' : 'next_tax_cents'] += $tax;
            $result['lines'][] = ['desc' => (string) ($line->description ?? ''),
                'proration' => $proration, 'amount_cents' => $amount, 'tax_cents' => $tax];
        }
        return $result;
    }
}
