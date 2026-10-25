<?php // src/Controller/Billing/PortalController.php
namespace App\Controller\Billing;

use App\Entity\{Entite, Utilisateur};
use App\Repository\Billing\EntiteSubscriptionRepository;
use App\Service\Billing\StripeBillingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

final class PortalController extends AbstractController
{
  #[Route('/administrateur/{entite}/billing/portal', name: 'app_billing_portal', methods: ['GET'])]
  public function portal(Entite $entite, EntiteSubscriptionRepository $subs, StripeBillingService $stripe): RedirectResponse
  {
    $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
    $this->denyAccessUnlessGranted(\App\Security\Permission\TenantPermission::BILLING_MANAGE, $entite);
    /** @var Utilisateur $user */
    $user = $this->getUser();

    $sub = $subs->findOneBy(['entite' => $entite], ['id' => 'DESC']);
    if (!$sub || !$sub->getStripeCustomerId()) {
      return $this->redirectToRoute('app_public_pricing');
    }

    $returnUrl = $this->generateUrl(
      'app_administrateur_dashboard_index',
      ['entite' => $entite->getId()],
      \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL
    );

    $url = $stripe->createCustomerPortalSession(
      $sub->getStripeCustomerId(),
      $returnUrl
    );

    return new RedirectResponse($url);
  }
}
