<?php
declare(strict_types=1);
namespace App\Service\EInvoicing;

use App\Entity\EInvoiceUsageBatch;
use Stripe\StripeClient;
use Symfony\Component\DependencyInjection\Attribute\{AsAlias, Autowire};

#[AsAlias(UsageInvoiceGateway::class)]
final class StripeUsageInvoiceGateway implements UsageInvoiceGateway
{
    public function __construct(#[Autowire('%stripe.secret_key%')] private readonly string $secret) {}
    private function client(): StripeClient { return new StripeClient(['api_key' => $this->secret, 'max_network_retries' => 0]); }
    public function validate(EInvoiceUsageBatch $batch): void
    {
        $data = $batch->getPayload();
        $sub = $this->client()->subscriptions->retrieve($data['subscription'], []);
        if (($sub->livemode ?? false) !== true || (string) ($sub->customer ?? '') !== $data['customer']
            || strtolower((string) ($sub->currency ?? '')) !== 'eur' || !in_array($sub->status, ['active', 'trialing', 'past_due'], true)) {
            throw new \DomainException('Abonnement Stripe de production, client et devise incompatibles : aucun montant ajouté.');
        }
        // Inherit only explicit subscription tax handling. An unrelated training VAT exemption must not leak here.
        if (!(bool) ($sub->automatic_tax->enabled ?? false) && count($sub->default_tax_rates ?? []) === 0) {
            throw new \DomainException('Configurez le traitement TVA de l’abonnement Stripe avant de facturer les émissions.');
        }
    }
    public function create(EInvoiceUsageBatch $batch): string
    {
        $item = $this->client()->invoiceItems->create($batch->getPayload(), ['idempotency_key' => $batch->getRequestKey()]);
        if (!$this->matches($item->toArray(), $batch)) throw new \UnexpectedValueException('Réponse Stripe incompatible : rapprochement requis.');
        return $item->id;
    }
    public function find(EInvoiceUsageBatch $batch): ?string
    {
        if (!$batch->getAttemptedAt()) return null;
        $items = $this->client()->invoiceItems->all(['customer' => $batch->getPayload()['customer'], 'limit' => 100,
            'created' => ['gte' => $batch->getAttemptedAt()->getTimestamp()-300, 'lte' => $batch->getAttemptedAt()->getTimestamp()+86400]]);
        $found = null; $count = 0;
        foreach ($items->autoPagingIterator() as $item) {
            if (++$count > 1000) throw new \RuntimeException('Rapprochement trop volumineux : contrôle manuel requis.');
            $data = $item->toArray();
            if (($data['metadata']['wf_einvoice_batch'] ?? '') !== $batch->getRequestKey()) continue;
            if (!$this->matches($data, $batch) || ($found && $found !== $data['id'])) throw new \UnexpectedValueException('Ligne Stripe contradictoire : contrôle manuel requis.');
            $found = $data['id'];
        }
        return $found;
    }
    private function matches(array $data, EInvoiceUsageBatch $batch): bool
    {
        $payload = $batch->getPayload();
        // Current Stripe API nests subscription attribution under parent; older versions used subscription.
        $subscription = $data['subscription'] ?? $data['parent']['subscription_details']['subscription'] ?? null;
        return preg_match('/^ii_[A-Za-z0-9]+$/D', (string) ($data['id'] ?? '')) === 1
            && ($data['livemode'] ?? false) === true && ($data['customer'] ?? '') === $payload['customer']
            && $subscription === $payload['subscription'] && ($data['currency'] ?? '') === 'eur'
            && (int) ($data['amount'] ?? -1) === $batch->getAmountCents()
            && ($data['metadata']['wf_einvoice_batch'] ?? '') === $batch->getRequestKey();
    }
}
