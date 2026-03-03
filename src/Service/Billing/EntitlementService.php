<?php // src/Service/Billing/EntitlementService.php
namespace App\Service\Billing;

use App\Entity\Billing\EntiteSubscription;
use App\Entity\Entite;
use App\Repository\Billing\AddonRepository;
use App\Repository\Billing\EntiteSubscriptionRepository;
use App\Repository\UtilisateurEntiteRepository;

final class EntitlementService
{
  public function __construct(
    private readonly EntiteSubscriptionRepository $subRepo,
    private readonly AddonRepository $addonRepo,
    private UtilisateurEntiteRepository $ueRepo,
  ) {}

  public function getLatestSubscription(Entite $entite): ?EntiteSubscription
  {
    return $this->subRepo->findLatestForEntite($entite);
  }

  /**
   * Entité "active" = abonnement actif OU essai en cours non expiré.
   */
  public function isEntiteActive(Entite $entite): bool
  {
    $sub = $this->subRepo->findLatestForEntite($entite);
    if (!$sub) {
      return false;
    }

    $now = new \DateTimeImmutable();

    // 1) Abonnement actif
    if ($sub->getStatus() === EntiteSubscription::STATUS_ACTIVE || $sub->getStatus() === 'active') {
      // Si tu relies Stripe : une période active doit être >= now
      $end = $sub->getCurrentPeriodEnd();
      if ($end instanceof \DateTimeImmutable && $end < $now) {
        return false;
      }
      return true;
    }

    // 2) Essai
    if ($sub->getStatus() === EntiteSubscription::STATUS_TRIALING || $sub->getStatus() === 'trialing') {
      $ends = $sub->getTrialEndsAt();
      // trial_ends_at absent = on considère inactif (plus safe)
      return $ends instanceof \DateTimeImmutable && $ends > $now;
    }

    // 3) tout le reste : past_due / canceled / incomplete...
    return false;
  }


  /**
   * Retourne les limites et features calculées.
   * Si pas de plan => tu peux décider de donner des limites "trial" par défaut.
   */
  public function limits(Entite $entite): array
  {
    $sub = $this->getLatestSubscription($entite);

    if (!$sub) {
      // pas d’abonnement = inactif (ou limites ultra faibles)
      return [
        'max_apprenants_an' => 0,
        'max_utilisateurs' => 0,
        'max_formateurs' => 0,
        'max_entreprises' => 0,
        'max_prospects' => 0,
        'support_prioritaire' => false,
        'is_trial' => false,
      ];
    }

    $plan = $sub->getPlan();

    // Si pas de plan pendant l’essai, tu peux donner un mini-pack trial,
    // ou forcer la sélection de plan sur /tarifs avant onboarding.
    if (!$plan) {
      return [
        'max_apprenants_an' => 50,
        'max_utilisateurs'  => 1,
        'max_formateurs'    => 1,
        'max_entreprises'   => 1,
        'max_prospects'     => 50,
        'support_prioritaire' => false,
        'is_trial' => $sub->getStatus() === EntiteSubscription::STATUS_TRIALING,
      ];
    }

    $extraApprenants = 0;
    foreach (($sub->getAddons() ?? []) as $addonCode) {
      $addon = $this->addonRepo->findOneBy(['code' => $addonCode, 'isActive' => true]);
      if ($addon?->getExtraApprenantsAn()) $extraApprenants += $addon->getExtraApprenantsAn();
    }

    return [
      'max_apprenants_an' => $plan->getMaxApprenantsAn() + $extraApprenants,
      'max_utilisateurs'  => $plan->getMaxUtilisateurs(),
      'max_formateurs'    => $plan->getMaxFormateurs(),
      'max_entreprises'   => $plan->getMaxEntreprises(),
      'max_prospects'     => $plan->getMaxProspects(),
      'support_prioritaire' => $plan->isSupportPrioritaire(),
      'is_trial' => $sub->getStatus() === EntiteSubscription::STATUS_TRIALING,
    ];
  }


  public function hasFeature(Entite $entite, string $feature): bool
  {
    $limits = $this->limits($entite);

    return match ($feature) {
      'support_prioritaire' => (bool)($limits['support_prioritaire'] ?? false),
      default => false,
    };
  }


  public function limit(string $key, Entite $entite): ?int
  {
    $limits = $this->limits($entite);
    $v = $limits[$key] ?? null;
    if ($v === 0) return null; // null = illimité
    return is_int($v) ? $v : null;
  }

  public function canCreateUser(Entite $entite, int $currentUsers): bool
  {
    $limit = $this->limit('max_utilisateurs', $entite);
    return $limit === null || $currentUsers < $limit;
  }

  public function canAddApprenantThisYear(Entite $entite, int $currentApprenantsThisYear): bool
  {
    $limit = $this->limit('max_apprenants_an', $entite);
    return $limit === null || $currentApprenantsThisYear < $limit;
  }


  public function assertCanAddRole(Entite $entite, string $tenantRole): void
  {
    $sub = $this->subRepo->findActiveForEntite($entite); // à implémenter
    $plan = $sub?->getPlan();
    if (!$plan) return; // ou throw si obligatoire

    $limit = $plan->getLimitFor($tenantRole);
    if ($limit === 0) return; // illimité

    $used = $this->ueRepo->countActiveByRole($entite, $tenantRole);
    if ($used >= $limit) {
      throw new \DomainException(sprintf('Quota atteint pour %s (%d/%d).', $tenantRole, $used, $limit));
    }
  }
}
