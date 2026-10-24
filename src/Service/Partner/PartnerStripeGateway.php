<?php

declare (strict_types=1);
namespace App\Service\Partner;

use App\Service\Stripe\StripeClientFactory;
/** All money movement uses the platform billing account, never a tenant's Connect account. */
class PartnerStripeGateway
{
    public function __construct(private StripeClientFactory $factory)
    {
    }
    public function invoice(string $id): array
    {
        $client = $this->factory->client();
        $invoice = $client->invoices->retrieve($id, [])->toArray();
        $lines = [];
        foreach ($client->invoices->allLines($id, ['limit' => 100])->autoPagingIterator() as $line) {
            $data = $line->toArray();
            $priceId = $data['price']['id'] ?? $data['pricing']['price_details']['price'] ?? null;
            if ($priceId) {
                $data['_price'] = $client->prices->retrieve($priceId, [])->toArray();
            }
            $lines[] = $data;
        }
        $invoice['_lines'] = $lines;
        $charges = [];
        if (!empty($invoice['charge'])) {
            $charges[] = is_array($invoice['charge']) ? $invoice['charge']['id'] : $invoice['charge'];
        } elseif (!empty($invoice['payment_intent'])) {
            $pi = $client->paymentIntents->retrieve(is_array($invoice['payment_intent']) ? $invoice['payment_intent']['id'] : $invoice['payment_intent'], []);
            if ($pi->latest_charge) {
                $charges[] = (string) $pi->latest_charge;
            }
        } else {
            foreach ($client->invoicePayments->all(['invoice' => $id, 'limit' => 100])->autoPagingIterator() as $payment) {
                if ($payment->status !== 'paid') {
                    continue;
                }
                if ($payment->payment->type === 'payment_intent') {
                    $pi = $client->paymentIntents->retrieve((string) $payment->payment->payment_intent, []);
                    if ($pi->latest_charge) {
                        $charges[] = (string) $pi->latest_charge;
                    }
                }
            }
        }
        $invoice['_charges'] = array_values(array_unique($charges));
        $invoice['_hasCreditNotes'] = (bool) ($invoice['pre_payment_credit_notes_amount'] ?? 0) || (bool) ($invoice['post_payment_credit_notes_amount'] ?? 0);
        return $invoice;
    }
    public function charge(string $id): array
    {
        $charge = $this->factory->client()->charges->retrieve($id, [])->toArray();
        if (!empty($charge['disputed']) && empty($charge['dispute'])) {
            foreach ($this->factory->client()->disputes->all(['charge' => $id, 'limit' => 1])->data as $dispute) {
                $charge['dispute'] = $dispute->id;
            }
        }
        return $charge;
    }
    public function dispute(string $id): array
    {
        return $this->factory->client()->disputes->retrieve($id, [])->toArray();
    }
    public function account(string $id): array
    {
        return $this->factory->client()->accounts->retrieve($id, [])->toArray();
    }
    public function createAccount(string $email, int $partnerId): string
    {
        foreach ($this->factory->client()->accounts->all(['limit' => 100])->autoPagingIterator() as $account) {
            if (($account->metadata->affiliate_partner ?? null) === (string) $partnerId) {
                return $account->id;
            }
        }
        return $this->factory->client()->accounts->create(['type' => 'express', 'country' => 'FR', 'email' => $email, 'capabilities' => ['transfers' => ['requested' => true]], 'settings' => ['payouts' => ['schedule' => ['interval' => 'manual']]], 'metadata' => ['affiliate_partner' => (string) $partnerId]], ['idempotency_key' => 'affiliate-account-' . $partnerId])->id;
    }
    public function onboarding(string $account, string $return, string $refresh): string
    {
        return $this->factory->client()->accountLinks->create(['account' => $account, 'type' => 'account_onboarding', 'return_url' => $return, 'refresh_url' => $refresh])->url;
    }
    public function dashboard(string $account): string
    {
        return $this->factory->client()->accounts->createLoginLink($account, [])->url;
    }
    public function findTransfer(string $key): ?array
    {
        foreach ($this->factory->client()->transfers->all(['transfer_group' => $key, 'limit' => 100])->autoPagingIterator() as $row) {
            return $row->toArray();
        }
        return null;
    }
    public function transfer(string $account, int $amount, string $charge, string $key): array
    {
        return $this->factory->client()->transfers->create(['destination' => $account, 'amount' => $amount, 'currency' => 'eur', 'source_transaction' => $charge, 'transfer_group' => $key, 'metadata' => ['affiliate_key' => $key]], ['idempotency_key' => $key])->toArray();
    }
    public function findPayout(string $account, string $key, int $since): ?array
    {
        foreach ($this->factory->client()->payouts->all(['created' => ['gte' => $since], 'limit' => 100], ['stripe_account' => $account])->autoPagingIterator() as $row) {
            if (($row->metadata->affiliate_key ?? null) === $key) {
                return $row->toArray();
            }
        }
        return null;
    }
    public function payout(string $account, int $amount, string $key): array
    {
        return $this->factory->client()->payouts->create(['amount' => $amount, 'currency' => 'eur', 'method' => 'standard', 'metadata' => ['affiliate_key' => $key]], ['stripe_account' => $account, 'idempotency_key' => $key])->toArray();
    }
    public function getPayout(string $account, string $id): array
    {
        return $this->factory->client()->payouts->retrieve($id, [], ['stripe_account' => $account])->toArray();
    }
    public function reverse(string $transfer, int $amount, string $key): void
    {
        // Reconcile by metadata before every retry, including after Stripe's idempotency retention.
        foreach ($this->factory->client()->transfers->allReversals($transfer, ['limit' => 100])->autoPagingIterator() as $row) {
            if (($row->metadata->affiliate_key ?? null) === $key) {
                return;
            }
        }
        $this->factory->client()->transfers->createReversal($transfer, ['amount' => $amount, 'metadata' => ['affiliate_key' => $key]], ['idempotency_key' => $key]);
    }
}
