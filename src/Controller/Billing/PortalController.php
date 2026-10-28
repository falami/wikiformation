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
  public function portal(Entite $entite, EntiteSubscriptionRepository $subs, StripeBillingService $stripe, \App\Service\Billing\InternalUsageContractPolicy $internalUsage): RedirectResponse
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

    try {
      $url = $stripe->createCustomerPortalSession(
        $sub->getStripeCustomerId(),
        $returnUrl,
        internalUsageOnly: $internalUsage->isProtected($entite),
      );
    } catch (\DomainException $error) {
      $this->addFlash('warning', $error->getMessage());
      return $this->redirectToRoute('app_administrateur_billing', ['entite' => $entite->getId()]);
    } catch (\Throwable $error) {
      error_log('[BILLING PORTAL] ' . $error::class);
      $this->addFlash('warning', 'Le portail de facturation est momentanément indisponible. Réessayez dans quelques instants.');
      return $this->redirectToRoute('app_administrateur_billing', ['entite' => $entite->getId()]);
    }

    return new RedirectResponse($url);
  }
}
