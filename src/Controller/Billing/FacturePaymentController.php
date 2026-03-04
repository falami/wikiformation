<?php

namespace App\Controller\Administrateur\Billing;

use App\Entity\Entite;
use App\Entity\Facture;
use App\Service\Stripe\FactureCheckoutManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/administrateur/{entite}/facture/{id}/paiement', name: 'app_admin_facture_paiement_')]
#[IsGranted('ROLE_USER')]
class FacturePaymentController extends AbstractController
{
    #[Route('/checkout', name: 'checkout', methods: ['POST'])]
    public function checkout(Entite $entite, Facture $facture, Request $request, FactureCheckoutManager $manager): Response
    {
        if ($facture->getEntite()?->getId() !== $entite->getId()) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('facture_checkout_'.$facture->getId(), (string)$request->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF invalide');
        }

        // TODO: ajoute tes règles métier (status DUE, pas déjà PAYÉE, etc.)
        $fc = $manager->createCheckoutForFacture($facture);

        // Redirection Stripe Checkout
        return $this->redirect('https://checkout.stripe.com/c/pay/' . $fc->getStripeCheckoutSessionId());
        // ⚠️ Option: au lieu de fabriquer l’URL, retourne directement $session->url dans manager
        // (Stripe renvoie session->url). C’est le plus propre.
    }
}