<?php
// src/Controller/Administrateur/Billing/StripeConnectController.php

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
final class StripeConnectController extends AbstractController
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
        if (!$this->isCsrfTokenValid('connect_start', (string)$request->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF invalide.');
        }

        $connect = $entite->getConnect() ?? (new EntiteConnect())->setEntite($entite);

        $stripe = $this->stripeFactory->client();

        if (!$connect->getStripeAccountId()) {
            $account = $stripe->accounts->create([
                'type' => 'express',
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
                    'entite_id' => (string) $entite->getId(),
                ],
            ]);

            $connect->setStripeAccountId($account->id)->touch();
            $entite->setConnect($connect);

            $this->em->persist($connect);
            $this->em->flush();
        }

        $returnUrl = rtrim($this->appUrl, '/') . $this->generateUrl('app_administrateur_billing_connect_refresh', ['entite' => $entite->getId()]);
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
            return $this->redirectToRoute('app_administrateur_billing_connect_index', ['entite' => $entite->getId()]);
        }

        $stripe = $this->stripeFactory->client();
        $account = $stripe->accounts->retrieve($connect->getStripeAccountId(), []);

        $connect->setDetailsSubmitted((bool)$account->details_submitted);
        $connect->setChargesEnabled((bool)$account->charges_enabled);
        $connect->setPayoutsEnabled((bool)$account->payouts_enabled);
        $connect->touch();

        $this->em->flush();

        $this->addFlash('success', 'Statut Stripe mis à jour.');
        return $this->redirectToRoute('app_administrateur_billing_connect_index', ['entite' => $entite->getId()]);
    }
    
    #[Route('/save', name: 'save', methods: ['POST'])]
    public function save(Entite $entite, Request $request): Response
    {
        $connect = $entite->getConnect();
        if (!$connect) throw $this->createNotFoundException();

        if (!$this->isCsrfTokenValid('connect_save', (string)$request->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF invalide.');
        }

        // ultra simple sans form symfony : on lit les inputs
        $connect->setOnlinePaymentEnabled((bool)$request->request->get('onlinePaymentEnabled'));
        $connect->setFeeFixedCents((int)$request->request->get('feeFixedCents', 0));
        $connect->setFeePercentBp((int)$request->request->get('feePercentBp', 0));
        $connect->touch();

        $this->em->flush();
        $this->addFlash('success', 'Paramètres mis à jour.');

        return $this->redirectToRoute('app_administrateur_billing_connect_index', ['entite' => $entite->getId()]);
    }
}