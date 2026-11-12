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
#[IsGranted(\App\Security\Permission\TenantPermission::BILLING_MANAGE, subject: 'entite')]
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

        $accountAvailable = false;
        if ($connect->getStripeAccountId()) {
            try {
                $account = $this->stripeFactory->client()->accounts->retrieve($connect->getStripeAccountId(), []);
                $accountAvailable = true;
                $connect->setDetailsSubmitted((bool) $account->details_submitted);
                $connect->setChargesEnabled((bool) $account->charges_enabled);
                $connect->setPayoutsEnabled((bool) $account->payouts_enabled);
            } catch (\Stripe\Exception\ApiErrorException $error) {
                $this->addFlash('warning', 'Le compte enregistré ne peut pas être vérifié avec la configuration Stripe actuelle. Après un passage du test à la production, démarrez à nouveau l’inscription.');
            }
        }

        return $this->render('administrateur/billing/connect/index.html.twig', [
            'entite' => $entite,
            'connect' => $connect,
            'stripeMode' => $this->stripeFactory->mode(),
            'accountAvailable' => $accountAvailable,
        ]);
    }

    #[Route('/start', name: 'start', methods: ['POST'])]
    public function start(Entite $entite, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('connect_start', (string)$request->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF invalide.');
        }

        $connect = $entite->getConnect() ?? (new EntiteConnect())->setEntite($entite);

        try {
        $stripe = $this->stripeFactory->client();

        $createAccount = !$connect->getStripeAccountId();
        if (!$createAccount) {
            try {
                $stripe->accounts->retrieve($connect->getStripeAccountId(), []);
            } catch (\Stripe\Exception\InvalidRequestException $error) {
                // Only a missing resource permits replacement; never replace on auth/network failures.
                if ($error->getStripeCode() !== 'resource_missing') {
                    throw $error;
                }
                $createAccount = true;
            }
        }

        if ($createAccount) {
            $account = $stripe->accounts->create([
                'type' => 'standard',
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
            ], [
                'idempotency_key' => 'connect-onboarding-'.$entite->getId().'-'.($connect->getStripeAccountId() ?? 'initial'),
            ]);

            $connect->setStripeAccountId($account->id)
                ->setDetailsSubmitted(false)
                ->setChargesEnabled(false)
                ->setPayoutsEnabled(false)
                ->touch();
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
        } catch (\Stripe\Exception\ApiErrorException $error) {
            return $this->stripeFailure($entite, $error);
        }
    }

    #[Route('/refresh', name: 'refresh', methods: ['GET'])]
    public function refresh(Entite $entite): Response
    {
        $connect = $entite->getConnect();
        if (!$connect || !$connect->getStripeAccountId()) {
            $this->addFlash('warning', 'Aucun compte Stripe lié.');
            return $this->redirectToRoute('app_administrateur_billing_connect_index', ['entite' => $entite->getId()]);
        }

        try {
        $stripe = $this->stripeFactory->client();
        $account = $stripe->accounts->retrieve($connect->getStripeAccountId(), []);

        $connect->setDetailsSubmitted((bool)$account->details_submitted);
        $connect->setChargesEnabled((bool)$account->charges_enabled);
        $connect->setPayoutsEnabled((bool)$account->payouts_enabled);
        $connect->touch();

        $this->em->flush();

        } catch (\Stripe\Exception\ApiErrorException $error) {
            return $this->stripeFailure($entite, $error);
        }
        $this->addFlash('success', 'Statut Stripe mis à jour.');
        return $this->redirectToRoute('app_administrateur_billing_connect_index', ['entite' => $entite->getId()]);
    }
    
    private function stripeFailure(Entite $entite, \Stripe\Exception\ApiErrorException $error): Response
    {
        $message = $error instanceof \Stripe\Exception\PermissionException
            ? 'Stripe refuse l’accès à ce compte connecté. Vérifiez son rattachement à la plateforme et les autorisations de la clé API.'
            : 'Stripe n’a pas pu ouvrir ou actualiser la connexion. Vérifiez la configuration du compte et réessayez.';
        $requestId = $error->getRequestId();
        if (is_string($requestId) && preg_match('/^req_[a-zA-Z0-9]+$/', $requestId)) {
            $message .= ' Référence Stripe : '.$requestId.'.';
        }
        $this->addFlash('warning', $message);
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
