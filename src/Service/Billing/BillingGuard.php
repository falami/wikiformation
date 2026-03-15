<?php

namespace App\Service\Billing;

use App\Entity\Billing\EntiteUsageYear;
use App\Entity\Entite;
use App\Entity\UtilisateurEntite;
use App\Exception\BillingQuotaExceededException;
use App\Repository\Billing\EntiteUsageYearRepository;
use App\Repository\EntrepriseRepository;
use App\Repository\UtilisateurEntiteRepository;
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

    public function assertCanCreateUser(Entite $entite): void
    {
        $max = $this->entitlement->limit('max_utilisateurs', $entite);
        if ($max === null) {
            return;
        }

        $current = $this->ueRepo->countBillableActiveForEntite($entite);
        if ($current >= $max) {
            throw new BillingQuotaExceededException(
                'max_utilisateurs',
                $current,
                $max,
                sprintf("Limite de comptes actifs atteinte (%d/%d).", $current, $max)
            );
        }
    }

    public function assertCanCreateEntreprise(Entite $entite): void
    {
        $limits = $this->entitlement->limits($entite);
        $max = (int)($limits['max_entreprises'] ?? 0);
        if ($max === 0) {
            return;
        }

        $current = (int)$this->entrepriseRepo->count(['entite' => $entite]);
        if ($current >= $max) {
            throw new BillingQuotaExceededException(
                'max_entreprises',
                $current,
                $max,
                sprintf("Limite d'entreprises atteinte (%d/%d).", $current, $max)
            );
        }
    }

    /**
     * Vérifie une transition d’un UtilisateurEntite :
     * - création
     * - changement de rôles
     * - passage inactif -> actif
     */
    public function assertCanTransitionUtilisateurEntite(
        Entite $entite,
        array $currentRoles,
        string $currentStatus,
        array $futureRoles,
        string $futureStatus,
        ?int $excludeUtilisateurEntiteId = null
    ): void {
        $futureRoles = $this->filterManagedRoles($futureRoles);
        $currentRoles = $this->filterManagedRoles($currentRoles);

        $willBeActive = $futureStatus === UtilisateurEntite::STATUS_ACTIVE;
        if (!$willBeActive) {
            return;
        }

        $wasActive = $currentStatus === UtilisateurEntite::STATUS_ACTIVE;

        $wasBillableUser = $wasActive && $this->containsBillableUserRole($currentRoles);
        $willBeBillableUser = $this->containsBillableUserRole($futureRoles);

        // 1) quota global comptes actifs "utilisateurs"
        // On ne contrôle max_utilisateurs QUE si la transition crée réellement
        // un compte utilisateur facturable.
        $maxUsers = $this->entitlement->limit('max_utilisateurs', $entite);
        if ($maxUsers !== null && $willBeBillableUser && !$wasBillableUser) {
            $currentActive = $this->ueRepo->countBillableActiveForEntite($entite, $excludeUtilisateurEntiteId);

            if (($currentActive + 1) > $maxUsers) {
                throw new BillingQuotaExceededException(
                    'max_utilisateurs',
                    $currentActive,
                    $maxUsers,
                    sprintf("Limite de comptes actifs atteinte (%d/%d).", $currentActive, $maxUsers)
                );
            }
        }

        // 2) quotas par rôle
        foreach ($futureRoles as $role) {
            $alreadyCounted = $wasActive && in_array($role, $currentRoles, true);
            if ($alreadyCounted) {
                continue;
            }

            // On ignore volontairement TENANT_STAGIAIRE ici :
            // il est géré par max_apprenants_an, pas par les seats utilisateurs.
            if ($role === UtilisateurEntite::TENANT_STAGIAIRE) {
                continue;
            }

            $limit = $this->entitlement->getSeatLimit($entite, $role);
            if ($limit === null) {
                continue; // illimité
            }

            $used = $this->ueRepo->countActiveByRoleForEntite($entite, $role, $excludeUtilisateurEntiteId);
            if (($used + 1) > $limit) {
                $label = $this->entitlement->getSeatLabel($role);

                throw new BillingQuotaExceededException(
                    $role,
                    $used,
                    $limit,
                    sprintf("Limite atteinte pour les comptes actifs « %s » (%d/%d).", $label, $used, $limit)
                );
            }
        }
    }

    public function assertCanAddApprenantAndConsume(Entite $entite, int $by = 1): void
    {
        $max = $this->entitlement->limit('max_apprenants_an', $entite);
        if ($max === null) {
            return;
        }

        $year = (int) date('Y');
        $usage = $this->usageRepo->findOneBy([
            'entite' => $entite,
            'year' => $year,
        ]);

        if (!$usage) {
            $usage = new EntiteUsageYear($entite, $year);
            $this->em->persist($usage);
        }

        $current = $usage->getApprenantsCount();
        if (($current + $by) > $max) {
            throw new BillingQuotaExceededException(
                'max_apprenants_an',
                $current,
                $max,
                sprintf("Quota apprenants/an atteint (%d/%d).", $current, $max)
            );
        }

        $usage->increment($by);
    }

    public function assertCanAddApprenantIfNew(Entite $entite, bool $isNew): void
    {
        if (!$isNew) {
            return;
        }

        $this->assertCanAddApprenantAndConsume($entite, 1);
    }

    private function filterManagedRoles(array $roles): array
    {
        $allowed = [
            UtilisateurEntite::TENANT_STAGIAIRE,
            UtilisateurEntite::TENANT_FORMATEUR,
            UtilisateurEntite::TENANT_ENTREPRISE,
            UtilisateurEntite::TENANT_OPCO,
            UtilisateurEntite::TENANT_OF,
            UtilisateurEntite::TENANT_ADMIN,
            UtilisateurEntite::TENANT_COMMERCIAL,
            UtilisateurEntite::TENANT_DIRIGEANT,
        ];

        $roles = array_map(static fn($r) => trim((string) $r), $roles);
        $roles = array_values(array_unique(array_filter(
            $roles,
            static fn($r) => in_array($r, $allowed, true)
        )));

        return $roles;
    }

    private function containsBillableUserRole(array $roles): bool
    {
        foreach ($roles as $role) {
            if ($this->isBillableUserRole($role)) {
                return true;
            }
        }

        return false;
    }

    private function isBillableUserRole(string $role): bool
    {
        return in_array($role, [
            UtilisateurEntite::TENANT_ADMIN,
            UtilisateurEntite::TENANT_DIRIGEANT,
            UtilisateurEntite::TENANT_FORMATEUR,
            UtilisateurEntite::TENANT_COMMERCIAL,
            UtilisateurEntite::TENANT_OF,
        ], true);
    }
}