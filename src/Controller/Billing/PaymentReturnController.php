<?php
namespace App\Controller\Billing;

use App\Repository\Billing\FactureCheckoutRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;

final class PaymentReturnController extends AbstractController
{
    #[Route('/paiement/success', name: 'app_billing_payment_success', methods: ['GET'])]
    public function success(Request $request, FactureCheckoutRepository $repo): Response
    {
        $sessionId = (string)$request->query->get('session_id');
        $fc = $sessionId ? $repo->findOneBySessionId($sessionId) : null;

        return $this->render('billing/payment/success.html.twig', [
            'fc' => $fc,
            'facture' => $fc?->getFacture(),
            'entite' => $fc?->getEntite(),
        ]);
    }

    #[Route('/paiement/cancel', name: 'app_billing_payment_cancel', methods: ['GET'])]
    public function cancel(Request $request): Response
    {
        return $this->render('billing/payment/cancel.html.twig', [
            'factureId' => (int)$request->query->get('facture'),
        ]);
    }
}