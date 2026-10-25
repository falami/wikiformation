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
use App\Service\Billing\InvoiceTotals;
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
        private readonly \App\Service\Billing\FacturePaymentRecorder $recorder,
    ) {}

    #[Route('/{id}/checkout/start', name: 'checkout_start', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function start(Entite $entite, Facture $facture, Request $request, \App\Service\Stripe\FactureCheckoutManager $manager): RedirectResponse
    {
        if ($facture->getEntite()?->getId() !== $entite->getId()) {
            throw $this->createAccessDeniedException('Facture non autorisée pour cette entité.');
        }

        if (!$this->isCsrfTokenValid('facture_checkout_' . $facture->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF invalide.');
        }

        if ($facture->isCanceled()) {
            $this->addFlash('warning', 'Une facture annulée ne peut pas être réglée.');
            return $this->redirectToRoute('app_administrateur_facture_show', ['entite' => $entite->getId(), 'id' => $facture->getId()]);
        }

        try {
            $checkout = $manager->createCheckoutForFacture($facture);
            return new RedirectResponse($checkout->getCheckoutUrl());
        } catch (\Throwable $error) {
            $this->addFlash('warning', 'Le paiement ne peut pas être ouvert pour le moment. Un paiement en cours doit être rapproché avec Stripe avant de créer un nouveau lien.');
            return $this->redirectToRoute('app_administrateur_facture_show', ['entite' => $entite->getId(), 'id' => $facture->getId()]);
        }
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

        try {
            $recorded = $this->recorder->record($fc, $session->toArray(), $connectedAccountId);
        } catch (\DomainException $error) {
            $this->addFlash('warning', 'Le paiement nécessite un rapprochement avec Stripe. Aucun règlement supplémentaire n’a été enregistré.');
            return $this->redirectToRoute('app_administrateur_facture_show', ['entite' => $entite->getId(), 'id' => $facture->getId()]);
        }
        if (!$recorded) {
            // An asynchronous payment may still succeed; an unpaid session is not necessarily expired.
            $this->addFlash('info', 'Le paiement est en attente de confirmation. Le règlement sera enregistré dès sa confirmation par Stripe.');
            return $this->redirectToRoute('app_administrateur_facture_show', ['entite' => $entite->getId(), 'id' => $facture->getId()]);
        }

        $this->addFlash('success', 'Paiement enregistré ✅');

        return $this->redirectToRoute('app_administrateur_facture_show', [
            'entite' => $entite->getId(),
            'id' => $facture->getId(),
        ]);
    }

    private function recomputeFactureStatus(Facture $facture): void
    {
        if ($facture->isCanceled()) return;
        $paidCents = 0;
        foreach ($facture->getPaiements() as $p) $paidCents += (int)$p->getMontantCents();

        $creditsCents = 0;
        foreach ($facture->getAvoirs() as $credit) $creditsCents += $credit->getMontantTtcCents();
        $netPayableCents = max(0, InvoiceTotals::totalTtc($facture) - $creditsCents);

        // A credit note settles a balance but is not evidence of an actual payment.
        if ($paidCents > 0 && $netPayableCents > 0 && InvoiceTotals::outstanding($facture) === 0) {
            $facture->setStatus(FactureStatus::PAID);
        } elseif ($paidCents > 0) {
            $facture->setStatus(FactureStatus::PARTIALLY_PAID);
        } else {
            $facture->setStatus(FactureStatus::DUE);
        }
    }
}
