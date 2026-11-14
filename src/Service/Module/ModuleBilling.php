<?php
declare(strict_types=1);
namespace App\Service\Module;

use App\Entity\Billing\{Module, ModuleAccess};
use App\Entity\Entite;
use App\Service\Stripe\StripeClientFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Platforms\SQLitePlatform;

/** All mutations serialize on the tenant row, including checkout creation and webhooks. */
final class ModuleBilling
{
    public function __construct(private EntityManagerInterface $em, private StripeClientFactory $stripe, private ModuleEntitlement $entitlements) {}

    public function locked(Entite $entite, callable $operation): mixed
    {
        return $this->em->wrapInTransaction(function () use ($entite, $operation) {
            if (!$this->em->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) $this->em->lock($entite, LockMode::PESSIMISTIC_WRITE);
            return $operation();
        });
    }
    public function row(Entite $entite, Module $module): ModuleAccess
    {
        $row = $this->entitlements->access($entite, $module);
        if ($row) { $this->em->refresh($row); return $row; }
        $row = new ModuleAccess(); $row->entite = $entite; $row->module = $module;
        $this->em->persist($row); $this->em->flush();
        return $row;
    }

    public function checkout(Entite $entite, Module $module, string $returnUrl): string
    {
        $this->locked($entite, fn() => $this->row($entite, $module));
        return $this->locked($entite, function () use ($entite, $module, $returnUrl) {
            if (!$module->active || !$module->stripePriceId || $module->included || $this->entitlements->internal($entite)) throw new \DomainException('Cette option ne nécessite pas de souscription ou n’est pas encore commercialisée.');
            $row = $this->row($entite, $module);
            $client = $this->stripe->client();
            if ($row->checkoutId) {
                $session = $client->checkout->sessions->retrieve($row->checkoutId);
                if ($session->status === 'open') {
                    if ($row->gift && ($row->giftEnd === null || $row->giftEnd > new \DateTimeImmutable())) throw new \DomainException('Cet organisme bénéficie déjà d’un accès offert.');
                    return $session->url;
                }
                if ($session->status === 'complete') throw new \DomainException('Votre paiement est en cours de synchronisation. Actualisez dans quelques instants.');
                $row->checkoutId = null;
            }
            if ($row->subscriptionId) $this->sync($row, $client->subscriptions->retrieve($row->subscriptionId)->toArray());
            if ($row->hasAccess() || ($row->subscriptionId && !in_array($row->status, ['canceled', 'incomplete_expired'], true))) throw new \DomainException('Cette option est déjà souscrite. Gérez votre abonnement ou régularisez votre paiement.');
            $price = $client->prices->retrieve($module->stripePriceId)->toArray();
            self::validatePrice($module, $price);
            // Dedicated platform customer: never a connected-account destination or tenant-collected payment.
            if (!$row->customerId) $row->customerId = $client->customers->create([
                'name' => $entite->getNom(), 'email' => $entite->getEmail(),
                'metadata' => ['module_access' => (string)$row->id, 'entite_id' => (string)$entite->getId()],
            ], ['idempotency_key' => 'module-customer-'.$row->id])->id;
            $meta = ['module_access' => (string)$row->id, 'module_code' => $module->code, 'entite_id' => (string)$entite->getId()];
            $data = ['metadata' => $meta];
            if (!$row->trialUsed && $module->trialDays > 0) $data['trial_period_days'] = $module->trialDays;
            $row->priceId = $module->stripePriceId; $row->price = $module->monthlyPrice;
            // A deterministic generation key survives an ambiguous Stripe response / transaction rollback.
            $generation = count($row->history);
            $session = $client->checkout->sessions->create([
                'mode' => 'subscription', 'customer' => $row->customerId,
                'metadata' => $meta, 'subscription_data' => $data,
                'line_items' => [['price' => $row->priceId, 'quantity' => 1]],
                'payment_method_collection' => 'always',
                'success_url' => $returnUrl.'?checkout=success', 'cancel_url' => $returnUrl,
                'locale' => 'fr',
            ], ['idempotency_key' => 'module-checkout-'.$row->id.'-'.$generation]);
            $row->checkoutId = $session->id; $row->audit('checkout_created');
            return $session->url;
        });
    }

    public static function validatePrice(Module $module, array $price): void
    {
        if (($price['id'] ?? '') !== $module->stripePriceId || !($price['active'] ?? false)
            || ($price['currency'] ?? '') !== 'eur' || ($price['unit_amount'] ?? null) !== $module->monthlyPrice
            || ($price['recurring']['interval'] ?? '') !== 'month' || ($price['recurring']['interval_count'] ?? 0) !== 1
            || ($price['recurring']['usage_type'] ?? '') !== 'licensed' || ($price['billing_scheme'] ?? '') !== 'per_unit') {
            throw new \DomainException('Le tarif Stripe doit correspondre au montant mensuel en euros affiché.');
        }
    }

    public function cancellation(Entite $entite, Module $module, bool $cancel, ?int $actor): void
    {
        $this->locked($entite, function () use ($entite, $module, $cancel, $actor) {
            $row = $this->row($entite, $module);
            if (!$row->subscriptionId) throw new \DomainException('Aucun abonnement Stripe à modifier.');
            $client = $this->stripe->client();
            $this->sync($row, $client->subscriptions->retrieve($row->subscriptionId)->toArray());
            if (!in_array($row->status, ['active', 'trialing', 'past_due', 'unpaid'], true)) throw new \DomainException('Cet abonnement est terminé. Vous pouvez souscrire à nouveau.');
            $sub = $client->subscriptions->update($row->subscriptionId, ['cancel_at_period_end' => $cancel]);
            $this->sync($row, $sub->toArray()); $row->audit($cancel ? 'cancellation_scheduled' : 'cancellation_reversed', $actor);
        });
    }

    public function portal(Entite $entite, Module $module, string $returnUrl): string
    {
        $row = $this->entitlements->access($entite, $module);
        if (!$row?->customerId) throw new \DomainException('Aucun compte de facturation pour cette option.');
        $client = $this->stripe->client();
        // Isolate module billing from the default portal's potentially enabled plan switches.
        $configuration = $client->billingPortal->configurations->create([
            'features' => [
                'invoice_history' => ['enabled' => true],
                'payment_method_update' => ['enabled' => true],
                'customer_update' => ['enabled' => true, 'allowed_updates' => ['email', 'address', 'name', 'tax_id']],
                'subscription_cancel' => ['enabled' => true, 'mode' => 'at_period_end', 'proration_behavior' => 'none'],
                'subscription_update' => ['enabled' => false],
            ],
            'metadata' => ['source' => 'wikiformation_modules'],
        ], ['idempotency_key' => 'wikiformation-module-portal-v1']);
        return $client->billingPortal->sessions->create(['customer' => $row->customerId, 'configuration' => $configuration->id, 'return_url' => $returnUrl])->url;
    }

    /** Called only after Stripe signature verification. Reload remote state to tolerate reordered events. */
    public function webhook(string $type, array $object): bool
    {
        $isCheckout = str_starts_with($type, 'checkout.session.');
        $isSubscription = str_starts_with($type, 'customer.subscription.');
        if (!$isCheckout && !$isSubscription) return false;
        $id = $object['metadata']['module_access'] ?? null;
        $row = $id ? $this->em->find(ModuleAccess::class, (int)$id) : null;
        if (!$row && $isSubscription) $row = $this->em->getRepository(ModuleAccess::class)->findOneBy(['subscriptionId' => $object['id'] ?? '']);
        if (!$id && !$row) return false;
        if (!$row) throw new \DomainException('Accès module introuvable.');
        $this->locked($row->entite, function () use ($row, $object, $isCheckout, $type) {
            $this->em->refresh($row);
            if ($isCheckout && ($object['customer'] ?? '') !== $row->customerId) throw new \DomainException('Client module incompatible.');
            // A delayed event from an older checkout cannot alter a newer pending checkout.
            if ($isCheckout && $row->checkoutId !== null && $row->checkoutId !== ($object['id'] ?? null)) return;
            if ($type === 'checkout.session.expired') {
                if ($row->checkoutId !== null) { $row->checkoutId = null; $row->audit('checkout_expired'); }
                return;
            }
            $subId = $isCheckout ? ($object['subscription'] ?? null) : ($object['id'] ?? null);
            if (!$subId) return;
            $remote = $this->stripe->client()->subscriptions->retrieve($subId)->toArray();
            // Ignore an old subscription's late events after a subsequent subscription has been bound.
            if ($row->subscriptionId && $row->subscriptionId !== $subId) {
                if (in_array($remote['status'] ?? '', ['canceled', 'incomplete_expired'], true)) return;
                $previous = $this->stripe->client()->subscriptions->retrieve($row->subscriptionId);
                if (!in_array($previous->status, ['canceled', 'incomplete_expired'], true)) throw new \DomainException('Un autre abonnement module est encore en cours.');
            }
            $this->sync($row, $remote);
            if ($isCheckout || !in_array($remote['status'] ?? '', ['canceled', 'incomplete_expired'], true)) $row->checkoutId = null;
        });
        return true;
    }

    public function sync(ModuleAccess $row, array $sub): void
    {
        $meta = $sub['metadata'] ?? [];
        $item = $sub['items']['data'][0] ?? [];
        if (($meta['module_access'] ?? '') !== (string)$row->id || ($meta['entite_id'] ?? '') !== (string)$row->entite->getId()
            || ($meta['module_code'] ?? '') !== $row->module->code || ($sub['customer'] ?? '') !== $row->customerId
            || ($item['price']['id'] ?? '') !== $row->priceId || count($sub['items']['data'] ?? []) !== 1 || ($item['quantity'] ?? 0) !== 1) {
            throw new \DomainException('Identité ou tarif de l’abonnement module incompatible.');
        }
        $status = $sub['status'] ?? 'incomplete';
        if ($row->status !== $status) $row->audit('stripe_status:'.$status);
        $row->subscriptionId = $sub['id']; $row->status = $status;
        $end = $status === 'trialing' ? ($sub['trial_end'] ?? null) : ($sub['current_period_end'] ?? $item['current_period_end'] ?? null);
        if (!empty($sub['cancel_at']) && $end) $end = min($end, $sub['cancel_at']);
        $row->periodEnd = $end ? (new \DateTimeImmutable())->setTimestamp((int)$end) : null;
        $row->cancelAtPeriodEnd = !empty($sub['cancel_at_period_end']) || !empty($sub['cancel_at']);
        if (!empty($sub['trial_start'])) $row->trialUsed = true;
    }
}
