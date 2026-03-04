<?php

namespace App\Controller\Webhook;

use App\Entity\Paiement;
use App\Enum\ModePaiement;
use App\Repository\Billing\FactureCheckoutRepository;
use App\Repository\FactureRepository;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\Webhook;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;

#[Route('/webhooks/stripe', name: 'app_webhook_stripe', methods: ['POST'])]
class StripeWebhookController extends AbstractController
{
    public function __construct(
        private readonly string $stripeWebhookSecret,
        private readonly FactureCheckoutRepository $checkoutRepo,
        private readonly FactureRepository $factureRepo,
        private readonly EntityManagerInterface $em,
    ) {}

    public function __invoke(Request $request): Response
    {
        $payload = $request->getContent();
        $sig = $request->headers->get('Stripe-Signature');

        try {
            $event = Webhook::constructEvent($payload, $sig, $this->stripeWebhookSecret);
        } catch (\Throwable $e) {
            return new Response('Invalid signature', 400);
        }

        // On gère le minimum vital :
        // - checkout.session.completed : paiement validé
        // - checkout.session.expired : session expirée
        switch ($event->type) {
            case 'checkout.session.completed':
                $session = $event->data->object;

                $fc = $this->checkoutRepo->findOneBySessionId($session->id);
                if (!$fc) return new Response('OK', 200);

                $fc->setStatus(\App\Entity\Billing\FactureCheckout::STATUS_COMPLETED);
                $fc->setStripePaymentIntentId($session->payment_intent ?? null);

                $factureId = (int)($session->metadata->facture_id ?? 0);
                $facture = $factureId ? $this->factureRepo->find($factureId) : null;

                if ($facture) {
                    // ✅ Crée un Paiement automatique (CB)
                    $p = new Paiement();
                    $p->setFacture($facture);
                    $p->setMontantCents((int)$session->amount_total); // TTC + éventuels frais service si inclus
                    $p->setDevise(strtoupper((string)($session->currency ?? 'eur')));
                    $p->setMode(ModePaiement::CB);
                    $p->setStripePaymentIntentId($session->payment_intent ?? null);

                    // Ventilation snapshot (à partir de ta facture)
                    $p->setVentilationHtHorsDeboursCents($facture->getMontantHtHorsDeboursCents());
                    $p->setVentilationTvaHorsDeboursCents($facture->getMontantTvaHorsDeboursCents());
                    $p->setVentilationDeboursCents($facture->getMontantDeboursTtcCents());
                    $p->setVentilationSource('stripe_auto');

                    // Payeur (si tu as déjà logique destinataire/entreprise)
                    $p->setPayeurUtilisateur($facture->getDestinataire());
                    $p->setPayeurEntreprise($facture->getEntrepriseDestinataire());

                    // IMPORTANT: createur/entite requis dans ton modèle
                    $p->setCreateur($facture->getCreateur());
                    $p->setEntite($facture->getEntite());

                    $this->em->persist($p);

                    // TODO : update status facture (PAID / PARTIAL)
                    // Exemple si tu as FactureStatus::PAID :
                    // $facture->setStatus(\App\Enum\FactureStatus::PAID);
                }

                $this->em->flush();
                return new Response('OK', 200);

            case 'checkout.session.expired':
                $session = $event->data->object;
                $fc = $this->checkoutRepo->findOneBySessionId($session->id);
                if ($fc) {
                    $fc->setStatus(\App\Entity\Billing\FactureCheckout::STATUS_EXPIRED);
                    $this->em->flush();
                }
                return new Response('OK', 200);

            default:
                return new Response('OK', 200);
        }
    }
}