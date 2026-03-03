<?php
// src/Controller/Public/PricingStartController.php

namespace App\Controller\Public;

use App\Entity\Billing\EntiteSubscription;
use App\Entity\Billing\Plan;
use App\Entity\Utilisateur;
use App\Repository\Billing\EntiteSubscriptionRepository;
use App\Service\Tenant\TenantContext; // si tu utilises mon service (recommandé)
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;

final class PricingStartController extends AbstractController
{
  #[Route('/pricing/start-trial', name: 'app_pricing_start_trial', methods: ['POST'])]
  public function startTrial(
    Request $request,
    EntityManagerInterface $em,
    EntiteSubscriptionRepository $subRepo,
    TenantContext $tenant,                 // ✅ important
    int $billingAppTrialDays = 90,
  ): Response {
    // 1) On récupère le choix pricing
    $planCode = (string) $request->request->get('plan', '');
    $interval = (string) $request->request->get('interval', 'year'); // month|year
    $addons   = (array)  $request->request->all('addons');           // addons[] codes

    // 2) On stocke TOUJOURS en session (même si connecté)
    $request->getSession()->set('pricing_selected_plan', $planCode);
    $request->getSession()->set('pricing_selected_addons', $addons);
    $request->getSession()->set('pricing_selected_interval', $interval);

    // 3) Pas connecté => login, puis onboarding
    if (!$this->getUser()) {
      // force la redirection après login vers onboarding
      $request->getSession()->set('_security.main.target_path', $this->generateUrl('app_onboarding'));
      return $this->redirectToRoute('app_login');
    }

    /** @var Utilisateur $user */
    $user = $this->getUser();

    // 4) Entité courante = plateforme (#1) OU pas d'entité “valide” => onboarding obligatoire
    $currentEntite = $tenant->getCurrentEntiteForUser($user);

    if (!$currentEntite || $tenant->isPlatformEntite($currentEntite)) {
      return $this->redirectToRoute('app_onboarding');
    }

    // 5) On est sur une entité client => créer le trial si pas de subscription
    $sub = $subRepo->findLatestForEntite($currentEntite);

    if (!$sub) {
      $sub = new EntiteSubscription();
      $sub->setEntite($currentEntite);
      $sub->setStatus(EntiteSubscription::STATUS_TRIALING);
      $sub->setIntervale($interval ?: 'year');
      $sub->setAddons(array_values(array_map('strval', $addons)));
      $sub->setTrialEndsAt((new \DateTimeImmutable())->modify('+' . $billingAppTrialDays . ' days'));
      $sub->touch();

      if ($planCode) {
        $plan = $em->getRepository(Plan::class)->findOneBy([
          'code' => strtoupper($planCode),
          'isActive' => true,
        ]);
        if ($plan) {
          $sub->setPlan($plan);
        }
      }

      $em->persist($sub);
      $em->flush();

      // Optionnel : nettoyage session après création effective
      $request->getSession()->remove('pricing_selected_plan');
      $request->getSession()->remove('pricing_selected_addons');
      $request->getSession()->remove('pricing_selected_interval');
    }

    // 6) Redirect billing sur l'entité client
    return $this->redirectToRoute('app_administrateur_billing', [
      'entite' => $currentEntite->getId(),
    ]);
  }
}
