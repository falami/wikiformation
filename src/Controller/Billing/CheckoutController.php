<?php // src/Controller/Billing/CheckoutController.php
namespace App\Controller\Billing;

use App\Entity\Billing\EntiteSubscription;
use App\Entity\Billing\Plan;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurEntite;
use App\Repository\Billing\PlanRepository;
use App\Repository\Billing\AddonRepository;
use App\Repository\Billing\EntiteSubscriptionRepository;
use App\Repository\UtilisateurEntiteRepository;
use App\Service\Billing\StripeBillingService;
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
    EntityManagerInterface $em,
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
    $planCode = strtoupper((string) $request->request->get('plan', ''));
    $interval = (string) $request->request->get('interval', 'month'); // month|year
    $addons   = (array)  $request->request->all('addons');

    if (!$planCode) {
      $this->addFlash('danger', 'Plan manquant.');
      return $this->redirectToRoute('app_public_pricing');
    }
    if (!in_array($interval, ['month', 'year'], true)) {
      $interval = 'year';
    }

        // ✅ 5) Récupérer plan actif
    /** @var Plan|null $plan */
    $plan = $planRepo->findOneBy(['code' => $planCode, 'isActive' => true]);
    if (!$plan) {
      throw $this->createNotFoundException('Plan introuvable');
    }

    // ✅ 6) Addons
    $addonPriceIds = [];
    $addonCodes = [];
    foreach ($addons as $addonCode) {
      $addon = $addonRepo->findOneBy(['code' => strtoupper((string)$addonCode), 'isActive' => true]);
      if ($addon && $addon->getStripePriceId()) {
        $addonPriceIds[] = $addon->getStripePriceId();
        $addonCodes[] = $addon->getCode();
      }
    }

    // ✅ 7) Dernière sub de l'entité (pour blocage / customer)
    $last = $subRepo->findOneBy(['entite' => $entite], ['id' => 'DESC']);

    if ($last && $last->isBlockingNewCheckout()) {
      // portail Stripe (assure-toi que ta route accepte {entite})
      return $this->redirectToRoute('app_billing_portal', ['entite' => $entite->getId()]);
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

    // ✅ 9) Trial
    $trialConsumed = $subRepo->entiteHasConsumedTrial($entite);
    $trialDays = $trialConsumed ? 0 : 90;

    // ✅ 10) Stripe checkout session
    $url = $stripe->createCheckoutSession(
      customerId: $customerId,
      plan: $plan,
      interval: $interval,
      addonsStripePriceIds: $addonPriceIds,
      trialDays: $trialDays,
      allowPromotionCodes: true,
      localSubId: $sub->getId(),
    );

    return new RedirectResponse($url);
  }

  #[Route('/billing/success', name: 'app_billing_success')]
  public function success(Request $request, StripeBillingService $stripe): Response
  {
    $sessionId = (string) $request->query->get('session_id');
    if (!$sessionId) {
      return $this->redirectToRoute('app_public_pricing');
    }

    $session = $stripe->retrieveCheckoutSession($sessionId);

    return $this->render('billing/success.html.twig', [
      'session' => $session,
    ]);
  }

  #[Route('/billing/cancel', name: 'app_billing_cancel')]
  public function cancel(): Response
  {
    return $this->render('billing/cancel.html.twig');
  }
}
