<?php
declare(strict_types=1);
namespace App\Service\Billing;

use App\Entity\{AuditLog, Entite, Utilisateur};
use App\Entity\Billing\{EntiteSubscription, InternalUsageSetup};
use App\Service\EInvoicing\EInvoiceTariff;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\{ApiRequestor, HttpClient\CurlClient, StripeClient};
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Psr\Log\{LoggerInterface, NullLogger};
use Stripe\Exception\ApiErrorException;

final class InternalUsageCheckout
{
    public function __construct(private EntityManagerInterface $em, #[Autowire('%stripe.secret_key%')] private string $secret, private LoggerInterface $logger = new NullLogger()) {}

    public function start(Entite $entite, Utilisateur $actor, array $terms, string $successUrl, string $cancelUrl): string
    {
        if (!EInvoiceTariff::isInternal($entite) || !$actor->getId()) throw new \DomainException('Cette configuration est réservée à I.T. For You.');
        if (!preg_match('/^(?:sk|rk)_live_[A-Za-z0-9]+$/D', $this->secret)) throw new \DomainException('La clé Stripe de production doit être configurée.');
        if ($this->em->getConnection()->isTransactionActive()) throw new \LogicException('Configuration Stripe hors transaction externe requise.');
        $setup = $this->em->wrapInTransaction(function () use ($entite, $actor, $terms, $successUrl, $cancelUrl): InternalUsageSetup {
            $this->em->lock($entite, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($entite);
            if (!EInvoiceTariff::isInternal($entite)) throw new \DomainException('Identité de l’organisme à vérifier.');
            $sub = $this->em->getRepository(EntiteSubscription::class)->findOneBy(['entite' => $entite], ['id' => 'DESC']);
            if (!$sub || !$sub->getPlan()) throw new \DomainException('Votre abonnement local existant doit être vérifié avant le rattachement Stripe.');
            if ($sub->getStripeSubscriptionId()) throw new \DomainException('Un abonnement Stripe est déjà rattaché. Utilisez le portail de facturation.');
            $setup = $this->em->getRepository(InternalUsageSetup::class)->findOneBy(['entite' => $entite]);
            if ($setup) {
                if ($setup->subscription->getId() !== $sub->getId()) throw new \DomainException('La configuration précédente doit être rapprochée avec Stripe.');
                return $setup;
            }
            $setup = new InternalUsageSetup();
            $setup->entite = $entite;
            $setup->subscription = $sub;
            $setup->customerId = $sub->getStripeCustomerId();
            $setup->acceptance = ['terms' => $terms, 'actorId' => $actor->getId(), 'acceptedAt' => $setup->createdAt->format(DATE_ATOM), 'baseMonthlyCents' => 0, 'unitPriceCents' => EInvoiceTariff::unitPriceCents($entite)];
            $metadata = ['entite_id' => (string) $entite->getId(), 'internal_usage_setup' => $setup->id, 'local_subscription_id' => (string) $sub->getId()];
            $setup->request = [
                'customer' => ['name' => $entite->getNom(), 'email' => $entite->getEmail(), 'metadata' => ['entite_id' => (string) $entite->getId(), 'source' => 'wikiformation']],
                'checkout' => ['mode' => 'subscription', 'client_reference_id' => (string) $sub->getId(),
                    'payment_method_collection' => 'always', 'payment_method_types' => ['card', 'sepa_debit'],
                    'line_items' => [['quantity' => 1, 'price_data' => ['currency' => 'eur', 'unit_amount' => 0, 'tax_behavior' => 'exclusive',
                        'recurring' => ['interval' => 'month'], 'product_data' => ['name' => 'I.T. For You — facturation des consommations']]]],
                    'automatic_tax' => ['enabled' => true], 'billing_address_collection' => 'required',
                    'customer_update' => ['name' => 'auto', 'address' => 'auto'], 'tax_id_collection' => ['enabled' => true],
                    'metadata' => $metadata, 'subscription_data' => ['metadata' => $metadata],
                    // Let Stripe start its default 24-hour window when it receives the request.
                    // A timestamp frozen at now + 30 minutes becomes invalid during preflight.
                    'success_url' => $successUrl, 'cancel_url' => $cancelUrl],
            ];
            $this->em->persist($setup);
            $this->em->persist((new AuditLog())->setEvent('subscription.internal_usage_terms')->setEntite($entite)->setActor($actor)->setCreateur($actor)->setCreatedAt(new \DateTimeImmutable())->setPayload($setup->acceptance + ['setupId' => $setup->id]));
            return $setup;
        });
        $client = new StripeClient(['api_key' => $this->secret, 'max_network_retries' => 0]);
        if ($setup->sessionId) {
            $session = $this->request(fn() => $client->checkout->sessions->retrieve($setup->sessionId, [])->toArray(), 'checkout.sessions.retrieve');
            if (($session['object'] ?? null) !== 'checkout.session' || ($session['id'] ?? null) !== $setup->sessionId
                || ($session['livemode'] ?? false) !== true || ($session['mode'] ?? null) !== 'subscription'
                || ($session['customer'] ?? null) !== $setup->customerId || ($session['metadata']['internal_usage_setup'] ?? null) !== $setup->id) throw new \DomainException('La configuration Stripe doit être rapprochée.');
            if (($session['status'] ?? null) === 'open' && is_string($session['url'] ?? null) && str_starts_with($session['url'], 'https://checkout.stripe.com/')) return $session['url'];
            throw new \DomainException('Cette configuration est terminée ou expirée. Attendez la confirmation Stripe ou demandez son rapprochement avant de recommencer.');
        }
        $legacyExpiry = $setup->request['checkout']['expires_at'] ?? null;
        $renewLegacy = is_int($legacyExpiry) && $legacyExpiry < time() - 300 && $setup->customerId !== null;
        // Preserve uncertain operations. Only an expired legacy request with a known
        // customer can be renewed, after read-only checks of the remote state below.
        if (!$renewLegacy && (($legacyExpiry !== null && $legacyExpiry <= time() + 60)
            || ($setup->attemptedAt && $setup->attemptedAt < new \DateTimeImmutable('-23 hours')))) {
            throw new \DomainException('La tentative précédente a expiré. Un rapprochement Stripe est nécessaire avant toute nouvelle configuration.');
        }
        $setup->attemptedAt ??= new \DateTimeImmutable();
        $this->em->flush();
        $customer = $setup->customerId
            ? $this->request(fn() => $client->customers->retrieve($setup->customerId, [])->toArray(), 'customers.retrieve')
            : $this->request(fn() => $client->customers->create($setup->request['customer'], ['idempotency_key' => 'internal-usage-customer-'.$setup->id])->toArray(), 'customers.create');
        if (($customer['object'] ?? null) !== 'customer' || ($customer['deleted'] ?? false) || ($customer['livemode'] ?? false) !== true
            || !is_string($customer['id'] ?? null) || !str_starts_with($customer['id'], 'cus_')
            || ($setup->customerId && $customer['id'] !== $setup->customerId)
            || ($customer['metadata']['entite_id'] ?? null) !== (string) $entite->getId()
            || !in_array($customer['currency'] ?? null, [null, 'eur'], true)) throw new \DomainException('Le client Stripe doit être rattaché à cet organisme en euros.');
        if (($customer['balance'] ?? null) !== 0) throw new \DomainException('Le solde Stripe existant doit être rapproché avant une configuration à 0 € de base.');
        // A zero-priced recurring line must not unexpectedly collect an earlier invoice or pending item.
        $pending = $this->request(fn() => $client->invoiceItems->all(['customer' => $customer['id'], 'pending' => true, 'limit' => 1])->toArray(), 'invoiceitems.list');
        $this->assertNoPendingDebt($pending);
        foreach (['open', 'draft'] as $status) {
            $invoices = $this->request(fn() => $client->invoices->all(['customer' => $customer['id'], 'status' => $status, 'limit' => 1])->toArray(), 'invoices.list');
            $this->assertNoPendingDebt($invoices);
        }
        $subscriptions = $this->request(fn() => $client->subscriptions->all(['customer' => $customer['id'], 'status' => 'all', 'limit' => 100])->toArray(), 'subscriptions.list');
        if (($subscriptions['has_more'] ?? true) !== false || !is_array($subscriptions['data'] ?? null)) throw new \DomainException('Les abonnements Stripe existants doivent être rapprochés avant de continuer.');
        foreach ($subscriptions['data'] as $existing) {
            if (!in_array($existing['status'] ?? null, ['canceled', 'incomplete_expired'], true)) throw new \DomainException('Une souscription Stripe existe déjà pour ce client. Attendez sa confirmation ou demandez son rapprochement.');
        }
        if ($renewLegacy) {
            $this->renewLegacyRequest($client, $setup, $actor, $terms);
        }
        $setup->customerId = $customer['id'];
        $setup->subscription->setStripeCustomerId($customer['id']);
        $setup->request['checkout']['customer'] = $customer['id'];
        $this->em->flush();
        $session = $this->request(fn() => $client->checkout->sessions->create($setup->request['checkout'], ['idempotency_key' => ($setup->request['checkout_idempotency_key'] ?? 'internal-usage-checkout-'.$setup->id)])->toArray(), 'checkout.sessions.create');
        if (($session['livemode'] ?? false) !== true || ($session['mode'] ?? null) !== 'subscription'
            || ($session['customer'] ?? null) !== $setup->customerId || ($session['metadata']['internal_usage_setup'] ?? null) !== $setup->id
            || !is_string($session['id'] ?? null) || !str_starts_with($session['id'], 'cs_')
            || !is_string($session['url'] ?? null) || !str_starts_with($session['url'], 'https://checkout.stripe.com/')) throw new \DomainException('Réponse Stripe incomplète : la configuration doit être rapprochée.');
        $setup->sessionId = $session['id'];
        $setup->checkoutUrl = $session['url'];
        $this->em->flush();
        return $session['url'];
    }

    /**
     * Recover the legacy 30-minute timestamp bug without replaying modified parameters
     * under the old key, dropping evidence, or creating a second live Checkout.
     */
    private function renewLegacyRequest(StripeClient $client, InternalUsageSetup $setup, Utilisateur $actor, array $terms): void
    {
        $frozenRequest = $setup->request;
        $sessions = $this->request(fn() => $client->checkout->sessions->all([
            'customer' => $setup->customerId, 'limit' => 100,
        ])->toArray(), 'checkout.sessions.list');
        // Fail closed on pagination: a partial list cannot authorize renewal.
        if (($sessions['object'] ?? null) !== 'list' || ($sessions['has_more'] ?? true) !== false || !is_array($sessions['data'] ?? null)) {
            throw new \DomainException('L’historique des sessions Stripe doit être vérifié avant de reprendre la configuration.');
        }
        foreach ($sessions['data'] as $session) {
            if (($session['object'] ?? null) !== 'checkout.session'
                || ($session['livemode'] ?? false) !== true || ($session['customer'] ?? null) !== $setup->customerId
                || !in_array($session['mode'] ?? null, ['payment', 'setup', 'subscription'], true)
                || !in_array($session['status'] ?? null, ['open', 'complete', 'expired'], true)) {
                throw new \DomainException('Une session Stripe doit être vérifiée avant de reprendre la configuration.');
            }
            if (($session['metadata']['internal_usage_setup'] ?? null) === $setup->id
                && (($session['metadata']['entite_id'] ?? null) !== (string) $setup->entite->getId()
                    || ($session['metadata']['local_subscription_id'] ?? null) !== (string) $setup->subscription->getId())) {
                throw new \DomainException('Les références de la session Stripe doivent être rapprochées.');
            }
            if ($session['mode'] === 'subscription' && $session['status'] !== 'expired') {
                throw new \DomainException('Une configuration Stripe ouverte ou terminée existe déjà. Son rapprochement est nécessaire avant de continuer.');
            }
        }
        $this->em->wrapInTransaction(function () use ($setup, $frozenRequest, $actor, $terms): void {
            $this->em->lock($setup->entite, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($setup);
            $this->em->refresh($setup->subscription);
            if ($setup->subscription->getStripeSubscriptionId() || $setup->sessionId) {
                throw new \DomainException('La configuration a évolué. Rechargez la page avant de continuer.');
            }
            // A concurrent request may have already prepared the new generation.
            if ($setup->request !== $frozenRequest) {
                throw new \DomainException('Une reprise est déjà en cours. Rechargez la page avant de continuer.');
            }
            $previousKey = $setup->request['checkout_idempotency_key'] ?? 'internal-usage-checkout-'.$setup->id;
            $previousAcceptance = $setup->acceptance;
            $setup->request['checkout_history'][] = [
                'request' => $setup->request['checkout'], 'idempotency_key' => $previousKey,
                'attemptedAt' => $setup->attemptedAt?->format(DATE_ATOM),
            ];
            unset($setup->request['checkout']['expires_at']);
            $setup->request['checkout_idempotency_key'] = 'internal-usage-checkout-'.$setup->id.'-'.bin2hex(random_bytes(8));
            $setup->attemptedAt = new \DateTimeImmutable();
            $setup->acceptance = ['terms' => $terms, 'actorId' => $actor->getId(),
                'acceptedAt' => $setup->attemptedAt->format(DATE_ATOM),
                'baseMonthlyCents' => 0, 'unitPriceCents' => EInvoiceTariff::unitPriceCents($setup->entite)];
            $this->em->persist((new AuditLog())->setEvent('subscription.internal_usage_resumed')
                ->setEntite($setup->entite)->setActor($actor)->setCreateur($actor)->setCreatedAt($setup->attemptedAt)
                ->setPayload(['setupId' => $setup->id, 'previousKey' => $previousKey,
                    'previousAcceptance' => $previousAcceptance, 'acceptance' => $setup->acceptance,
                    'reason' => 'legacy_expiry_verified_no_live_subscription_checkout']));
        });
    }

    /** Validate every subscription event, including created-before-checkout and later plan changes. */
    public function assertSubscription(array $remote, EntiteSubscription $sub): void
    {
        $setup = $this->setupFor($sub, $remote['metadata']['internal_usage_setup'] ?? null);
        if (!$setup) return;
        $items = $remote['items']['data'] ?? null;
        $item = is_array($items) ? ($items[0] ?? []) : [];
        $price = $item['price'] ?? [];
        if (!EInvoiceTariff::isInternal($setup->entite) || $setup->entite->getId() !== $sub->getEntite()?->getId()
            || !is_string($setup->customerId) || !str_starts_with($setup->customerId, 'cus_')
            || $sub->getStripeCustomerId() !== $setup->customerId
            || ($remote['object'] ?? null) !== 'subscription' || !is_string($remote['id'] ?? null) || !str_starts_with($remote['id'], 'sub_')
            || ($sub->getStripeSubscriptionId() && $sub->getStripeSubscriptionId() !== $remote['id'])
            || ($remote['customer'] ?? null) !== $setup->customerId || ($remote['livemode'] ?? false) !== true
            || ($remote['metadata']['internal_usage_setup'] ?? null) !== $setup->id
            || ($remote['metadata']['entite_id'] ?? null) !== (string) $setup->entite->getId()
            || ($remote['metadata']['local_subscription_id'] ?? null) !== (string) $sub->getId()
            || ($remote['collection_method'] ?? null) !== 'charge_automatically' || ($remote['currency'] ?? null) !== 'eur'
            || !is_array($items) || count($items) !== 1 || ($remote['items']['has_more'] ?? true) !== false
            || ($price['unit_amount'] ?? null) !== 0 || ($price['currency'] ?? null) !== 'eur' || ($price['tax_behavior'] ?? null) !== 'exclusive'
            || ($price['recurring']['interval'] ?? null) !== 'month' || ($price['recurring']['interval_count'] ?? null) !== 1
            || ($item['quantity'] ?? null) !== 1 || ($remote['automatic_tax']['enabled'] ?? false) !== true) {
            throw new \DomainException('La confirmation Stripe ne correspond pas à la configuration interne à 0 € de base.');
        }
    }

    /** Called by the signed Stripe webhook before it changes the existing local subscription. */
    public function assertCompleted(array $session, array $remote, EntiteSubscription $sub): void
    {
        $setup = $this->setupFor($sub, $session['metadata']['internal_usage_setup'] ?? $remote['metadata']['internal_usage_setup'] ?? null);
        if (!$setup) return;
        $this->assertSubscription($remote, $sub);
        if (($session['object'] ?? null) !== 'checkout.session' || !is_string($session['id'] ?? null) || !str_starts_with($session['id'], 'cs_')
            || ($setup->sessionId && $setup->sessionId !== $session['id'])
            || ($session['livemode'] ?? false) !== true || ($session['mode'] ?? null) !== 'subscription' || ($session['status'] ?? null) !== 'complete'
            || ($session['client_reference_id'] ?? null) !== (string) $sub->getId()
            || ($session['metadata']['internal_usage_setup'] ?? null) !== $setup->id
            || ($session['metadata']['entite_id'] ?? null) !== (string) $setup->entite->getId()
            || ($session['metadata']['local_subscription_id'] ?? null) !== (string) $sub->getId()
            || ($session['customer'] ?? null) !== $setup->customerId || ($session['subscription'] ?? null) !== $remote['id']
            || ($session['currency'] ?? null) !== 'eur' || ($session['amount_total'] ?? null) !== 0
            || !in_array($session['payment_status'] ?? null, ['paid', 'no_payment_required'], true)) {
            throw new \DomainException('La session Stripe ne correspond pas à la configuration interne à 0 € de base.');
        }
        $setup->sessionId ??= $session['id'];
    }

    private function setupFor(EntiteSubscription $sub, mixed $remoteSetupId): ?InternalUsageSetup
    {
        $setup = $this->em->getRepository(InternalUsageSetup::class)->findOneBy(['subscription' => $sub]);
        if (!$setup && $remoteSetupId !== null) {
            // Metadata alone cannot authorize attaching an externally created subscription.
            throw new \DomainException('La configuration interne Stripe ne possède pas de réservation locale correspondante.');
        }
        return $setup;
    }

    private function assertNoPendingDebt(array $list): void
    {
        if (($list['has_more'] ?? true) !== false || ($list['data'] ?? null) !== []) {
            throw new \DomainException('Une facture ou une ligne Stripe antérieure doit être rapprochée avant une configuration à 0 € de base.');
        }
    }

    private function request(callable $operation, string $operationName = 'stripe.request'): array
    {
        $http = ApiRequestor::httpClient();
        $timeouts = $http instanceof CurlClient ? [$http->getTimeout(), $http->getConnectTimeout()] : null;
        try {
            if ($timeouts) { $http->setTimeout($timeouts[0] > 0 ? min(10, $timeouts[0]) : 10); $http->setConnectTimeout($timeouts[1] > 0 ? min(3, $timeouts[1]) : 3); }
            return $operation();
        } catch (\Stripe\Exception\ExceptionInterface $error) {
            // Do not log request bodies, keys, customer details or raw Stripe messages.
            $safe = static fn(?string $value): ?string => $value !== null && preg_match('/^[a-zA-Z0-9_.\[\]-]{1,100}$/D', $value) ? $value : null;
            $context = ['operation' => $operationName, 'exception_class' => $error::class];
            if ($error instanceof ApiErrorException) {
                $context += ['stripe_request_id' => $safe($error->getRequestId()),
                    'stripe_code' => $safe($error->getStripeCode()), 'http_status' => $error->getHttpStatus(),
                    'stripe_param' => $safe($error->getError()?->param)];
            }
            $this->logger->warning('Internal usage Stripe request failed.', $context);
            $reference = isset($context['stripe_request_id']) ? ' Référence : '.$context['stripe_request_id'].'.' : '';
            if ($error instanceof \Stripe\Exception\InvalidRequestException) {
                $parameter = isset($context['stripe_param']) ? ' (paramètre : '.$context['stripe_param'].')' : '';
                throw new \DomainException('Stripe a refusé la configuration'.$parameter.'. Contactez l’administrateur pour vérifier les réglages.'.$reference, 0, $error);
            }
            throw new \DomainException('Stripe est momentanément indisponible. Votre tentative est conservée ; réessayez sans créer une autre souscription.'.$reference, 0, $error);
        } finally {
            if ($timeouts) { $http->setTimeout($timeouts[0]); $http->setConnectTimeout($timeouts[1]); }
        }
    }
}
