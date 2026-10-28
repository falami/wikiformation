<?php // src/Controller/Billing/CheckoutController.php
namespace App\Controller\Billing;

use App\Entity\Billing\EntiteSubscription;
use App\Entity\Billing\Plan;
use App\Entity\AuditLog;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurEntite;
use App\Repository\Billing\PlanRepository;
use App\Repository\Billing\AddonRepository;
use App\Repository\Billing\EntiteSubscriptionRepository;
use App\Repository\UtilisateurEntiteRepository;
use App\Service\Billing\StripeBillingService;
use App\Service\Billing\SubscriptionTerms;
use App\Service\Tenant\TenantContext;
use App\Security\Permission\TenantPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, RedirectResponse, Response};
use Symfony\Component\Routing\Attribute\Route;

final class CheckoutController extends AbstractController
{
  #[Route('/billing/checkout', name: 'app_billing_checkout', methods: ['POST'])]
  public function checkout(
    Request $request,
    PlanRepository $planRepo,
    AddonRepository $addonRepo,
    EntiteSubscriptionRepository $subRepo,
    UtilisateurEntiteRepository $ueRepo,
    TenantContext $tenant,
    StripeBillingService $stripe,
    SubscriptionTerms $terms,
    EntityManagerInterface $em,
    int $billingAppTrialDays,
  ): RedirectResponse {
    $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

    /** @var Utilisateur $user */
    $user = $this->getUser();

    // ✅ 1) Entité courante via TenantContext (PAS $user->getEntite())
    $entite = $tenant->getCurrentEntiteForUser($user);

    // Pas d'entité => onboarding
    if (!$entite) {
      $this->addFlash('danger', 'Aucun organisme sélectionné. Merci de créer votre organisme.');
      return $this->redirectToRoute('app_onboarding');
    }

    // ✅ 2) Interdit de checkout sur l'entité plateforme (#1)
    if ($tenant->isPlatformEntite($entite)) {
      $this->addFlash('warning', 'Pour souscrire, vous devez d’abord créer votre organisme.');
      return $this->redirectToRoute('app_onboarding');
    }

    // ✅ 3) Vérifier que l'utilisateur est ADMIN de CETTE entité (role >= 6)
    $ue = $ueRepo->findOneBy(['utilisateur' => $user, 'entite' => $entite]);
    if (!$ue instanceof UtilisateurEntite) {
      throw $this->createAccessDeniedException("Vous n'avez pas accès à cet organisme.");
    }

    // ✅ droits billing via voter (robuste)
    $this->denyAccessUnlessGranted(TenantPermission::BILLING_MANAGE, $entite);

    // ✅ 4) Lire la demande
    $planCode = strtoupper(trim((string) $request->request->get('plan', '')));
    $interval = (string) $request->request->get('interval', 'month'); // New subscriptions are monthly.
    $addons   = (array)  $request->request->all('addons');

    if (!$planCode) {
      $this->addFlash('danger', 'Plan manquant.');
      return $this->redirectToRoute('app_public_pricing');
    }

        // ✅ 5) Récupérer plan actif
    /** @var Plan|null $plan */
    $plan = $planRepo->findOneBy(['code' => $planCode, 'isActive' => true]);
    if (!$plan) {
      throw $this->createNotFoundException('Plan introuvable');
    }

    if (!$this->isCsrfTokenValid('start_trial', (string) $request->request->get('_token'))) {
      throw $this->createAccessDeniedException('Votre session a expiré. Rechargez la page.');
    }
    if (!$plan->isAvailableForNewSubscription($interval) || !$plan->isCheckoutConfigured($interval)) {
      $this->addFlash('warning', 'Cette offre est disponible à l’essai. Le paiement sera ouvert après activation de son tarif.');
      return $this->redirectToRoute('app_public_pricing');
    }
    // Reject unchecked or stale terms before any Stripe request or local subscription creation.
    $acceptedTerms = $terms->snapshot();
    if ($request->request->get('terms_accepted') !== '1'
        || $request->request->get('terms_version') !== $acceptedTerms['version']
        || !hash_equals($acceptedTerms['sha256'], (string) $request->request->get('terms_digest', ''))) {
      $this->addFlash('warning', 'Veuillez consulter et accepter les conditions générales de vente actuelles avant de poursuivre.');
      return $this->redirectToRoute('app_public_pricing');
    }
    try {
      $stripe->validatePlanPrice($plan, $interval);
    } catch (\Throwable $e) {
      $this->addFlash('warning', 'Le paiement de cette offre est temporairement indisponible. Aucun abonnement n’a été créé.');
      return $this->redirectToRoute('app_public_pricing');
    }

    // ✅ 6) Addons
    $addonPriceIds = [];
    $addonCodes = [];
    foreach (array_unique(array_filter($addons, 'is_string')) as $addonCode) {
      $addon = $addonRepo->findOneBy(['code' => trim((string) $addonCode), 'isActive' => true]);
      if ($addon && $addon->getStripePriceId()) {
        $addonPriceIds[] = $addon->getStripePriceId();
        $addonCodes[] = $addon->getCode();
      }
    }

    // Serialize checkout reservations per organisation, before contacting Stripe.
    $em->beginTransaction();
    try {
    $em->lock($entite, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
    // ✅ 7) Dernière sub de l'entité (pour blocage / customer)
    $last = $subRepo->findOneBy(['entite' => $entite], ['id' => 'DESC']);

    if ($last && ($last->isBlockingNewCheckout()
        || ($last->getStripeSubscriptionId() && !in_array($last->getStatus(), ['canceled', 'incomplete_expired'], true)))) {
      $em->commit();
      // portail Stripe (assure-toi que ta route accepte {entite})
      return $this->redirectToRoute('app_billing_portal', ['entite' => $entite->getId()]);
    }
    if ($last && $last->getStatus() === EntiteSubscription::STATUS_INCOMPLETE
        && $last->getCreatedAt() > new \DateTimeImmutable('-32 minutes')) {
      $em->commit();
      $this->addFlash('info', 'Une souscription est déjà en cours. Terminez le paiement ouvert ou attendez son expiration avant de réessayer (32 minutes maximum).');
      return $this->redirectToRoute('app_public_pricing');
    }

    // ✅ 8) Créer sub locale INCOMPLETE
    $sub = new EntiteSubscription();
    $sub->setEntite($entite);
    $sub->setPlan($plan);
    $sub->setIntervale($interval);
    $sub->setStatus(EntiteSubscription::STATUS_INCOMPLETE);
    $sub->setAddons($addonCodes);
    $sub->touch();

    // customer Stripe : réutiliser si connu
    $existingCustomerId = $last?->getStripeCustomerId();
    $customerId = $stripe->createOrGetCustomer($entite, $existingCustomerId);
    $sub->setStripeCustomerId($customerId);

    $em->persist($sub);
    $em->flush();
    $acceptedAt = new \DateTimeImmutable();
    $em->persist((new AuditLog())->setEvent('subscription.terms_accepted')
      ->setActor($user)->setCreateur($user)->setEntite($entite)->setCreatedAt($acceptedAt)
      ->setPayload([
        'subscriptionId' => $sub->getId(),
        'acceptedAt' => $acceptedAt->format(DATE_ATOM),
        'actorId' => $user->getId(),
        'entiteId' => $entite->getId(),
        'terms' => $acceptedTerms,
        'plan' => ['id' => $plan->getId(), 'code' => $plan->getCode(), 'name' => $plan->getName(),
          'priceCentsExcludingTax' => $plan->getPriceMonthlyCents(), 'currency' => 'EUR', 'interval' => $interval],
        'addons' => $addonCodes,
        // Acceptance precedes Checkout: this is not evidence of payment or electronic-invoicing activation.
        'stage' => 'accepted_before_checkout',
      ]));
    $em->flush();
    $em->commit();
    } catch (\Throwable $error) {
      if ($em->getConnection()->isTransactionActive()) $em->rollback();
      throw $error;
    }

    // ✅ 9) Trial
    $trialConsumed = $subRepo->entiteHasConsumedTrial($entite);
    $trialDays = $trialConsumed ? 0 : $billingAppTrialDays;

    // ✅ 10) Stripe checkout session
    $url = $stripe->createCheckoutSession(
      customerId: $customerId,
      plan: $plan,
      interval: $interval,
      addonsStripePriceIds: $addonPriceIds,
      trialDays: $trialDays,
      allowPromotionCodes: true,
      localSubId: $sub->getId(),
      expiresAt: time() + 1800,
    );

    return new RedirectResponse($url);
  }

  #[Route('/billing/success', name: 'app_billing_success')]
  public function success(Request $request, StripeBillingService $stripe, EntiteSubscriptionRepository $subs): Response
  {
    $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
    $sessionId = (string) $request->query->get('session_id');
    if (!$sessionId) {
      return $this->redirectToRoute('app_public_pricing');
    }

    $session = $stripe->retrieveCheckoutSession($sessionId);
    $sub = $subs->find((int) ($session->client_reference_id ?? 0));
    $entite = $sub?->getEntite();
    if (!$entite) throw $this->createNotFoundException();
    $this->denyAccessUnlessGranted(TenantPermission::BILLING_MANAGE, $entite);
    if (!$sub->getStripeCustomerId()
        || $sub->getStripeCustomerId() !== (string) ($session->customer ?? '')) throw $this->createNotFoundException();
    if (($session->status ?? '') !== 'complete' || !in_array($session->payment_status ?? '', ['paid', 'no_payment_required'], true)) {
      $this->addFlash('info', 'Votre paiement attend encore une confirmation.');
      return $this->redirectToRoute('app_administrateur_billing', ['entite' => $entite->getId()]);
    }

    return $this->render('billing/success.html.twig', [
      'session' => $session,
      'entite' => $entite,
    ]);
  }

  #[Route('/billing/cancel', name: 'app_billing_cancel')]
  public function cancel(): Response
  {
    return $this->render('billing/cancel.html.twig');
  }
}
