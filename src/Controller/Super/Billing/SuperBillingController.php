<?php
// src/Controller/Super/Billing/SuperBillingController.php

namespace App\Controller\Super\Billing;

use App\Entity\{Entite, Utilisateur};
use App\Entity\Billing\EntiteSubscription;
use App\Form\Super\Billing\EntiteSubscriptionType;
use App\Repository\Billing\EntiteSubscriptionRepository;
use App\Repository\Billing\PlanRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, RedirectResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;

#[IsGranted('ROLE_SUPER_ADMIN')]
#[Route('/super/{entite}/entites', name: 'app_super_billing_')]
final class SuperBillingController extends AbstractController
{

  public function __construct(
    private readonly UtilisateurEntiteManager $utilisateurEntiteManager,
  ) {}
  #[Route('/subscription', name: 'subscription_show', methods: ['GET'])]
  public function show(
    Entite $entite,
    EntiteSubscriptionRepository $subRepo,
    PlanRepository $planRepo,
  ): Response {
    /** @var Utilisateur $user */
    $user = $this->getUser();
    $sub = $subRepo->findOneBy(['entite' => $entite], ['id' => 'DESC']);

    // plans pour affichage (et CTA “changer d’offre”)
    $plans = $planRepo->findBy(['isActive' => true], ['ordre' => 'ASC']);

    return $this->render('super/billing/subscription_show.html.twig', [
      'entite' => $entite,
      'sub' => $sub,
      'plans' => $plans,


    ]);
  }

  #[Route('/subscription/new', name: 'subscription_new', methods: ['GET', 'POST'])]
  public function new(
    Entite $entite,
    Request $request,
    EntityManagerInterface $em,
    EntiteSubscriptionRepository $subRepo,
  ): Response {
    /** @var Utilisateur $user */
    $user = $this->getUser();
    // si déjà un abonnement, on redirige vers edit
    $existing = $subRepo->findOneBy(['entite' => $entite], ['id' => 'DESC']);
    if ($existing) {
      return $this->redirectToRoute('app_super_billing_subscription_edit', [
        'entite' => $entite->getId(),
        'id' => $existing->getId(),

      ]);
    }

    $sub = new EntiteSubscription();
    $sub->setEntite($entite);
    $sub->setStatus(EntiteSubscription::STATUS_INCOMPLETE);
    $sub->setIntervale('month');

    $form = $this->createForm(EntiteSubscriptionType::class, $sub, [
      'is_super' => true,
    ]);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      $sub->touch();
      $em->persist($sub);
      $em->flush();

      $this->addFlash('success', 'Abonnement créé.');
      return $this->redirectToRoute('app_super_billing_subscription_show', [
        'entite' => $entite,

      ]);
    }

    return $this->render('super/billing/subscription_edit.html.twig', [
      'entite' => $entite,
      'sub' => $sub,
      'form' => $form->createView(),
      'isNew' => true,


    ]);
  }

  #[Route('/subscription/{id}/edit', name: 'subscription_edit', methods: ['GET', 'POST'])]
  public function edit(
    Entite $entite,
    EntiteSubscription $sub,
    Request $request,
    EntityManagerInterface $em,
  ): Response {
    if ($sub->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException('Abonnement invalide pour cette entité.');
    }
    /** @var Utilisateur $user */
    $user = $this->getUser();

    $form = $this->createForm(EntiteSubscriptionType::class, $sub, [
      'is_super' => true,
    ]);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      $sub->touch();
      $em->flush();

      $this->addFlash('success', 'Abonnement mis à jour.');
      return $this->redirectToRoute('app_super_billing_subscription_show', [
        'entite' => $entite->getId(),

      ]);
    }

    return $this->render('super/billing/subscription_edit.html.twig', [
      'entite' => $entite,
      'sub' => $sub,
      'form' => $form->createView(),
      'isNew' => false,


    ]);
  }

  /**
   * Offrir une période d’essai (ex: +14 jours) :
   * - met status = trialing
   * - trialEndsAt = now + days
   * - currentPeriodEnd (optionnel) = trialEndsAt (pratique côté UI)
   */
  #[Route('/subscription/{id}/grant-trial', name: 'subscription_grant_trial', methods: ['POST'])]
  public function grantTrial(
    Entite $entite,
    EntiteSubscription $sub,
    Request $request,
    EntityManagerInterface $em,
  ): RedirectResponse {
    if ($sub->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException();
    }
    /** @var Utilisateur $user */
    $user = $this->getUser();

    $token = (string)$request->request->get('_token');
    if (!$this->isCsrfTokenValid('grant_trial_' . $sub->getId(), $token)) {
      $this->addFlash('danger', 'Token CSRF invalide.');
      return $this->redirectToRoute('app_super_billing_subscription_show', [
        'entite' => $entite,

      ]);
    }

    $days = max(1, min(365, (int)$request->request->get('days', 14)));

    $now = new \DateTimeImmutable();
    $ends = $now->modify('+' . $days . ' days');

    $sub->setStatus(EntiteSubscription::STATUS_TRIALING);
    $sub->setTrialEndsAt($ends);
    // optionnel : aligner la période sur la fin d’essai pour affichage
    $sub->setCurrentPeriodEnd($ends);
    $sub->touch();

    $em->flush();

    $this->addFlash('success', sprintf('Essai offert : +%d jours (jusqu’au %s).', $days, $ends->format('d/m/Y')));
    return $this->redirectToRoute('app_super_billing_subscription_show', [
      'entite' => $entite->getId(),

    ]);
  }

  #[Route('/subscription/{id}/cancel-local', name: 'subscription_cancel_local', methods: ['POST'])]
  public function cancelLocal(
    Entite $entite,
    EntiteSubscription $sub,
    Request $request,
    EntityManagerInterface $em,
  ): RedirectResponse {
    if ($sub->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException();
    }
    /** @var Utilisateur $user */
    $user = $this->getUser();

    $token = (string)$request->request->get('_token');
    if (!$this->isCsrfTokenValid('cancel_local_' . $sub->getId(), $token)) {
      $this->addFlash('danger', 'Token CSRF invalide.');
      return $this->redirectToRoute('app_super_billing_subscription_show', [
        'entite' => $entite->getId(),

      ]);
    }

    $sub->setStatus(EntiteSubscription::STATUS_CANCELED);
    $sub->setCanceledAt(new \DateTimeImmutable());
    $sub->touch();
    $em->flush();

    $this->addFlash('warning', 'Abonnement marqué comme annulé (local).');
    return $this->redirectToRoute('app_super_billing_subscription_show', [
      'entite' => $entite->getId(),

    ]);
  }
}
