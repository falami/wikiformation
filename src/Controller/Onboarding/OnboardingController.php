<?php

namespace App\Controller\Onboarding;

use App\Entity\{Entite, UtilisateurEntite, Utilisateur};
use App\Entity\Billing\{EntiteSubscription, Plan};
use App\Form\Onboarding\EntiteOnboardingType;
use App\Repository\Billing\EntiteSubscriptionRepository;
use App\Service\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class OnboardingController extends AbstractController
{
    #[Route('/onboarding', name: 'app_onboarding', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        EntityManagerInterface $em,
        EntiteSubscriptionRepository $subRepo,
        TenantContext $tenant,
        int $billingAppTrialDays,
        \App\Service\Partner\Referrals $referrals,
    ): Response {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        /** @var Utilisateur|null $user */
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->redirectToRoute('app_login');
        }

        $plans = $em->getRepository(Plan::class)->findPublicOffers();

        $entite = new Entite();
        $form = $this->createForm(EntiteOnboardingType::class, $entite);
        if (!$request->isMethod('POST')) {
            $selectedCode = strtoupper(trim((string) $request->getSession()->get('pricing_selected_plan', '')));
            // Restore the catalogue's actual code, including legacy code casing.
            foreach ($plans as $plan) {
                if (strtoupper(trim($plan->getCode())) === $selectedCode) {
                    $form->get('planCode')->setData($plan->getCode());
                    break;
                }
            }
            $form->get('interval')->setData('month');
        }
        if (!$request->isMethod('POST')) $form->get('referralCode')->setData((string)$request->getSession()->get('affiliate_code', ''));
        $form->handleRequest($request);
        $partner = null;
        if ($form->isSubmitted()) {
            $code = trim((string)$form->get('referralCode')->getData());
            if ($code !== '') {
                $partner = $referrals->byCode($code);
                if (!$partner || $partner->user === $user) $form->get('referralCode')->addError(new FormError('Code apporteur invalide ou auto-parrainage interdit.'));
            }
        }

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile|null $logoFile */
            $logoFile = $form->get('logoFile')->getData();

            if ($logoFile) {
                $slugger = new AsciiSlugger();
                $safe = $slugger->slug(pathinfo($logoFile->getClientOriginalName(), PATHINFO_FILENAME))->lower();
                $ext = $logoFile->guessExtension() ?: $logoFile->getClientOriginalExtension() ?: 'bin';
                $filename = sprintf('logo-%s-%s.%s', $safe, bin2hex(random_bytes(6)), $ext);

                $targetDir = $this->getParameter('logo_entite');
                $logoFile->move($targetDir, $filename);

                $entite->setLogo($filename);
            }

            $planCode = (string) $form->get('planCode')->getData();
            $interval = (string) $form->get('interval')->getData() ?: 'month';

            $plan = null;
            if ($planCode !== '') {
                $plan = $em->getRepository(Plan::class)->findOneBy([
                    'code' => $planCode,
                    'isActive' => true,
                ]);
            }

            if (!$plan || !$plan->isAvailableForNewSubscription($interval)) {
                $form->get('planCode')->addError(new FormError('Choisissez un plan pour continuer.'));
                return $this->render('onboarding/index.html.twig', [
                    'form' => $form,
                    'trialDays' => $billingAppTrialDays,
                    'plans' => $plans,
                ]);
            }

            $conn = $em->getConnection();
            $conn->beginTransaction();

            try {
                // 1) Entité
                $entite->setCreateur($user);
                $entite->setDateCreation(new \DateTimeImmutable());
                $entite->setPublic(false);
                $entite->setIsActive(true);
                $entite->setSlug('pending-' . bin2hex(random_bytes(6)));

                $entite->setCouleurPrincipal($entite->getCouleurPrincipal() ?: '#233342');
                $entite->setCouleurSecondaire($entite->getCouleurSecondaire() ?: '#0d6efd');
                $entite->setCouleurTertiaire($entite->getCouleurTertiaire() ?: '#F0F0F0');
                $entite->setCouleurQuaternaire($entite->getCouleurQuaternaire() ?: '#0f2336');

                $em->persist($entite);

                // 2) Membership admin
                $ue = new UtilisateurEntite();
                $ue->setUtilisateur($user);
                $ue->setEntite($entite);
                $ue->setRoles([UtilisateurEntite::TENANT_ADMIN]);
                $ue->setCreateur($user);
                $ue->ensureCouleur();
                $em->persist($ue);

                $em->flush();

                $entiteId = $entite->getId();
                if (null === $entiteId) {
                    throw new \RuntimeException('Impossible de créer l’entité (ID non généré).');
                }

                $entite->setSlug('E' . $entiteId);
                $user->setEntite($entite);

                $em->flush();

                // 3) Subscription trial
                $session = $request->getSession();
                $selectedAddons = (array) $session->get('pricing_selected_addons', []);

                $now = new \DateTimeImmutable();

                $trialEndsAt = $now->modify('+' . $billingAppTrialDays . ' days');

                $sub = new EntiteSubscription();
                $sub->setEntite($entite);
                $sub->setPlan($plan);
                $sub->setStatus(EntiteSubscription::STATUS_TRIALING);
                $sub->setIntervale($interval);
                $sub->setAddons(array_values(array_map('strval', $selectedAddons)));
                $sub->setStartedAt($now);
                $sub->setTrialEndsAt($trialEndsAt);
                $sub->setCurrentPeriodEnd($trialEndsAt);
                $sub->setLimitsSnapshot($plan->getNormalizedSeatLimits());
                $sub->touch();

                $em->persist($sub);

                $session->remove('pricing_selected_addons');
                $session->remove('pricing_selected_plan');
                $session->remove('pricing_selected_interval');

                $em->flush();

                if ($partner) { $referrals->attach($partner, $entite, $user, 'onboarding'); $em->flush(); }
                $session->remove('affiliate_code');

                // 4) Tenant courant
                $tenant->setCurrentEntite($user, $entite);
                $em->flush();

                $conn->commit();
            } catch (\Throwable $e) {
                $conn->rollBack();
                throw $e;
            }

            return $this->redirectToRoute('app_administrateur_billing', [
                'entite' => $entite->getId(),
            ]);
        }

        return $this->render('onboarding/index.html.twig', [
            'form' => $form,
            'trialDays' => $billingAppTrialDays,
            'plans' => $plans,
        ]);
    }
}
