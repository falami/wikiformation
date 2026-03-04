<?php

namespace App\Service\Stripe;

use App\Entity\Billing\FactureCheckout;
use App\Entity\Entite;
use App\Entity\Facture;
use App\Entity\Paiement;
use App\Enum\ModePaiement;
use App\Repository\Billing\FactureCheckoutRepository;
use Doctrine\ORM\EntityManagerInterface;

class FactureCheckoutManager
{
    public function __construct(
        private readonly StripeClientFactory $stripeFactory,
        private readonly EntityManagerInterface $em,
        private readonly string $appUrl,
    ) {}

    public function createCheckoutForFacture(Facture $facture, ?int $payeurUserId = null, ?int $payeurEntId = null): FactureCheckout
    {
        $entite = $facture->getEntite();
        $connect = $entite->getConnect();

        if (!$connect || !$connect->isOnlinePaymentEnabled()) {
            throw new \RuntimeException('Paiement en ligne désactivé pour cette entité.');
        }
        if (!$connect->isReadyForCheckout()) {
            throw new \RuntimeException('Compte Stripe de l’entité non prêt (onboarding incomplet).');
        }

        $amountCents = (int)$facture->getMontantTtcCents();
        if ($amountCents <= 0) {
            throw new \RuntimeException('Montant facture invalide.');
        }

        // ✅ Frais de service répercutés (optionnel)
        $serviceFeeCents = $connect->computeServiceFeeCents($amountCents);
        $grandTotalCents = $amountCents + $serviceFeeCents;

        $stripe = $this->stripeFactory->client();

        $successUrl = rtrim($this->appUrl, '/') . '/paiement/success?session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl  = rtrim($this->appUrl, '/') . '/paiement/cancel?facture=' . $facture->getId();

        // Stripe recommande CheckoutSessions pour les paiements web :contentReference[oaicite:6]{index=6}
        // Destination charge : payment_intent_data.transfer_data.destination :contentReference[oaicite:7]{index=7}
        $session = $stripe->checkout->sessions->create([
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,

            // “dynamic payment methods” recommandé par Stripe skill :contentReference[oaicite:8]{index=8}
            'automatic_tax' => ['enabled' => false],

            'line_items' => array_values(array_filter([
                [
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => strtolower($facture->getDevise()),
                        'unit_amount' => $amountCents,
                        'product_data' => [
                            'name' => 'Facture ' . $facture->getNumero(),
                            'description' => 'Paiement de votre facture',
                        ],
                    ],
                ],
                $serviceFeeCents > 0 ? [
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => strtolower($facture->getDevise()),
                        'unit_amount' => $serviceFeeCents,
                        'product_data' => [
                            'name' => 'Frais de service',
                            'description' => 'Frais de traitement',
                        ],
                    ],
                ] : null,
            ])),

            'metadata' => [
                'type' => 'facture_payment',
                'facture_id' => (string)$facture->getId(),
                'entite_id' => (string)$entite->getId(),
            ],

            // Destination charge (les fonds vont à l’entité)
            'payment_intent_data' => [
                'transfer_data' => [
                    'destination' => $connect->getStripeAccountId(),
                ],

                // ⚠️ application_fee_amount = commission plateforme
                // Ici, si tu veux juste répercuter des frais au client sans prendre une commission plateforme,
                // mets 0. Si tu veux prendre une commission, mets un montant.
                // (Tu peux aussi faire = serviceFeeCents si tu veux que “frais de service” aillent à la plateforme)
                'application_fee_amount' => 0,

                // utile si comptes pas même région -> merchant of record :contentReference[oaicite:9]{index=9}
                'on_behalf_of' => $connect->getStripeAccountId(),

                'metadata' => [
                    'facture_id' => (string)$facture->getId(),
                    'entite_id' => (string)$entite->getId(),
                ],
            ],
        ]);

        $fc = new FactureCheckout();
        $fc->setFacture($facture)
            ->setEntite($entite)
            ->setStripeCheckoutSessionId($session->id)
            ->setAmountTotalCents($grandTotalCents)
            ->setServiceFeeCents($serviceFeeCents);

        $this->em->persist($fc);
        $this->em->flush();

        return $fc;
    }
}