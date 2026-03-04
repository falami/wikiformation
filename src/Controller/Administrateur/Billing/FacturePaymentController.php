<?php
// src/Controller/Administrateur/Billing/FacturePaymentController.php

namespace App\Controller\Administrateur\Billing;

use App\Entity\Entite;
use App\Entity\Facture;
use App\Service\Stripe\FactureCheckoutManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/administrateur/{entite}/facture/{id}/paiement', name: 'app_administrateur_facture_paiement_')]
#[IsGranted('ROLE_USER')]
final class FacturePaymentController extends AbstractController
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

        // ✅ garde-fou : facture déjà payée (adapte selon ton modèle)
        if (!$facture->canBePaid()) {
            $this->addFlash('info', $facture->isCanceled()
                ? 'Cette facture est annulée.'
                : 'Cette facture est déjà payée.'
            );

            return $this->redirectToRoute('app_administrateur_facture_show', [
                'entite' => $entite->getId(),
                'id' => $facture->getId(),
            ]);
        }

        $fc = $manager->createCheckoutForFacture($facture);

        if (!$fc->getCheckoutUrl()) {
            throw new \RuntimeException('Stripe n’a pas renvoyé d’URL Checkout.');
        }

        return $this->redirect($fc->getCheckoutUrl());
    }
}