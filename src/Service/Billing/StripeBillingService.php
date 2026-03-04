<?php

namespace App\Service\Billing;


use App\Entity\Billing\Plan;
use App\Entity\Entite;
use Stripe\StripeClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
final class StripeBillingService
{
  public function __construct(
    private readonly string $stripeSecretKey,
    #[Autowire('%app.url%')] private readonly string $appUrl,
  ) {}

  private function client(): StripeClient
  {
    return new StripeClient($this->stripeSecretKey);
  }

  public function createOrGetCustomer(Entite $entite, ?string $existingCustomerId, array $meta = []): string
  {
    $stripe = $this->client();

    if ($existingCustomerId) {
      return $existingCustomerId;
    }

    $customer = $stripe->customers->create([
      'name' => $entite->getNom(),
      'email' => $entite->getEmail(),
      'metadata' => array_merge([
        'entite_id' => (string)$entite->getId(),
        'source' => 'wikiformation',
      ], $meta),
    ]);

    return $customer->id;
  }

  public function createCheckoutSession(
    string $customerId,
    Plan $plan,
    string $interval,
    array $addonsStripePriceIds = [],
    int $trialDays = 0,
    bool $allowPromotionCodes = true,
    ?string $couponId = null,
    ?int $localSubId = null,              // 👈 AJOUT
  ): string {
    $stripe = $this->client();

    $priceId = $interval === 'year' ? $plan->getStripePriceYearlyId() : $plan->getStripePriceMonthlyId();
    if (!$priceId) {
      throw new \RuntimeException('Plan Stripe price manquant.');
    }

    $lineItems = [
      ['price' => $priceId, 'quantity' => 1],
    ];
    foreach ($addonsStripePriceIds as $addonPriceId) {
      $lineItems[] = ['price' => $addonPriceId, 'quantity' => 1];
    }

    $subscriptionData = [];
    if ($trialDays > 0) {
      $subscriptionData['trial_period_days'] = $trialDays;
    }
    if ($couponId) {
      $subscriptionData['discounts'] = [['coupon' => $couponId]];
    }

    $base = rtrim($this->appUrl, '/');

    $params = [
      'mode' => 'subscription',
      'customer' => $customerId,
      'line_items' => $lineItems,
      'allow_promotion_codes' => $allowPromotionCodes,
      'success_url' => $base . '/billing/success?session_id={CHECKOUT_SESSION_ID}',
      'cancel_url'  => $base . '/billing/cancel',
      'subscription_data' => $subscriptionData,
      // ✅ collecte adresse + TVA intracom
      'billing_address_collection' => 'required',
      'tax_id_collection' => ['enabled' => true],
      'customer_update' => [
        'name' => 'auto',
        'address' => 'auto',
      ],
      // ✅ AJOUT ICI (OBLIGATOIRE)
      'automatic_tax' => [
        'enabled' => true,
      ],
    ];

    if ($localSubId) {
      $params['client_reference_id'] = (string) $localSubId;
    }

    $session = $stripe->checkout->sessions->create($params);




    return $session->url;
  }

  public function createCustomerPortalSession(string $customerId, string $returnUrl): string
  {
    $stripe = $this->client();
    $portal = $stripe->billingPortal->sessions->create([
      'customer' => $customerId,
      'return_url' => $returnUrl,
    ]);
    return $portal->url;
  }

  public function retrieveCheckoutSession(string $sessionId): \Stripe\Checkout\Session
  {
    return $this->client()->checkout->sessions->retrieve($sessionId, []);
  }

  public function retrieveSubscription(string $subscriptionId): \Stripe\Subscription
  {
    return $this->client()->subscriptions->retrieve($subscriptionId, []);
  }

  public function createCoupon100PercentRepeatingMonths(int $months): string
  {
    $stripe = $this->client();

    $coupon = $stripe->coupons->create([
      'percent_off' => 100,
      'duration' => 'repeating',
      'duration_in_months' => $months,
      'name' => sprintf('OFFERT %d mois (WikiFormation)', $months),
      'metadata' => ['source' => 'wikiformation'],
    ]);

    return $coupon->id;
  }

  /**
   * Crée un subscription Schedule (X mois gratuits, puis normal).
   * Recommandé : à utiliser lors d’une souscription “Leader / négociée” ou geste commercial.
   */
  public function createSubscriptionScheduleFreeMonthsThenNormal(
    string $customerId,
    string $planPriceId,
    array $addonPriceIds,
    int $freeMonths,
  ): string {
    if ($freeMonths <= 0) {
      throw new \InvalidArgumentException('freeMonths doit être > 0');
    }

    $stripe = $this->client();

    $couponId = $this->createCoupon100PercentRepeatingMonths($freeMonths);

    $items = array_merge(
      [['price' => $planPriceId, 'quantity' => 1]],
      array_map(fn($p) => ['price' => $p, 'quantity' => 1], $addonPriceIds)
    );

    $schedule = $stripe->subscriptionSchedules->create([
      'customer' => $customerId,
      'start_date' => 'now',
      'end_behavior' => 'release', // à la fin du schedule, on libère en sub normal
      'phases' => [
        [
          'items' => $items,
          'iterations' => $freeMonths,
          'discounts' => [['coupon' => $couponId]],
        ],
        [
          'items' => $items,
        ],
      ],
      'metadata' => ['source' => 'wikiformation', 'type' => 'free_months_then_normal'],
    ]);

    return $schedule->id;
  }


  public function changeSubscriptionPlan(
    string $subscriptionId,
    string $newPriceId,
    array $addonPriceIds = [],
    bool $prorate = true
  ): \Stripe\Subscription {
    $stripe = $this->client();

    $sub = $stripe->subscriptions->retrieve($subscriptionId, []);
    $itemId = $sub->items->data[0]->id ?? null;
    if (!$itemId) {
      throw new \RuntimeException('Souscription item introuvable.');
    }

    $items = [
      ['id' => $itemId, 'price' => $newPriceId],
    ];

    // si tu gères les addons comme items séparés :
    foreach ($addonPriceIds as $p) {
      $items[] = ['price' => $p, 'quantity' => 1];
    }

    return $stripe->subscriptions->update($subscriptionId, [
      'items' => $items,
      'proration_behavior' => $prorate ? 'create_prorations' : 'none',
    ]);
  }



  public function previewSubscriptionChange(
    string $customerId,
    string $subscriptionId,
    string $subscriptionItemId,
    string $newPriceId,
    bool $prorate = true
  ): \Stripe\Invoice {
    $stripe = $this->client();

    return $stripe->invoices->createPreview([
      'customer' => $customerId,
      'subscription' => $subscriptionId,
      'subscription_details' => [
        'items' => [
          ['id' => $subscriptionItemId, 'price' => $newPriceId],
        ],
        'proration_behavior' => $prorate ? 'create_prorations' : 'none',
        'proration_date' => time(),
        // On garde le cycle (sinon Stripe te met la période complète immédiatement)
        'billing_cycle_anchor' => 'unchanged',
      ],

      // ✅ IMPORTANT
      'expand' => [
        'lines.data',
        'lines.data.tax_amounts',
        'total_tax_amounts',
      ],
    ]);
  }







  public function applySubscriptionChangeNow(
    string $subscriptionId,
    string $subscriptionItemId,
    string $newPriceId,
    bool $prorate = true
  ): \Stripe\Subscription {
    $stripe = $this->client();

    return $stripe->subscriptions->update($subscriptionId, [
      'items' => [
        [
          'id' => $subscriptionItemId,
          'price' => $newPriceId,
        ]
      ],
      'proration_behavior' => $prorate ? 'create_prorations' : 'none',
      // Optionnel : si tu veux “tout de suite” + recalcul du cycle :
      // 'billing_cycle_anchor' => 'now',
      // si paiement requis immédiatement, Stripe peut générer un invoice/prorata
      'expand' => ['latest_invoice.payment_intent'],
    ]);
  }


  public function setCustomerTaxExemptNone(string $customerId): void
  {
    $this->client()->customers->update($customerId, [
      'tax_exempt' => 'none',
    ]);
  }

  public function enableAutomaticTaxOnSubscription(string $subscriptionId): void
  {
    $this->client()->subscriptions->update($subscriptionId, [
      'automatic_tax' => ['enabled' => true],
    ]);
  }


  /**
   * Retourne le montant (unit_amount) d’un Stripe Price en cents.
   * Cache mémoire (par requête) pour éviter plusieurs appels Stripe.
   */
  public function getPriceUnitAmount(?string $priceId): ?int
  {
    if (!$priceId) return null;

    static $memo = [];
    if (array_key_exists($priceId, $memo)) {
      return $memo[$priceId];
    }

    $price = $this->client()->prices->retrieve($priceId, []);

    $memo[$priceId] = isset($price->unit_amount) ? (int) $price->unit_amount : null;
    return $memo[$priceId];
  }

  /**
   * Retourne un tableau [PLAN_CODE => ['month'=>cents|null, 'year'=>cents|null]]
   * à partir des stripePriceMonthlyId / stripePriceYearlyId.
   *
   * @param Plan[] $plans
   */
  public function getPlansPublicPrices(array $plans): array
  {
    $out = [];
    foreach ($plans as $plan) {
      $code = strtoupper($plan->getCode());
      $out[$code] = [
        'month' => $this->getPriceUnitAmount($plan->getStripePriceMonthlyId()),
        'year'  => $this->getPriceUnitAmount($plan->getStripePriceYearlyId()),
      ];
    }
    return $out;
  }
}
