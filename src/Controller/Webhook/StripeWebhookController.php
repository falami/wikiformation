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
                $session = $event->data->object;
                $sessionId = (string)($session->id ?? '');
                if ($sessionId === '') return new Response('OK', 200);

                $fc = $this->checkoutRepo->findOneBySessionId($sessionId);
                if (!$fc) return new Response('OK', 200);

                // ✅ Idempotence 1 : si déjà terminé => noop
                if ($fc->getStatus() === FactureCheckout::STATUS_COMPLETED) {
                    return new Response('OK', 200);
                }

                $pi = $session->payment_intent ?? null;
                $pi = $pi ? (string)$pi : null;

                // ✅ Idempotence 2 : si un paiement existe déjà pour ce PI => on marque checkout terminé et on sort
                if ($pi) {
                    $already = $this->paiementRepo->findOneBy(['stripePaymentIntentId' => $pi]);
                    if ($already) {
                        $fc->setStripePaymentIntentId($pi);
                        $fc->setStatus(FactureCheckout::STATUS_COMPLETED);
                        $this->em->flush();
                        return new Response('OK', 200);
                    }
                }

                $fc->setStripePaymentIntentId($pi);
                $fc->setStatus(FactureCheckout::STATUS_COMPLETED);

                $facture = $fc->getFacture();
                if ($facture) {
                    $p = new Paiement();
                    $p->setFacture($facture);
                    $p->setEntite($facture->getEntite());
                    $p->setCreateur($facture->getCreateur());
                    $p->setMode(ModePaiement::CB);
                    $p->setDevise(strtoupper((string)($session->currency ?? 'EUR')));

                    // ✅ important : on règle la facture sur SA part (pas les frais)
                    $p->setMontantCents($fc->getFactureAmountCents());

                    if (method_exists($p, 'setStripePaymentIntentId')) {
                        $p->setStripePaymentIntentId($pi);
                    }

                    // payeur (si dispo)
                    if (method_exists($facture, 'getDestinataire')) {
                        $p->setPayeurUtilisateur($facture->getDestinataire());
                    }
                    if (method_exists($facture, 'getEntrepriseDestinataire')) {
                        $p->setPayeurEntreprise($facture->getEntrepriseDestinataire());
                    }

                    if (method_exists($p, 'setVentilationSource')) {
                        $p->setVentilationSource('stripe_auto');
                    }

                    $this->em->persist($p);

                    // ✅ ici : recalc statut facture (PAID/PARTIAL) via TON service existant
                    // $this->sync->syncFacture($facture);
                }

                $this->em->flush();
                return new Response('OK', 200);

            case 'checkout.session.expired':
                $session = $event->data->object;
                $sessionId = (string)($session->id ?? '');
                if ($sessionId === '') return new Response('OK', 200);

                $fc = $this->checkoutRepo->findOneBySessionId($sessionId);
                if ($fc && $fc->getStatus() !== FactureCheckout::STATUS_COMPLETED) {
                    $fc->setStatus(FactureCheckout::STATUS_EXPIRED);
                    $this->em->flush();
                }
                return new Response('OK', 200);

            default:
                return new Response('OK', 200);
        }
    }
}