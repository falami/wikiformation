<?php // src/Controller/Billing/StripeWebhookController.php
namespace App\Controller\Billing;

use App\Entity\Billing\EntiteSubscription;
use App\Repository\Billing\EntiteSubscriptionRepository;
use App\Repository\Billing\PlanRepository;
use App\Service\Billing\StripeBillingService;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\Webhook;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;


final class StripeWebhookController extends AbstractController
{
  #[Route('/stripe/webhook', name: 'app_stripe_webhook', methods: ['POST'])]
  public function webhook(
    Request $request,
    StripeBillingService $stripeBilling,
    \App\Service\Platform\StripeRevenue $platformRevenue,
    \App\Service\Marketplace\Payments $marketplace,
    \App\Service\Marketplace\Confirmations $marketConfirmations,
    EntiteSubscriptionRepository $subRepo,
    PlanRepository $planRepo,
    EntityManagerInterface $em,
    \App\Service\Partner\Commissions $commissions,
    \App\Service\Billing\InternalUsageCheckout $internalUsageCheckout,
    #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%env(STRIPE_WEBHOOK_SECRET)%')]
    string $stripeWebhookSecret,
  ): Response {
    $payload = $request->getContent();
    $sig = $request->headers->get('stripe-signature');

    try {
      $event = Webhook::constructEvent($payload, (string)$sig, $stripeWebhookSecret);
    } catch (\Throwable $e) {
      error_log('[STRIPE WEBHOOK] INVALID: ' . $e::class);
      return new Response('invalid', 400);
    }

    try {
      if (in_array($event->type, ['checkout.session.completed','checkout.session.async_payment_succeeded','checkout.session.expired'], true)) {
        $marketId = $event->data->object->metadata->marketplace_purchase ?? null;
        if ($marketId) {
          $purchase = $em->find(\App\Entity\Marketplace\Purchase::class, $marketId);
          if ($purchase) { $marketplace->sync($purchase); $marketConfirmations->send($purchase); }
          return new Response('ok');
        }
      }
      if (in_array($event->type, ['charge.updated','charge.refunded','charge.dispute.created','charge.dispute.closed'], true)) {
        $obj = $event->data->object;
        $paymentId = $obj->payment_intent ?? null;
        if ($paymentId && $purchase = $em->getRepository(\App\Entity\Marketplace\Purchase::class)->findOneBy(['paymentId'=>$paymentId])) {
          $marketplace->sync($purchase);
          // Asynchronous Stripe processing fees can arrive after checkout; reconcile them silently.
          if ($event->type !== 'charge.updated') $marketConfirmations->send($purchase);
        }
      }
      switch ($event->type) {
        case 'invoice.paid': {
            $commissions->record($event->data->object->toArray());
            $platformRevenue->record($event->data->object->toArray());
            $em->flush();
            break;
        }
        case 'checkout.session.completed': {
            $session = $event->data->object;

            if (($session->mode ?? null) !== 'subscription') break;

            $customerId = (string)($session->customer ?? '');
            $subscriptionId = (string)($session->subscription ?? '');
            if (!$customerId || !$subscriptionId) break;

            $localId = (int)($session->client_reference_id ?? 0);

            $sub = $localId
              ? $subRepo->find($localId)
              : $subRepo->findOneBy(['stripeCustomerId' => $customerId, 'stripeSubscriptionId' => null, 'status' => EntiteSubscription::STATUS_INCOMPLETE], ['id' => 'DESC']);

            if (!$sub) break;
            if ($sub->getStripeCustomerId() !== $customerId || ($sub->getStripeSubscriptionId() && $sub->getStripeSubscriptionId() !== $subscriptionId)) {
              throw new \DomainException('Checkout incompatible avec l’abonnement local.');
            }

            // ✅ recharge après update (sinon tu lis l'ancienne config)
            $stripeSub = $stripeBilling->retrieveSubscription($subscriptionId);
            if ((string) ($stripeSub->customer ?? '') !== $customerId) throw new \DomainException('Client Stripe incompatible.');
            $internalUsageCheckout->assertCompleted($session->toArray(), $stripeSub->toArray(), $sub);

            $sub->setStripeSubscriptionId($subscriptionId);
            $this->syncFromStripeSubscription($sub, $stripeSub, $planRepo);

            $sub->touch();
            $em->flush();
            break;
        }

        case 'customer.subscription.created':
        case 'customer.subscription.updated':
        case 'customer.subscription.deleted': {
            $obj = $event->data->object;                 // StripeObject
            $subscriptionId = (string)($obj->id ?? '');
            if (!$subscriptionId) break;

            // 1) retrouver ta sub locale
            $sub = $subRepo->findOneBy(['stripeSubscriptionId' => $subscriptionId], ['id' => 'DESC']);

            if (!$sub) {
              $customerId = (string)($obj->customer ?? '');
              if ($customerId) {
                $sub = $subRepo->findOneBy(
                  ['stripeCustomerId' => $customerId, 'stripeSubscriptionId' => null, 'status' => EntiteSubscription::STATUS_INCOMPLETE],
                  ['id' => 'DESC']
                );

              }
            }

            if (!$sub) break;

            // 2) IMPORTANT : recharger la sub complète depuis Stripe
            $stripeSub = $stripeBilling->retrieveSubscription($subscriptionId);
            if ((string) ($stripeSub->customer ?? '') !== $sub->getStripeCustomerId()) throw new \DomainException('Client Stripe incompatible.');
            $internalUsageCheckout->assertSubscription($stripeSub->toArray(), $sub);
            // Bind only after all remote identity and internal zero-base-price checks have passed.
            $sub->setStripeSubscriptionId($subscriptionId);

            $this->syncFromStripeSubscription($sub, $stripeSub, $planRepo);

            // si vraiment supprimé (deleted), tu peux aussi fixer canceledAt
            if ($event->type === 'customer.subscription.deleted') {
              $sub->setCanceledAt(new \DateTimeImmutable());
            }

            $sub->touch();
            $em->flush();
            break;
          }
      }
    } catch (\Throwable $e) {
      // Un événement non synchronisé doit rester réessayable par Stripe.
      // Les traitements ci-dessus mettent à jour l'abonnement existant.
      error_log('[STRIPE WEBHOOK] ERROR ' . $event->type . ' : ' . $e::class);
      return new Response('synchronization_failed', 500);
    }

    return new Response('ok', 200);
  }


  private function syncFromStripeSubscription(
    EntiteSubscription $sub,
    \Stripe\Subscription $stripeSub,
    PlanRepository $planRepo
  ): void {
    $sub->setStatus((string)($stripeSub->status ?? EntiteSubscription::STATUS_INCOMPLETE));

    $periodEnd = !empty($stripeSub->current_period_end) ? (int) $stripeSub->current_period_end : null;

    if (!$periodEnd && isset($stripeSub->items->data[0]->current_period_end)) {
      $periodEnd = (int) $stripeSub->items->data[0]->current_period_end;
    }

    $sub->setCurrentPeriodEnd(
      $periodEnd ? (new \DateTimeImmutable())->setTimestamp($periodEnd) : null
    );



    if (!empty($stripeSub->trial_end)) {
        $sub->setTrialEndsAt(
            (new \DateTimeImmutable())->setTimestamp((int) $stripeSub->trial_end)
        );
    }


    // Gestion annulation (Stripe peut utiliser cancel_at_period_end OU cancel_at)
    $cancelAtTs = null;

    // canceled_at is when cancellation was requested, not the end of paid access.
    if (!empty($stripeSub->cancel_at)) {
      $cancelAtTs = (int) $stripeSub->cancel_at;
    } elseif (!empty($stripeSub->cancel_at_period_end)) {
      $cancelAtTs = $periodEnd ?: (!empty($stripeSub->current_period_end) ? (int)$stripeSub->current_period_end : null);
    } elseif (!empty($stripeSub->ended_at)) {
      $cancelAtTs = (int) $stripeSub->ended_at;
    } elseif (($stripeSub->status ?? '') === EntiteSubscription::STATUS_CANCELED && !empty($stripeSub->canceled_at)) {
      $cancelAtTs = (int) $stripeSub->canceled_at;
    }

    $sub->setCanceledAt($cancelAtTs ? (new \DateTimeImmutable())->setTimestamp($cancelAtTs) : null);




    // items peut être vide selon les events
    $firstItem = null;
    if (isset($stripeSub->items) && isset($stripeSub->items->data) && is_array($stripeSub->items->data)) {
      $firstItem = $stripeSub->items->data[0] ?? null;
    }

    $priceId = $firstItem?->price?->id ?? null;

    if ($priceId) {
      $plan = $planRepo->createQueryBuilder('p')
        ->andWhere('p.stripePriceMonthlyId = :pid OR p.stripePriceYearlyId = :pid')
        ->setParameter('pid', $priceId)
        ->setMaxResults(1)
        ->getQuery()
        ->getOneOrNullResult();

      if ($plan) {
        $sub->setPlan($plan);
      }
    }

    $interval = $firstItem?->price?->recurring?->interval ?? null; // month|year|null
    $sub->setIntervale($interval === 'year' ? 'year' : 'month');
  }
}
