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
use Symfony\Component\DependencyInjection\Attribute\Autowire;


final class StripeWebhookController extends AbstractController
{
  #[Route('/stripe/webhook', name: 'app_stripe_webhook', methods: ['POST'])]
  public function webhook(
    Request $request,
    StripeBillingService $stripeBilling,
    EntiteSubscriptionRepository $subRepo,
    PlanRepository $planRepo,
    EntityManagerInterface $em,
    #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%env(STRIPE_WEBHOOK_SECRET)%')]
    string $stripeWebhookSecret,
  ): Response {
    $payload = $request->getContent();
    $sig = $request->headers->get('stripe-signature');

    try {
      $event = Webhook::constructEvent($payload, (string)$sig, $stripeWebhookSecret);
    } catch (\Throwable $e) {
      error_log('[STRIPE WEBHOOK] INVALID: ' . $e->getMessage());
      return new Response('invalid', 400);
    }

    try {
      switch ($event->type) {
        case 'checkout.session.completed': {
            $session = $event->data->object;

            if (($session->mode ?? null) !== 'subscription') break;

            $customerId = (string)($session->customer ?? '');
            $subscriptionId = (string)($session->subscription ?? '');
            if (!$customerId || !$subscriptionId) break;

            // ✅ AJOUTS
            $stripeBilling->setCustomerTaxExemptNone($customerId);
            $stripeBilling->enableAutomaticTaxOnSubscription($subscriptionId);

            $localId = (int)($session->client_reference_id ?? 0);

            $sub = $localId
              ? $subRepo->find($localId)
              : $subRepo->findOneBy(['stripeCustomerId' => $customerId], ['id' => 'DESC']);

            if (!$sub) break;

            // ✅ recharge après update (sinon tu lis l'ancienne config)
            $stripeSub = $stripeBilling->retrieveSubscription($subscriptionId);

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
                  ['stripeCustomerId' => $customerId, 'status' => EntiteSubscription::STATUS_INCOMPLETE],
                  ['id' => 'DESC']
                ) ?? $subRepo->findOneBy(['stripeCustomerId' => $customerId], ['id' => 'DESC']);

                if ($sub && !$sub->getStripeSubscriptionId()) {
                  $sub->setStripeSubscriptionId($subscriptionId);
                }
              }
            }

            if (!$sub) break;

            // 2) IMPORTANT : recharger la sub complète depuis Stripe
            $stripeSub = $stripeBilling->retrieveSubscription($subscriptionId);

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
      // IMPORTANT: log + 200 OK pour éviter que Stripe spamme,
      // OU 500 si tu veux que Stripe retente. Ici on log et 200.
      error_log('[STRIPE WEBHOOK] ERROR ' . $event->type . ' : ' . $e->getMessage());
      error_log($e->getTraceAsString());
      return new Response('ok', 200);
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

    if (!empty($stripeSub->canceled_at)) {
      $cancelAtTs = (int) $stripeSub->canceled_at;
    } elseif (!empty($stripeSub->ended_at)) {
      $cancelAtTs = (int) $stripeSub->ended_at;
    } elseif (!empty($stripeSub->cancel_at)) {
      $cancelAtTs = (int) $stripeSub->cancel_at;
    } elseif (!empty($stripeSub->cancel_at_period_end)) {
      $cancelAtTs = $periodEnd ?: (!empty($stripeSub->current_period_end) ? (int)$stripeSub->current_period_end : null);
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
