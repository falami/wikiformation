<?php // src/EventSubscriber/BillingAccessSubscriber.php
namespace App\EventSubscriber;

use App\Entity\{Entite, Utilisateur};
use App\Repository\{EntiteRepository, UtilisateurEntiteRepository};
use App\Service\Billing\EntitlementService;
use App\Service\Tenant\TenantContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use App\Repository\Billing\EntiteSubscriptionRepository;
use App\Entity\Billing\EntiteSubscription;

final class BillingAccessSubscriber implements EventSubscriberInterface
{
  public function __construct(
    private readonly TokenStorageInterface $tokenStorage,
    private readonly EntitlementService $entitlement,
    private readonly RouterInterface $router,
    private readonly EntiteSubscriptionRepository $subRepo,
    private readonly EntiteRepository $entiteRepo,
    private readonly UtilisateurEntiteRepository $memberships,
    private readonly TenantContext $tenant,
  ) {}

  public static function getSubscribedEvents(): array
  {
    // après le firewall, on a le user chargé
    return [KernelEvents::REQUEST => ['onKernelRequest', -10]];
  }

  public function onKernelRequest(RequestEvent $event): void
  {
    if (!$event->isMainRequest()) return;

    $request = $event->getRequest();
    $route   = (string) $request->attributes->get('_route');

    if ($route === '_wdt' || $route === '_profiler') return;

    // ✅ 1) On ne contrôle l'abonnement QUE sur les routes "privées"
    $protectedPrefixes = [
      'app_administrateur_',
      'app_stagiaire_',
      'app_formateur_',
      'app_entreprise_',
      'app_commercial_',
      'app_opco_',
      'app_of_',
    ];

    // Some private endpoints have historical names such as app_formation_quick_create.
    // Cover their tenant-scoped URL as well, so aliases cannot bypass the subscription.
    $isProtected = $request->attributes->has('entite') && (bool) preg_match(
      '~^/(?:[^/]+/)?administrateur/\d+(?:/|$)~',
      $request->getPathInfo()
    );
    foreach ($protectedPrefixes as $prefix) {
      if (str_starts_with($route, $prefix)) {
        $isProtected = true;
        break;
      }
    }

    // ✅ Si la route est publique => on laisse passer
    if (!$isProtected) return;

    // ✅ 2) Toujours autoriser tarifs + billing (pour pouvoir souscrire)
    $allow = [
      'app_login',
      'app_logout',
      'app_public_pricing',
      'app_administrateur_billing',
      'app_pricing_start_trial',
      'app_billing_change_preview',
      'app_billing_change_apply',
      'app_billing_checkout',
      'app_billing_success',
      'app_billing_cancel',
      'app_billing_portal',
      'app_stripe_webhook',
    ];
    if (\in_array($route, $allow, true)) return;

    $token = $this->tokenStorage->getToken();
    if (!$token) return;

    $user = $token->getUser();
    if (!$user instanceof Utilisateur) return;
    // Platform support must be able to inspect expired organisms. Actual quota
    // writes remain checked by BillingQuotaSubscriber, including for super admins.
    if ($user->isSuperAdmin()) return;

    // The URL selects the organism. The saved user/session organism can still refer
    // to another one until the controller runs, including after a workspace switch.
    if ($request->attributes->has('entite')) {
      $routeEntite = $request->attributes->get('entite');
      $entite = $routeEntite instanceof Entite ? $routeEntite :
        ((is_int($routeEntite) || (is_string($routeEntite) && ctype_digit($routeEntite))) ? $this->entiteRepo->find($routeEntite) : null);
    } else {
      $entite = $request->hasSession() ? $this->tenant->getCurrentEntiteForUser($user) : $user->getEntite();
    }
    if (!$entite) return;
    // Let the controller's authorization checks handle foreign or inactive memberships.
    if (!$this->memberships->userHasEntite($user, $entite)) return;

    if ($this->entitlement->isEntiteActive($entite)) return;

    if (str_starts_with($route, 'app_commercial_') || str_starts_with($route, 'app_opco_')) {
      throw new AccessDeniedHttpException('L’abonnement de cet organisme doit être renouvelé par son administrateur.');
    }

    if ($request->hasSession()) {
      /** @var \Symfony\Component\HttpFoundation\Session\Session $session */
      $session = $request->getSession();

      $sub = $this->subRepo->findLatestForEntite($entite);
      $session->getFlashBag()->add('billing_expired', $this->billingMessage($sub));
    }

    $event->setResponse(new RedirectResponse(
      $this->router->generate('app_public_pricing')
    ));
  }
  // src/EventSubscriber/BillingAccessSubscriber.php

  private function billingMessage(?EntiteSubscription $sub): string
  {
    $now = new \DateTimeImmutable();

    if (!$sub) {
      return "Aucun abonnement n’est actif. Merci de souscrire à une offre pour continuer à utiliser WikiFormation.";
    }

    $st = $sub->getStatus();
    $trialEnd = $sub->getTrialEndsAt();
    $periodEnd = $sub->getCurrentPeriodEnd();

    $fmt = fn(?\DateTimeImmutable $d) => $d ? $d->format('d/m/Y') : null;

    // Essai
    if ($st === EntiteSubscription::STATUS_TRIALING) {
      if ($trialEnd && $trialEnd <= $now) {
        return "Votre période d’essai est terminée. Merci de souscrire à une offre pour continuer à utiliser WikiFormation.";
      }
      if ($trialEnd) {
        return "Votre période d’essai est en cours jusqu’au {$fmt($trialEnd)}. Choisissez une offre pour continuer sans interruption.";
      }
      return "Votre période d’essai est en cours. Choisissez une offre pour continuer sans interruption.";
    }

    // Paiement en attente / impayé
    if ($st === EntiteSubscription::STATUS_PAST_DUE) {
      return "Votre paiement est en attente. Merci de mettre à jour votre moyen de paiement pour conserver l’accès à WikiFormation.";
    }
    if ($st === EntiteSubscription::STATUS_UNPAID) {
      return "Votre abonnement est impayé. Merci de régulariser votre paiement pour réactiver l’accès à WikiFormation.";
    }

    // Annulé / fin d’accès
    if ($st === EntiteSubscription::STATUS_CANCELED) {
      return "Votre abonnement est terminé. Merci de souscrire à une offre pour continuer à utiliser WikiFormation.";
    }

    // Incomplete
    if ($st === EntiteSubscription::STATUS_INCOMPLETE) {
      return "Votre souscription n’est pas finalisée. Merci de finaliser le paiement pour activer votre abonnement.";
    }

    // Fallback
    if ($periodEnd && $periodEnd <= $now) {
      return "Votre accès a expiré. Merci de souscrire à une offre pour continuer à utiliser WikiFormation.";
    }

    return "Votre accès est restreint. Merci de souscrire à une offre pour continuer à utiliser WikiFormation.";
  }
}
