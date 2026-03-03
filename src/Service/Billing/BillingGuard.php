<?php // src/Service/Billing/BillingGuard.php
namespace App\Service\Billing;

use App\Entity\Entite;
use App\Entity\Billing\EntiteUsageYear;
use App\Repository\Billing\EntiteUsageYearRepository;
use App\Repository\UtilisateurEntiteRepository;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;

final class BillingGuard
{
  public function __construct(
    private readonly EntitlementService $entitlement,
    private readonly UtilisateurEntiteRepository $ueRepo,
    private readonly EntiteUsageYearRepository $usageRepo,
    private readonly EntrepriseRepository $entrepriseRepo,
    private readonly EntityManagerInterface $em,
  ) {}

  /** limite users (staff inclus) */
  public function assertCanCreateUser(Entite $entite): void
  {
    $limits = $this->entitlement->limits($entite);
    $max = (int)($limits['max_utilisateurs'] ?? 0);

    if ($max === 0) return; // illimité

    $current = (int)$this->ueRepo->count(['entite' => $entite]);
    if ($current >= $max) {
      throw new \DomainException("Limite d'utilisateurs atteinte ($current/$max). Upgrade nécessaire.");
    }
  }


  public function assertCanCreateEntreprise(Entite $entite): void
  {
    $limits = $this->entitlement->limits($entite);
    $max = (int)($limits['max_entreprises'] ?? 0);
    if ($max === 0) return;

    $current = (int)$this->entrepriseRepo->count(['entite' => $entite]);
    if ($current >= $max) {
      throw new \DomainException("Limite d'entreprises atteinte ($current/$max). Upgrade nécessaire.");
    }
  }

  /** quota apprenants/an : à appeler quand tu “crées” un stagiaire réel */
  public function assertCanAddApprenantAndConsume(Entite $entite, int $by = 1): void
  {
    $limits = $this->entitlement->limits($entite);
    $max = (int)($limits['max_apprenants_an'] ?? 0);
    if ($max === 0) return; // illimité

    $year = (int)date('Y');
    $usage = $this->usageRepo->findOneBy(['entite' => $entite, 'year' => $year]);

    if (!$usage) {
      $usage = new EntiteUsageYear($entite, $year);
      $this->em->persist($usage);
    }

    $current = $usage->getApprenantsCount();
    if (($current + $by) > $max) {
      throw new \DomainException("Quota apprenants/an atteint ($current/$max). Upgrade nécessaire.");
    }

    $usage->increment($by);
  }
}
