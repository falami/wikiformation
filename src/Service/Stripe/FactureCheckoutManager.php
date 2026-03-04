<?php
// src/Service/Stripe/FactureCheckoutManager.php

namespace App\Service\Stripe;

use App\Entity\Billing\FactureCheckout;
use App\Entity\Facture;
use Doctrine\ORM\EntityManagerInterface;

final class FactureCheckoutManager
{
    public function __construct(
        private readonly StripeClientFactory $stripeFactory,
        private readonly EntityManagerInterface $em,
        private readonly string $appUrl,
    ) {}

    public function createCheckoutForFacture(Facture $facture): FactureCheckout
    {
        $entite  = $facture->getEntite();
        if (!$entite) {
            throw new \RuntimeException('Facture sans entité.');
        }

        $connect = $entite->getConnect();
        if (!$connect || !$connect->isOnlinePaymentEnabled()) {
            throw new \RuntimeException('Paiement en ligne désactivé pour cette entité.');
        }
        if (!$connect->getStripeAccountId()) {
            throw new \RuntimeException('Aucun compte Stripe Connect lié à cette entité.');
        }
        if (!$connect->isReadyForCheckout()) {
            throw new \RuntimeException('Compte Stripe non prêt (onboarding incomplet).');
        }

        // ✅ montant TTC facture (adapte si besoin)
        $factureAmountCents = (int) $facture->getTtcTotalCents();
        if ($factureAmountCents <= 0) {
            throw new \RuntimeException('Montant facture invalide.');
        }

        $serviceFeeCents = $connect->computeServiceFeeCents($factureAmountCents);
        $grandTotalCents = $factureAmountCents + $serviceFeeCents;

        $stripe = $this->stripeFactory->client();

        // ✅ routes "admin" success/cancel (tu peux changer)
        $successUrl = rtrim($this->appUrl, '/') . '/paiement/success?session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl  = rtrim($this->appUrl, '/') . '/paiement/cancel?facture=' . $facture->getId();

        $currency = strtolower($facture->getDevise() ?: 'eur');

        $lineItems = [
            [
                'quantity' => 1,
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => $factureAmountCents,
                    'product_data' => [
                        'name' => 'Facture ' . ($facture->getNumero() ?: ('#'.$facture->getId())),
                        'description' => 'Paiement de votre facture',
                    ],
                ],
            ],
        ];

        if ($serviceFeeCents > 0) {
            $lineItems[] = [
                'quantity' => 1,
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => $serviceFeeCents,
                    'product_data' => [
                        'name' => 'Frais de service',
                        'description' => 'Frais de traitement',
                    ],
                ],
            ];
        }

        $session = $stripe->checkout->sessions->create([
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url'  => $cancelUrl,
            'line_items'  => $lineItems,

            'metadata' => [
                'type'       => 'facture_payment',
                'facture_id' => (string) $facture->getId(),
                'entite_id'  => (string) $entite->getId(),
            ],

            // ✅ Connect: transfert au compte de l’entité + fee à la plateforme
            'payment_intent_data' => [
                'transfer_data' => [
                    'destination' => $connect->getStripeAccountId(),
                ],
                'application_fee_amount' => $serviceFeeCents, // ✅ la plateforme récupère les frais
                'on_behalf_of' => $connect->getStripeAccountId(),
                'metadata' => [
                    'facture_id' => (string) $facture->getId(),
                    'entite_id'  => (string) $entite->getId(),
                ],
            ],
        ]);

        $fc = new FactureCheckout();
        $fc->setFacture($facture)
            ->setEntite($entite)
            ->setStripeCheckoutSessionId($session->id)
            ->setCheckoutUrl($session->url ?? null)
            ->setFactureAmountCents($factureAmountCents)
            ->setServiceFeeCents($serviceFeeCents)
            ->setAmountTotalCents($grandTotalCents)
            ->setStatus(FactureCheckout::STATUS_CREATED);

        $this->em->persist($fc);
        $this->em->flush();

        return $fc;
    }
}