<?php
// src/Controller/Webhook/StripeWebhookController.php

namespace App\Controller\Webhook;

use App\Entity\Paiement;
use App\Entity\Billing\FactureCheckout;
use App\Enum\ModePaiement;
use App\Repository\Billing\FactureCheckoutRepository;
use App\Repository\PaiementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\Webhook;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;

#[Route('/webhooks/stripe', name: 'app_webhook_stripe', methods: ['POST'])]
final class StripeWebhookController extends AbstractController
{
    public function __construct(
        private readonly string $stripeWebhookSecret,
        private readonly FactureCheckoutRepository $checkoutRepo,
        private readonly PaiementRepository $paiementRepo,
        private readonly EntityManagerInterface $em,
        private readonly \App\Service\Billing\FacturePaymentRecorder $recorder,
        // ✅ optionnel : injecte ton service de sync facture si tu l’as
        // private readonly FacturePaymentStatusSync $sync,
    ) {}

    public function __invoke(Request $request): Response
    {
        $payload = $request->getContent();
        $sig = $request->headers->get('Stripe-Signature');

        if (!$sig) {
            return new Response('Missing signature', 400);
        }

        try {
            $event = Webhook::constructEvent($payload, $sig, $this->stripeWebhookSecret);
        } catch (\Throwable) {
            return new Response('Invalid signature', 400);
        }

        switch ($event->type) {
            case 'checkout.session.completed':
            case 'checkout.session.async_payment_succeeded':
                $session = $event->data->object;
                $fc = $this->checkoutRepo->findOneBySessionId((string) ($session->id ?? ''));
                if (!$fc && ($session->metadata->type ?? '') === 'facture_checkout') {
                    $fc = $this->checkoutRepo->find((int) ($session->metadata->facture_checkout_id ?? 0));
                }
                if (!$fc) return new Response('OK', 200);
                try {
                    $this->recorder->record($fc, $session->toArray(), $event->account ?? null);
                } catch (\Throwable $error) {
                    // Keep failed reconciliation retryable; never acknowledge an unrecorded payment.
                    error_log('[STRIPE INVOICE WEBHOOK] Reconciliation failed: ' . $error->getMessage());
                    return new Response('synchronization_failed', 500);
                }
                return new Response('OK', 200);

            case 'checkout.session.expired':
                $session = $event->data->object;
                $sessionId = (string)($session->id ?? '');
                if ($sessionId === '') return new Response('OK', 200);

                $fc = $this->checkoutRepo->findOneBySessionId($sessionId);
                if ($fc && $fc->getStatus() !== FactureCheckout::STATUS_COMPLETED) {
                    $this->em->wrapInTransaction(function () use ($fc): void {
                        $this->em->lock($fc, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
                        $this->em->refresh($fc);
                        if ($fc->getStatus() !== FactureCheckout::STATUS_COMPLETED) $fc->setStatus(FactureCheckout::STATUS_EXPIRED);
                        $this->em->flush();
                    });
                }
                return new Response('OK', 200);

            default:
                return new Response('OK', 200);
        }
    }
}
