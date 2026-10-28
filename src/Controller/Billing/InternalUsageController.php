<?php
declare(strict_types=1);
namespace App\Controller\Billing;

use App\Entity\{Entite, Utilisateur};
use App\Repository\Billing\EntiteSubscriptionRepository;
use App\Security\Permission\TenantPermission;
use App\Service\Billing\{InternalUsageCheckout, SubscriptionTerms};
use App\Service\EInvoicing\EInvoiceTariff;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class InternalUsageController extends AbstractController
{
    #[Route('/administrateur/{entite}/billing/internal-usage', name: 'app_billing_internal_usage', methods: ['GET', 'POST'])]
    public function index(Entite $entite, Request $request, EntiteSubscriptionRepository $subscriptions, InternalUsageCheckout $checkout, SubscriptionTerms $terms): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        $this->denyAccessUnlessGranted(TenantPermission::BILLING_MANAGE, $entite);
        if (!EInvoiceTariff::isInternal($entite)) throw $this->createAccessDeniedException('Cette configuration est réservée à I.T. For You.');
        $sub = $subscriptions->findLatestForEntite($entite);
        if ($sub?->getStripeSubscriptionId()) return $this->redirectToRoute('app_billing_portal', ['entite' => $entite->getId()]);
        $snapshot = $terms->snapshot();
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('internal_usage_'.$entite->getId(), $request->request->getString('_token'))) throw $this->createAccessDeniedException('Votre session a expiré. Rechargez cette page.');
            if (!$request->request->getBoolean('terms_accepted') || !$request->request->getBoolean('usage_accepted')
                || $request->request->getString('terms_version') !== $snapshot['version']
                || !hash_equals($snapshot['sha256'], $request->request->getString('terms_digest'))) {
                $this->addFlash('warning', 'Consultez et acceptez les conditions actuelles et le tarif des consommations avant de continuer.');
                return $this->redirectToRoute('app_billing_internal_usage', ['entite' => $entite->getId()]);
            }
            $user = $this->getUser();
            if (!$user instanceof Utilisateur) throw $this->createAccessDeniedException();
            try {
                $return = $this->generateUrl('app_billing_internal_usage', ['entite' => $entite->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
                return $this->redirect($checkout->start($entite, $user, $snapshot,
                    $this->generateUrl('app_billing_success', [], UrlGeneratorInterface::ABSOLUTE_URL).'?session_id={CHECKOUT_SESSION_ID}', $return));
            } catch (\DomainException $error) {
                $this->addFlash('warning', $error->getMessage());
                return $this->redirectToRoute('app_billing_internal_usage', ['entite' => $entite->getId()]);
            }
        }
        return $this->render('billing/internal_usage.html.twig', ['entite' => $entite, 'terms' => $snapshot, 'unitPriceCents' => EInvoiceTariff::unitPriceCents($entite)]);
    }
}
