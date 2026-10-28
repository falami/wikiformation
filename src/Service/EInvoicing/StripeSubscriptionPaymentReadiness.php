<?php
declare(strict_types=1);

namespace App\Service\EInvoicing;

use App\Entity\Billing\EntiteSubscription;
use Stripe\{ApiRequestor, HttpClient\CurlClient, StripeClient};
use Symfony\Component\DependencyInjection\Attribute\{AsAlias, Autowire};

/** Checks current Stripe configuration; never charges, attaches, updates or caches a payment method. */
#[AsAlias(SubscriptionPaymentReadiness::class)]
final class StripeSubscriptionPaymentReadiness implements SubscriptionPaymentReadiness
{
    public function __construct(#[Autowire('%stripe.secret_key%')] private readonly string $secret) {}

    public function assertReady(EntiteSubscription $subscription): void
    {
        if (!preg_match('/^(?:sk|rk)_live_[A-Za-z0-9]+$/D', $this->secret)) {
            throw new \DomainException('Le paiement Stripe de production doit être configuré avant d’émettre des factures.');
        }
        $subscriptionId = $subscription->getStripeSubscriptionId() ?? '';
        $customerId = $subscription->getStripeCustomerId() ?? '';
        $entiteId = $subscription->getEntite()?->getId();
        if ($entiteId === null || $entiteId <= 0) {
            throw new \DomainException('L’abonnement Stripe doit être associé à un organisme enregistré avant d’émettre des factures.');
        }
        if (!preg_match('/^sub_[A-Za-z0-9]+$/D', $subscriptionId) || !preg_match('/^cus_[A-Za-z0-9]+$/D', $customerId)) {
            throw new \DomainException('Souscrivez un abonnement Stripe et configurez son moyen de paiement avant d’émettre des factures.');
        }
        $stripe = new StripeClient(['api_key' => $this->secret, 'max_network_retries' => 0]);
        $sub = $this->read(fn () => $stripe->subscriptions->retrieve($subscriptionId, []));
        if (($sub['id'] ?? '') !== $subscriptionId || ($sub['object'] ?? '') !== 'subscription'
            || ($sub['livemode'] ?? false) !== true || self::id($sub['customer'] ?? null) !== $customerId
            || ($sub['currency'] ?? '') !== 'eur' || !in_array($sub['status'] ?? '', ['active', 'trialing'], true)) {
            throw new \DomainException('Un abonnement Stripe de production actif, en euros et rattaché à cet organisme est requis.');
        }
        if (($sub['collection_method'] ?? '') !== 'charge_automatically' || ($sub['pause_collection'] ?? null) !== null) {
            throw new \DomainException('Activez le paiement automatique de l’abonnement Stripe et reprenez les prélèvements avant d’émettre des factures.');
        }
        $this->assertDates($sub);
        $this->assertTax($sub);

        $customer = $this->read(fn () => $stripe->customers->retrieve($customerId, []));
        if (($customer['id'] ?? '') !== $customerId || ($customer['object'] ?? '') !== 'customer'
            || ($customer['deleted'] ?? false) !== false || ($customer['livemode'] ?? false) !== true
            || (isset($customer['currency']) && $customer['currency'] !== 'eur')) {
            throw new \DomainException('Le client Stripe de production associé à cet organisme doit être vérifié.');
        }
        // Written server-side by StripeBillingService when creating this tenant's customer.
        // Legacy customers must be explicitly reconciled; neither a name nor an email proves ownership.
        if (($customer['metadata']['entite_id'] ?? null) !== (string) $entiteId) {
            throw new \DomainException('Le rattachement du client Stripe à votre organisme doit être confirmé par l’administrateur avant d’émettre des factures.');
        }
        if (($sub['automatic_tax']['enabled'] ?? false) === true
            && in_array($customer['tax']['automatic_tax'] ?? '', ['requires_location_inputs', 'unrecognized_location', 'not_supported'], true)) {
            throw new \DomainException('Complétez l’adresse de facturation et le traitement TVA du client Stripe.');
        }

        // Stripe priority: subscription PM, subscription legacy source, customer PM, customer source.
        // Never validate a lower-priority method while Stripe would actually charge a legacy source.
        $paymentId = self::id($sub['default_payment_method'] ?? null);
        if (($sub['default_payment_method'] ?? null) !== null && $paymentId === null) $this->invalidDefaultPaymentMethod();
        if ($paymentId === null && ($sub['default_source'] ?? null) !== null) $this->legacySource();
        $paymentId ??= self::id($customer['invoice_settings']['default_payment_method'] ?? null);
        if (($customer['invoice_settings']['default_payment_method'] ?? null) !== null && $paymentId === null) $this->invalidDefaultPaymentMethod();
        if ($paymentId === null && ($customer['default_source'] ?? null) !== null) $this->legacySource();
        if ($paymentId === null || !preg_match('/^pm_[A-Za-z0-9]+$/D', $paymentId)) {
            throw new \DomainException('Ajoutez un moyen de paiement par défaut à votre abonnement dans le portail Stripe.');
        }
        $payment = $this->read(fn () => $stripe->paymentMethods->retrieve($paymentId, []));
        if (($payment['id'] ?? '') !== $paymentId || ($payment['object'] ?? '') !== 'payment_method'
            || ($payment['livemode'] ?? false) !== true || self::id($payment['customer'] ?? null) !== $customerId) {
            throw new \DomainException('Le moyen de paiement par défaut doit être actif et rattaché au bon client Stripe.');
        }
        $type = $payment['type'] ?? '';
        $allowed = $sub['payment_settings']['payment_method_types'] ?? null;
        if ($allowed !== null && (!is_array($allowed) || !in_array($type, $allowed, true))) {
            throw new \DomainException('Autorisez le type du moyen de paiement par défaut dans les paramètres de l’abonnement Stripe.');
        }
        if (self::id($sub['pending_setup_intent'] ?? null) !== null) {
            $setupId = self::id($sub['pending_setup_intent']);
            $setup = $this->read(fn () => $stripe->setupIntents->retrieve($setupId, []));
            if (($setup['id'] ?? '') !== $setupId || ($setup['object'] ?? '') !== 'setup_intent'
                || ($setup['status'] ?? '') !== 'succeeded' || ($setup['livemode'] ?? false) !== true
                || self::id($setup['customer'] ?? null) !== $customerId || self::id($setup['payment_method'] ?? null) !== $paymentId) {
                throw new \DomainException('Terminez la confirmation du moyen de paiement dans Stripe avant d’émettre des factures.');
            }
        }
        if ($type === 'card') {
            $month = $payment['card']['exp_month'] ?? null;
            $year = $payment['card']['exp_year'] ?? null;
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            if (!is_int($month) || !is_int($year) || $month < 1 || $month > 12
                || ($year * 100 + $month) < (int) $now->format('Ym')) {
                throw new \DomainException('La carte bancaire par défaut est expirée ou invalide. Mettez-la à jour dans le portail Stripe.');
            }
            return;
        }
        if ($type === 'sepa_debit') {
            $this->assertSepaMandate($stripe, $customerId, $paymentId);
            return;
        }
        throw new \DomainException('Configurez une carte bancaire ou un prélèvement SEPA avec mandat actif pour le paiement automatique de votre abonnement.');
    }

    private function assertDates(array $sub): void
    {
        $now = time();
        foreach (['ended_at', 'cancel_at'] as $field) {
            if (isset($sub[$field]) && (!is_int($sub[$field]) || $sub[$field] <= $now)) {
                throw new \DomainException('L’abonnement Stripe a pris fin. Réactivez-le avant d’émettre des factures.');
            }
        }
        if (($sub['status'] ?? '') === 'trialing' && (!is_int($sub['trial_end'] ?? null) || $sub['trial_end'] <= $now)) {
            throw new \DomainException('L’essai Stripe est terminé ou sa période ne peut pas être vérifiée.');
        }
        $ends = [];
        if (isset($sub['current_period_end'])) $ends[] = $sub['current_period_end'];
        foreach ($sub['items']['data'] ?? [] as $item) {
            if (isset($item['current_period_end'])) $ends[] = $item['current_period_end'];
        }
        if ($ends === [] || array_filter($ends, static fn ($end) => !is_int($end) || $end <= $now)) {
            throw new \DomainException('La période de facturation Stripe est expirée ou ne peut pas être vérifiée.');
        }
        // canceled_at records the cancellation request, not the end of paid access.
    }

    private function assertTax(array $sub): void
    {
        if (($sub['automatic_tax']['enabled'] ?? false) === true) return;
        $rates = $sub['default_tax_rates'] ?? [];
        if (!is_array($rates) || $rates === []) {
            throw new \DomainException('Configurez la TVA de l’abonnement Stripe avant de facturer les émissions électroniques.');
        }
        foreach ($rates as $rate) {
            // Archived rates already attached to an existing subscription remain valid in Stripe.
            if (!is_array($rate) || !preg_match('/^txr_[A-Za-z0-9]+$/D', (string) ($rate['id'] ?? ''))
                || ($rate['object'] ?? '') !== 'tax_rate' || ($rate['livemode'] ?? false) !== true
                || ($rate['inclusive'] ?? null) !== false || !is_numeric($rate['percentage'] ?? null)
                || !is_finite((float) $rate['percentage']) || $rate['percentage'] < 0 || $rate['percentage'] > 100) {
                throw new \DomainException('Vérifiez les taux de TVA hors taxes configurés sur l’abonnement Stripe.');
            }
        }
    }

    private function assertSepaMandate(StripeClient $stripe, string $customerId, string $paymentId): void
    {
        // Bounded read of setup records for this exact customer and instrument; never infer a mandate from attachment alone.
        $setups = $this->read(fn () => $stripe->setupIntents->all(['customer' => $customerId, 'payment_method' => $paymentId, 'limit' => 10]));
        foreach ($setups['data'] ?? [] as $setup) {
            if (($setup['object'] ?? '') !== 'setup_intent' || !preg_match('/^seti_[A-Za-z0-9]+$/D', (string) ($setup['id'] ?? ''))
                || ($setup['livemode'] ?? false) !== true || ($setup['status'] ?? '') !== 'succeeded'
                || ($setup['usage'] ?? '') !== 'off_session' || self::id($setup['customer'] ?? null) !== $customerId
                || self::id($setup['payment_method'] ?? null) !== $paymentId) continue;
            if ($this->validMandate($stripe, self::id($setup['mandate'] ?? null), $paymentId)) return;
        }
        // A SEPA method can also have been saved by a successful payment rather than a SetupIntent.
        $charges = $this->read(fn () => $stripe->charges->all(['customer' => $customerId, 'limit' => 10]));
        foreach ($charges['data'] ?? [] as $charge) {
            if (($charge['object'] ?? '') !== 'charge' || !preg_match('/^ch_[A-Za-z0-9]+$/D', (string) ($charge['id'] ?? ''))
                || ($charge['livemode'] ?? false) !== true || ($charge['status'] ?? '') !== 'succeeded'
                || self::id($charge['customer'] ?? null) !== $customerId || self::id($charge['payment_method'] ?? null) !== $paymentId) continue;
            if ($this->validMandate($stripe, self::id($charge['payment_method_details']['sepa_debit']['mandate'] ?? null), $paymentId)) return;
        }
        throw new \DomainException('Le mandat SEPA actif ne peut pas être confirmé. Confirmez à nouveau votre prélèvement ou ajoutez une carte dans le portail Stripe.');
    }

    private function validMandate(StripeClient $stripe, ?string $id, string $paymentId): bool
    {
        if ($id === null || !preg_match('/^mandate_[A-Za-z0-9]+$/D', $id)) return false;
        $mandate = $this->read(fn () => $stripe->mandates->retrieve($id, []));
        return ($mandate['id'] ?? '') === $id && ($mandate['object'] ?? '') === 'mandate'
            && ($mandate['livemode'] ?? false) === true && ($mandate['status'] ?? '') === 'active'
            && ($mandate['type'] ?? '') === 'multi_use' && self::id($mandate['payment_method'] ?? null) === $paymentId
            && ($mandate['on_behalf_of'] ?? null) === null && ($mandate['payment_method_details']['type'] ?? '') === 'sepa_debit';
    }

    private function legacySource(): never
    {
        throw new \DomainException('Votre abonnement utilise un ancien moyen de paiement Stripe. Remplacez-le par une carte ou un prélèvement SEPA dans le portail Stripe.');
    }

    private function invalidDefaultPaymentMethod(): never
    {
        throw new \DomainException('Le moyen de paiement par défaut ne peut pas être vérifié. Mettez-le à jour dans le portail Stripe.');
    }

    private function read(callable $request): array
    {
        $http = ApiRequestor::httpClient();
        $timeouts = $http instanceof CurlClient ? [$http->getTimeout(), $http->getConnectTimeout()] : null;
        try {
            if ($timeouts !== null) {
                // Stripe shares its transport process-wide: cap only this read and preserve stricter limits.
                $http->setTimeout($timeouts[0] > 0 ? min($timeouts[0], 10) : 10);
                $http->setConnectTimeout($timeouts[1] > 0 ? min($timeouts[1], 3) : 3);
            }
            return $request()->toArray();
        }
        catch (\Throwable) {
            // Do not expose API bodies, tokens, personal data, or chain the original exception to a web response.
            throw new \DomainException('La vérification du paiement Stripe est momentanément indisponible. Aucune nouvelle facture ne peut être émise ; réessayez dans quelques instants.');
        } finally {
            if ($timeouts !== null) {
                $http->setTimeout($timeouts[0]);
                $http->setConnectTimeout($timeouts[1]);
            }
        }
    }

    private static function id(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : (is_array($value) && is_string($value['id'] ?? null) ? $value['id'] : null);
    }
}
