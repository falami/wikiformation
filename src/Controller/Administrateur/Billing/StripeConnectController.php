<?php

namespace App\Controller\Administrateur\Billing;

use App\Entity\Entite;
use App\Entity\Billing\EntiteConnect;
use App\Repository\Billing\EntiteConnectRepository;
use App\Service\Stripe\StripeClientFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/administrateur/{entite}/billing/connect', name: 'app_administrateur_billing_connect_')]
#[IsGranted('ROLE_USER')]
class StripeConnectController extends AbstractController
{
    public function __construct(
        private readonly StripeClientFactory $stripeFactory,
        private readonly EntityManagerInterface $em,
        private readonly string $appUrl,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Entite $entite, EntiteConnectRepository $repo): Response
    {
        $connect = $entite->getConnect();
        if (!$connect) {
            $connect = (new EntiteConnect())->setEntite($entite);
            $entite->setConnect($connect);
            $this->em->persist($connect);
            $this->em->flush();
        }

        return $this->render('administrateur/billing/connect/index.html.twig', [
            'entite' => $entite,
            'connect' => $connect,
        ]);
    }

    #[Route('/start', name: 'start', methods: ['POST'])]
    public function start(Entite $entite, Request $request): Response
    {
        $this->validateCsrf($request, 'connect_start');

        $connect = $entite->getConnect() ?? (new EntiteConnect())->setEntite($entite);

        $stripe = $this->stripeFactory->client();

        // 1) Créer le compte si nécessaire (Express est souvent un bon compromis)
        if (!$connect->getStripeAccountId()) {
            $account = $stripe->accounts->create([
                'controller' => [ 'type' => 'express' ], // Stripe recommande d’utiliser controller properties :contentReference[oaicite:4]{index=4}
                'country' => 'FR',
                'email' => $entite->getEmail(),
                'business_type' => 'company',
                'business_profile' => [
                    'name' => $entite->getNom(),
                    'support_email' => $entite->getEmail(),
                ],
                'capabilities' => [
                    'card_payments' => ['requested' => true],
                    'transfers' => ['requested' => true],
                ],
                'metadata' => [
                    'entite_id' => (string)$entite->getId(),
                ],
            ]);

            $connect->setStripeAccountId($account->id);
            $connect->touch();
            $this->em->persist($connect);
            $this->em->flush();
        }

        // 2) Créer un lien d’onboarding
        $returnUrl = rtrim($this->appUrl, '/') . $this->generateUrl('app_admin_billing_connect_refresh', ['entite' => $entite->getId()]);
        $refreshUrl = $returnUrl;

        $link = $stripe->accountLinks->create([
            'account' => $connect->getStripeAccountId(),
            'refresh_url' => $refreshUrl,
            'return_url' => $returnUrl,
            'type' => 'account_onboarding',
        ]);

        return $this->redirect($link->url);
    }

    #[Route('/refresh', name: 'refresh', methods: ['GET'])]
    public function refresh(Entite $entite): Response
    {
        $connect = $entite->getConnect();
        if (!$connect || !$connect->getStripeAccountId()) {
            $this->addFlash('warning', 'Aucun compte Stripe lié.');
            return $this->redirectToRoute('app_admin_billing_connect_index', ['entite' => $entite->getId()]);
        }

        $stripe = $this->stripeFactory->client();
        $account = $stripe->accounts->retrieve($connect->getStripeAccountId(), []);

        $connect->setDetailsSubmitted((bool)$account->details_submitted);
        $connect->setChargesEnabled((bool)$account->charges_enabled);
        $connect->setPayoutsEnabled((bool)$account->payouts_enabled);
        $connect->touch();

        $this->em->flush();

        $this->addFlash('success', 'Statut Stripe mis à jour.');
        return $this->redirectToRoute('app_admin_billing_connect_index', ['entite' => $entite->getId()]);
    }

    private function validateCsrf(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, (string)$request->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF invalide.');
        }
    }
}