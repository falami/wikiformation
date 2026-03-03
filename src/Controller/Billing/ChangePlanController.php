<?php

// src/Controller/Billing/ChangePlanController.php
namespace App\Controller\Billing;

use App\Entity\Utilisateur;
use App\Repository\Billing\PlanRepository;
use App\Repository\Billing\EntiteSubscriptionRepository;
use App\Service\Billing\StripeBillingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};
use Symfony\Component\Routing\Attribute\Route;

final class ChangePlanController extends AbstractController
{
  #[Route('/billing/change/preview', name: 'app_billing_change_preview', methods: ['POST'])]
  public function preview(
    Request $request,
    EntiteSubscriptionRepository $subRepo,
    PlanRepository $planRepo,
    StripeBillingService $stripe,
  ): JsonResponse {


    $planCode = (string)$request->request->get('plan');
    $interval = (string)$request->request->get('interval', 'month');

    /** @var Utilisateur $user */
    $user = $this->getUser();
    $entite = $user->getEntite();

    if (!$entite) {
      return $this->json(['ok' => false, 'error' => 'Aucune entité rattachée.'], 400);
    }


    $sub = $subRepo->findOneBy(['entite' => $entite], ['id' => 'DESC']);
    if (!$sub || !$sub->getStripeSubscriptionId()) {
      return $this->json(['ok' => false, 'error' => 'Aucun abonnement Stripe actif.'], 400);
    }

    if (!$this->isCsrfTokenValid('change_plan_preview', (string)$request->request->get('_token'))) {
      return $this->json(['ok' => false, 'error' => 'CSRF invalide.'], 403);
    }


    $plan = $planRepo->findOneBy(['code' => $planCode, 'isActive' => true]);
    if (!$plan) return $this->json(['ok' => false, 'error' => 'Plan introuvable.'], 404);

    $newPriceId = $interval === 'year' ? $plan->getStripePriceYearlyId() : $plan->getStripePriceMonthlyId();
    if (!$newPriceId) return $this->json(['ok' => false, 'error' => 'Price Stripe manquant.'], 400);

    // On récupère la subscription complète pour trouver le subscription_item id
    $stripeSub = $stripe->retrieveSubscription($sub->getStripeSubscriptionId());
    $itemId = $stripeSub->items->data[0]->id ?? null;
    if (!$itemId) return $this->json(['ok' => false, 'error' => 'Item Stripe introuvable.'], 400);

    $customerId = (string)($stripeSub->customer ?? '');
    if (!$customerId) return $this->json(['ok' => false, 'error' => 'Customer Stripe introuvable.'], 400);

    try {
      $invoice = $stripe->previewSubscriptionChange(
        $customerId,
        $stripeSub->id,
        $itemId,
        $newPriceId,
        true
      );

      $dueNow = (int)($invoice->amount_due ?? $invoice->total ?? 0);

      $lines = $invoice->lines->data ?? [];

      $prorationCents = 0;
      $nextPeriodCents = 0;
      $prorationTaxCents = 0;
      $nextTaxCents = 0;

      $uiLines = [];

      foreach ($lines as $line) {
        $amount = (int)($line->amount ?? 0);
        $isProration = !empty($line->proration);

        $lineTax = 0;
        if (!empty($line->tax_amounts) && is_array($line->tax_amounts)) {
          foreach ($line->tax_amounts as $ta) {
            $lineTax += (int)($ta->amount ?? 0);
          }
        }

        if ($isProration) {
          $prorationCents += $amount;
          $prorationTaxCents += $lineTax;
        } else {
          $nextPeriodCents += $amount;
          $nextTaxCents += $lineTax;
        }


        // (optionnel) utile pour comprendre les 0€ / crédits
        $uiLines[] = [
          'desc' => (string)($line->description ?? ''),
          'proration' => (bool)$isProration,
          'amount_cents' => $amount,
          'tax_cents' => $lineTax,
        ];
      }

      $amountDue = (int)($invoice->amount_due ?? 0);

      return $this->json([
        'ok' => true,
        'currency' => $invoice->currency ?? 'eur',

        'proration_cents' => $prorationCents,
        'proration_tax_cents' => $prorationTaxCents,

        'next_period_cents' => $nextPeriodCents,
        'next_tax_cents' => $nextTaxCents,

        // ✅ total Stripe “à payer maintenant”
        'amount_due_cents' => $amountDue,

        // debug UI
        'lines' => $uiLines,
      ]);
    } catch (\Stripe\Exception\InvalidRequestException $e) {
      if (str_contains($e->getMessage(), 'No upcoming invoices')) {
        return $this->json([
          'ok' => true,
          'due_now_cents' => 0,
          'currency' => 'eur',
          'note' => 'Stripe ne peut pas estimer de prorata pour le moment (aucune facture à venir).'
        ], 200);
      }

      return $this->json(['ok' => false, 'error' => $e->getMessage()], 400);
    }
  }

  #[Route('/billing/change/apply', name: 'app_billing_change_apply', methods: ['POST'])]
  public function apply(
    Request $request,
    EntiteSubscriptionRepository $subRepo,
    PlanRepository $planRepo,
    StripeBillingService $stripe,
  ): Response {


    if (!$this->isCsrfTokenValid('change_plan', (string)$request->request->get('_token'))) {
      throw $this->createAccessDeniedException('CSRF invalide.');
    }

    $planCode = (string)$request->request->get('plan');
    $interval = (string)$request->request->get('interval', 'month');

    /** @var Utilisateur $user */
    $user = $this->getUser();
    $entite = $user->getEntite();

    $sub = $subRepo->findOneBy(['entite' => $entite], ['id' => 'DESC']);
    if (!$sub || !$sub->getStripeSubscriptionId()) {
      $this->addFlash('danger', 'Aucun abonnement Stripe actif.');
      return $this->redirectToRoute('app_administrateur_billing', ['entite' => $entite->getId()]);
    }

    $plan = $planRepo->findOneBy(['code' => $planCode, 'isActive' => true]);
    if (!$plan) {
      $this->addFlash('danger', 'Plan introuvable.');
      return $this->redirectToRoute('app_administrateur_billing', ['entite' => $entite->getId()]);
    }

    $newPriceId = $interval === 'year' ? $plan->getStripePriceYearlyId() : $plan->getStripePriceMonthlyId();

    $stripeSub = $stripe->retrieveSubscription($sub->getStripeSubscriptionId());
    $itemId = $stripeSub->items->data[0]->id ?? null;

    $updated = $stripe->applySubscriptionChangeNow($stripeSub->id, $itemId, $newPriceId, true);

    $this->addFlash('success', 'Votre abonnement a été mis à jour.');
    return $this->redirectToRoute('app_administrateur_billing', ['entite' => $entite->getId()]);
  }
}
