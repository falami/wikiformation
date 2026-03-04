<?php

namespace App\Controller\Administrateur;

use App\Entity\Entite;
use App\Entity\Facture;
use App\Entity\Paiement;
use App\Entity\Utilisateur;
use App\Entity\Billing\FactureCheckout;
use App\Enum\ModePaiement;
use App\Repository\Billing\FactureCheckoutRepository;
use App\Security\Permission\TenantPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, RedirectResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[Route('/administrateur/{entite}/facture', name: 'app_administrateur_facture_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::FACTURE_MANAGE, subject: 'entite')]
final class FactureCheckoutController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FactureCheckoutRepository $checkoutRepo,
        #[Autowire('%env(STRIPE_SECRET_KEY)%')] private readonly string $stripeSecretKey,
    ) {}

    /**
     * Démarrer Checkout pour payer le RESTANT dû d’une facture.
     * ✅ Checkout créé SUR le compte connecté de l’entité (stripe_account).
     * ✅ application_fee_amount = frais plateforme (si configurés).
     */
    #[Route('/{id}/checkout/start', name: 'checkout_start', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function start(Entite $entite, Facture $facture, Request $request): RedirectResponse
    {
        // sécurité : facture appartient à l'entité
        if ($facture->getEntite()?->getId() !== $entite->getId()) {
            throw $this->createAccessDeniedException('Facture non autorisée pour cette entité.');
        }

        if (!$this->isCsrfTokenValid('facture_checkout_' . $facture->getId(), (string)$request->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF invalide.');
        }

        // Calcul restant
        $paidCents = 0;
        foreach ($facture->getPaiements() as $p) {
            $paidCents += (int)$p->getMontantCents();
        }

        $ttcTotalCents = (int)$facture->getTtcTotalCents(); // ton helper
        $remainingCents = max(0, $ttcTotalCents - $paidCents);

        if ($remainingCents <= 0) {
            $this->addFlash('info', 'Cette facture est déjà réglée.');
            return $this->redirectToRoute('app_administrateur_facture_show', [
                'entite' => $entite->getId(),
                'id' => $facture->getId(),
            ]);
        }

        // Connect entité
        $connect = $entite->getConnect();
        if (!$connect || !$connect->isOnlinePaymentEnabled() || !$connect->isReadyForCheckout()) {
            $this->addFlash('warning', "Paiement en ligne indisponible : Stripe Connect non prêt.");
            return $this->redirectToRoute('app_administrateur_facture_show', [
                'entite' => $entite->getId(),
                'id' => $facture->getId(),
            ]);
        }

        $connectedAccountId = $connect->getStripeAccountId();
        if (!$connectedAccountId) {
            $this->addFlash('warning', "Compte Stripe connecté introuvable.");
            return $this->redirectToRoute('app_administrateur_facture_show', [
                'entite' => $entite->getId(),
                'id' => $facture->getId(),
            ]);
        }

        /** @var Utilisateur|null $actor */
        $actor = $this->getUser();

        // Déterminer le payeur (utilisateur ou entreprise) depuis la facture si possible
        // (j’adapte à ce que tu as déjà dans ton show : f.destinataire / f.entrepriseDestinataire)
        $payeurUser = method_exists($facture, 'getDestinataire') ? $facture->getDestinataire() : null;
        $payeurEnt  = method_exists($facture, 'getEntrepriseDestinataire') ? $facture->getEntrepriseDestinataire() : null;

        // Frais plateforme (repercutés au payeur) : application_fee_amount
        $serviceFeeCents = $connect->computeServiceFeeCents($remainingCents);

        // Créer FactureCheckout (snapshot)
        $fc = new FactureCheckout();
        $fc->setEntite($entite);
        $fc->setFacture($facture);
        $fc->setStatus(FactureCheckout::STATUS_CREATED);
        $fc->setFactureAmountCents($remainingCents);
        $fc->setAmountTotalCents($remainingCents);
        $fc->setServiceFeeCents($serviceFeeCents);
        $fc->setPayeurUtilisateur($payeurUser);
        $fc->setPayeurEntreprise($payeurEnt);

        $this->em->persist($fc);
        $this->em->flush(); // on veut un id pour metadata

        // URLs (absolues)
        $successUrl = $request->getSchemeAndHttpHost() . $this->generateUrl('app_administrateur_facture_checkout_success', [
            'entite' => $entite->getId(),
        ]) . '?session_id={CHECKOUT_SESSION_ID}';

        $cancelUrl = $request->getSchemeAndHttpHost() . $this->generateUrl('app_administrateur_facture_show', [
            'entite' => $entite->getId(),
            'id' => $facture->getId(),
        ]);

        $stripe = new \Stripe\StripeClient($this->stripeSecretKey);

        // Création checkout SUR compte connecté + application fee
        $sessionParams = [
            'mode' => 'payment',
            'payment_method_types' => ['card'],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,

            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($facture->getDevise() ?? 'EUR'),
                    'unit_amount' => $remainingCents,
                    'product_data' => [
                        'name' => sprintf('Paiement facture %s', $facture->getNumero() ?: ('#'.$facture->getId())),
                    ],
                ],
            ]],

            'metadata' => [
                'type' => 'facture_checkout',
                'entite_id' => (string)$entite->getId(),
                'facture_id' => (string)$facture->getId(),
                'facture_checkout_id' => (string)$fc->getId(),
            ],
        ];

        // ✅ Application fee (plateforme) si > 0
        if ($serviceFeeCents > 0) {
            $sessionParams['payment_intent_data'] = [
                'application_fee_amount' => $serviceFeeCents,
                'metadata' => [
                    'facture_checkout_id' => (string)$fc->getId(),
                    'facture_id' => (string)$facture->getId(),
                ],
            ];
        }

        $session = $stripe->checkout->sessions->create(
            $sessionParams,
            ['stripe_account' => $connectedAccountId]
        );

        $fc->setStripeCheckoutSessionId($session->id);
        $fc->setCheckoutUrl($session->url);
        $this->em->flush();

        return new RedirectResponse($session->url);
    }

    /**
     * Retour success : vérifie session sur compte connecté,
     * et crée un Paiement local si payé.
     */
    #[Route('/checkout/success', name: 'checkout_success', methods: ['GET'])]
    public function success(Entite $entite, Request $request): RedirectResponse
    {
        $sessionId = (string)$request->query->get('session_id');
        if (!$sessionId) {
            $this->addFlash('warning', 'Session Stripe manquante.');
            return $this->redirectToRoute('app_administrateur_facture_index', ['entite' => $entite->getId()]);
        }

        $connect = $entite->getConnect();
        $connectedAccountId = $connect?->getStripeAccountId();

        if (!$connectedAccountId) {
            $this->addFlash('danger', "Compte Stripe connecté introuvable pour cet organisme.");
            return $this->redirectToRoute('app_administrateur_facture_index', ['entite' => $entite->getId()]);
        }

        /** @var FactureCheckout|null $fc */
        $fc = $this->checkoutRepo->findOneBy(['stripeCheckoutSessionId' => $sessionId]);

        if (!$fc) {
            $this->addFlash('warning', "Checkout introuvable (session_id inconnu).");
            return $this->redirectToRoute('app_administrateur_facture_index', ['entite' => $entite->getId()]);
        }

        $facture = $fc->getFacture();
        if (!$facture || $facture->getEntite()?->getId() !== $entite->getId()) {
            throw $this->createAccessDeniedException('Facture/entité incohérente.');
        }

        // Idempotence
        if ($fc->getStatus() === FactureCheckout::STATUS_COMPLETED) {
            $this->addFlash('success', 'Paiement déjà enregistré.');
            return $this->redirectToRoute('app_administrateur_facture_show', [
                'entite' => $entite->getId(),
                'id' => $facture->getId(),
            ]);
        }

        $stripe = new \Stripe\StripeClient($this->stripeSecretKey);

        $session = $stripe->checkout->sessions->retrieve(
            $sessionId,
            [],
            ['stripe_account' => $connectedAccountId]
        );

        $paymentStatus = (string)($session->payment_status ?? '');
        if ($paymentStatus !== 'paid') {
            // expired / unpaid / etc.
            $fc->setStatus(FactureCheckout::STATUS_EXPIRED);
            $this->em->flush();

            $this->addFlash('warning', "Paiement non confirmé (status: {$paymentStatus}).");
            return $this->redirectToRoute('app_administrateur_facture_show', [
                'entite' => $entite->getId(),
                'id' => $facture->getId(),
            ]);
        }

        $paymentIntentId = (string)($session->payment_intent ?? '');
        $amountTotal = (int)($session->amount_total ?? 0);
        if ($amountTotal <= 0) {
            $amountTotal = (int)$fc->getAmountTotalCents();
        }

        // ✅ Anti doublon: si un paiement existe déjà avec ce payment_intent_id
        if ($paymentIntentId) {
            $existing = $this->em->getRepository(Paiement::class)->findOneBy(['stripePaymentIntentId' => $paymentIntentId]);
            if ($existing) {
                $fc->setStatus(FactureCheckout::STATUS_COMPLETED);
                $fc->setStripePaymentIntentId($paymentIntentId);
                $this->em->flush();

                $this->addFlash('success', 'Paiement déjà présent (Stripe).');
                return $this->redirectToRoute('app_administrateur_facture_show', [
                    'entite' => $entite->getId(),
                    'id' => $facture->getId(),
                ]);
            }
        }

        /** @var Utilisateur|null $actor */
        $actor = $this->getUser();
        if (!$actor instanceof Utilisateur) {
            // ton app a peut-être besoin d'un acteur connecté
            $this->addFlash('warning', "Session utilisateur manquante.");
            return $this->redirectToRoute('app_administrateur_facture_show', [
                'entite' => $entite->getId(),
                'id' => $facture->getId(),
            ]);
        }

        // Créer Paiement local
        $paiement = new Paiement();
        $paiement->setEntite($entite);
        $paiement->setFacture($facture);
        $paiement->setCreateur($actor);

        $paiement->setMontantCents($amountTotal);
        $paiement->setDevise(strtoupper((string)($facture->getDevise() ?? 'EUR')));
        $paiement->setMode(ModePaiement::CB);
        $paiement->setDatePaiement(new \DateTimeImmutable());
        $paiement->setStripePaymentIntentId($paymentIntentId ?: null);

        // rattacher payeur (snapshot checkout)
        $paiement->setPayeurUtilisateur($fc->getPayeurUtilisateur());
        $paiement->setPayeurEntreprise($fc->getPayeurEntreprise());

        // meta utile (optionnel)
        $paiement->setMeta([
            'stripe' => [
                'checkout_session_id' => $sessionId,
                'connected_account' => $connectedAccountId,
                'application_fee_cents' => $fc->getServiceFeeCents(),
            ],
        ]);

        // (optionnel) ventilation auto snapshot
        $paiement->setVentilationSource('stripe');
        // si tu veux, tu peux remplir ht/tva/debours ici, mais tu as déjà un système

        $this->em->persist($paiement);

        // Marquer checkout completed
        $fc->setStatus(FactureCheckout::STATUS_COMPLETED);
        $fc->setStripePaymentIntentId($paymentIntentId ?: null);

        $this->em->flush();

        $this->addFlash('success', 'Paiement enregistré ✅');

        return $this->redirectToRoute('app_administrateur_facture_show', [
            'entite' => $entite->getId(),
            'id' => $facture->getId(),
        ]);
    }
}