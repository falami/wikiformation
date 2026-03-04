<?php

namespace App\Controller\Administrateur\Billing;

use App\Entity\Entite;
use App\Entity\Facture;
use App\Entity\Paiement;
use App\Entity\Utilisateur;
use App\Entity\Billing\FactureCheckout;
use App\Entity\Billing\StripeCustomerMap;
use App\Enum\ModePaiement;
use App\Enum\FactureStatus;
use App\Repository\Billing\FactureCheckoutRepository;
use App\Repository\Billing\StripeCustomerMapRepository;
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
        private readonly StripeCustomerMapRepository $customerMapRepo,
        #[Autowire('%env(STRIPE_SECRET_KEY)%')] private readonly string $stripeSecretKey,
    ) {}

    #[Route('/{id}/checkout/start', name: 'checkout_start', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function start(Entite $entite, Facture $facture, Request $request): RedirectResponse
    {
        if ($facture->getEntite()?->getId() !== $entite->getId()) {
            throw $this->createAccessDeniedException('Facture non autorisée pour cette entité.');
        }

        if (!$this->isCsrfTokenValid('facture_checkout_' . $facture->getId(), (string)$request->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF invalide.');
        }

        $paidCents = 0;
        foreach ($facture->getPaiements() as $p) $paidCents += (int)$p->getMontantCents();

        $totalCents = (int)$facture->getTtcTotalCents();
        $remainingCents = max(0, $totalCents - $paidCents);

        if ($remainingCents <= 0) {
            $this->addFlash('info', 'Cette facture est déjà réglée.');
            return $this->redirectToRoute('app_administrateur_facture_show', [
                'entite' => $entite->getId(),
                'id' => $facture->getId(),
            ]);
        }

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

        // ✅ évite de recréer des sessions en boucle
        $existing = $this->checkoutRepo->findLatestCreatedForFactureSince($facture, new \DateTimeImmutable('-2 hours'));
        if ($existing && $existing->getCheckoutUrl()) {
            return new RedirectResponse($existing->getCheckoutUrl());
        }

        /** @var Utilisateur|null $actor */
        $actor = $this->getUser();

        $payeurUser = method_exists($facture, 'getDestinataire') ? $facture->getDestinataire() : null;
        $payeurEnt  = method_exists($facture, 'getEntrepriseDestinataire') ? $facture->getEntrepriseDestinataire() : null;

        $serviceFeeCents = $connect->computeServiceFeeCents($remainingCents);

        // snapshot checkout
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
        $this->em->flush();

        $successUrl = $request->getSchemeAndHttpHost()
            . $this->generateUrl('app_administrateur_facture_checkout_success', ['entite' => $entite->getId()])
            . '?session_id={CHECKOUT_SESSION_ID}';

        $cancelUrl = $request->getSchemeAndHttpHost()
            . $this->generateUrl('app_administrateur_facture_show', ['entite' => $entite->getId(), 'id' => $facture->getId()]);

        $stripe = new \Stripe\StripeClient($this->stripeSecretKey);

        $sessionParams = [
            'mode' => 'payment',
            'payment_method_types' => ['card'],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,

            // (optionnel) meilleure UX + conformité
            'billing_address_collection' => 'auto',
            'customer_creation' => 'if_required',

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

        if ($serviceFeeCents > 0) {
            $sessionParams['payment_intent_data'] = [
                'application_fee_amount' => $serviceFeeCents,
                'metadata' => [
                    'facture_checkout_id' => (string)$fc->getId(),
                    'facture_id' => (string)$facture->getId(),
                ],
            ];
        }

        // ✅ VERSION PRO : réutiliser un Customer Stripe sur CE compte connecté
        $email = ($actor instanceof Utilisateur) ? trim((string)$actor->getEmail()) : '';
        $email = $email !== '' ? $email : null;

        if ($actor instanceof Utilisateur) {
            $map = $this->customerMapRepo->findFor($connectedAccountId, $actor);

            if ($map) {
                // customer existant => Checkout préremplit (et c’est le mieux)
                $sessionParams['customer'] = $map->getStripeCustomerId();
            } elseif ($email) {
                // sinon on le crée sur le compte connecté et on mémorise
                $customer = $stripe->customers->create([
                    'email' => $email,
                    'name' => trim(($actor->getPrenom() ?? '') . ' ' . ($actor->getNom() ?? '')) ?: null,
                    'metadata' => [
                        'utilisateur_id' => (string)$actor->getId(),
                        'entite_id' => (string)$entite->getId(),
                        'source' => 'wikiformation_facture',
                    ],
                ], ['stripe_account' => $connectedAccountId]);

                $map = (new StripeCustomerMap())
                    ->setConnectedAccountId($connectedAccountId)
                    ->setUtilisateur($actor)
                    ->setStripeCustomerId($customer->id);

                $this->em->persist($map);
                $this->em->flush();

                $sessionParams['customer'] = $customer->id;
            } elseif ($email) {
                // fallback (normalement jamais ici)
                $sessionParams['customer_email'] = $email;
            }
        } elseif ($email) {
            // si pas de user Symfony mais email dispo
            $sessionParams['customer_email'] = $email;
        }

        // (fallback sécurité) si on n’a pas customer mais qu’on a email
        if (!isset($sessionParams['customer']) && $email) {
            $sessionParams['customer_email'] = $email;
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

        $fc = $this->checkoutRepo->findOneBy(['stripeCheckoutSessionId' => $sessionId]);
        if (!$fc) {
            $this->addFlash('warning', "Checkout introuvable (session_id inconnu).");
            return $this->redirectToRoute('app_administrateur_facture_index', ['entite' => $entite->getId()]);
        }

        $facture = $fc->getFacture();
        if (!$facture || $facture->getEntite()?->getId() !== $entite->getId()) {
            throw $this->createAccessDeniedException('Facture/entité incohérente.');
        }

        // ✅ Idempotence : si déjà complété, on renvoie simplement
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
        if ($amountTotal <= 0) $amountTotal = (int)$fc->getAmountTotalCents();

        // ✅ si un paiement existe déjà (Stripe PI), on marque checkout et on recalcule facture
        if ($paymentIntentId) {
            $existingPaiement = $this->em->getRepository(Paiement::class)
                ->findOneBy(['stripePaymentIntentId' => $paymentIntentId]);

            if ($existingPaiement) {
                $fc->setStatus(FactureCheckout::STATUS_COMPLETED);
                $fc->setStripePaymentIntentId($paymentIntentId);
                $this->recomputeFactureStatus($facture);
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
            $this->addFlash('warning', "Session utilisateur manquante.");
            return $this->redirectToRoute('app_administrateur_facture_show', [
                'entite' => $entite->getId(),
                'id' => $facture->getId(),
            ]);
        }

        // ✅ Créer Paiement local + rattacher proprement
        $paiement = new Paiement();
        $paiement->setEntite($entite);
        $paiement->setCreateur($actor);

        $paiement->setMontantCents($amountTotal);
        $paiement->setDevise(strtoupper((string)($facture->getDevise() ?? 'EUR')));
        $paiement->setMode(ModePaiement::CB);
        $paiement->setDatePaiement(new \DateTimeImmutable());
        $paiement->setStripePaymentIntentId($paymentIntentId ?: null);

        $paiement->setPayeurUtilisateur($fc->getPayeurUtilisateur());
        $paiement->setPayeurEntreprise($fc->getPayeurEntreprise());

        $paiement->setMeta([
            'stripe' => [
                'checkout_session_id' => $sessionId,
                'connected_account' => $connectedAccountId,
                'application_fee_cents' => $fc->getServiceFeeCents(),
            ],
        ]);

        $paiement->setVentilationSource('stripe');

        // important : relation bi-directionnelle
        $facture->addPaiement($paiement);

        $this->em->persist($paiement);

        $fc->setStatus(FactureCheckout::STATUS_COMPLETED);
        $fc->setStripePaymentIntentId($paymentIntentId ?: null);

        // ✅ recalcul statut facture
        $this->recomputeFactureStatus($facture);

        $this->em->flush();

        $this->addFlash('success', 'Paiement enregistré ✅');

        return $this->redirectToRoute('app_administrateur_facture_show', [
            'entite' => $entite->getId(),
            'id' => $facture->getId(),
        ]);
    }

    private function recomputeFactureStatus(Facture $facture): void
    {
        $paidCents = 0;
        foreach ($facture->getPaiements() as $p) $paidCents += (int)$p->getMontantCents();

        $totalCents = (int)$facture->getTtcTotalCents();
        $remaining = max(0, $totalCents - $paidCents);

        if ($remaining <= 0 && $totalCents > 0) {
            $facture->setStatus(FactureStatus::PAID);
        } elseif ($paidCents > 0) {
            $facture->setStatus(FactureStatus::PARTIALLY_PAID);
        } else {
            $facture->setStatus(FactureStatus::DUE);
        }
    }
}