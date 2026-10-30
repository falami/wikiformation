<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\{Entite, Utilisateur, UtilisateurEntite};
use App\Repository\{EntiteRepository, UtilisateurEntiteRepository};
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/** Protect ownership independently of the administrator permissions it grants. */
final class TenantAccountProtection
{
    public const ACCOUNT_MESSAGE = 'Compte dirigeant protégé : seul son titulaire peut le modifier. Un administrateur ne peut pas modifier ses accès ou ses identifiants.';
    public const ROLE_MESSAGE = 'Le rôle Dirigeant est protégé et ne peut pas être retiré depuis cet organisme. Son accès doit être conservé.';

    public function __construct(
        private readonly UtilisateurEntiteRepository $memberships,
        private readonly EntiteRepository $entities,
    ) {}

    public function isProtectedMembership(UtilisateurEntite $membership): bool
    {
        return $membership->hasRole(UtilisateurEntite::TENANT_DIRIGEANT)
            || $this->isCreator($membership->getUtilisateur(), $membership->getEntite());
    }

    public function isProtectedIdentity(Utilisateur $user): bool
    {
        if ($user->isSuperAdmin()) return true;
        if (!$user->getId()) return false;
        // Login details are shared across tenants, even if the local role is only trainee.
        if ($this->entities->count(['createur' => $user]) > 0) return true;
        foreach ($this->memberships->findBy(['utilisateur' => $user]) as $membership) {
            if ($membership->hasRole(UtilisateurEntite::TENANT_DIRIGEANT)) return true;
        }
        return false;
    }

    public function canEditAccount(Utilisateur $actor, Utilisateur $target): bool
    {
        return $actor->isSuperAdmin() || $actor === $target
            || ($actor->getId() !== null && $actor->getId() === $target->getId())
            || !$this->isProtectedIdentity($target);
    }

    public function assertCanEditAccount(Utilisateur $actor, Utilisateur $target): void
    {
        if (!$this->canEditAccount($actor, $target)) throw new AccessDeniedException(self::ACCOUNT_MESSAGE);
    }

    /** Existing owners cannot lose their administrative access, even by self-edit. */
    public function transitionError(Utilisateur $actor, UtilisateurEntite $membership, array $roles, string $status): ?string
    {
        // Platform maintenance remains available for an explicitly managed transfer/recovery.
        if ($actor->isSuperAdmin() || !$this->isProtectedMembership($membership)) return null;
        if ($status !== UtilisateurEntite::STATUS_ACTIVE) return self::ROLE_MESSAGE;
        if ($membership->hasRole(UtilisateurEntite::TENANT_DIRIGEANT)
            && !in_array(UtilisateurEntite::TENANT_DIRIGEANT, $roles, true)) return self::ROLE_MESSAGE;
        if (!array_intersect([UtilisateurEntite::TENANT_DIRIGEANT, UtilisateurEntite::TENANT_ADMIN], $roles)) return self::ROLE_MESSAGE;
        return null;
    }

    private function isCreator(?Utilisateur $user, ?Entite $entity): bool
    {
        return $user && $entity && $entity->getCreateur()
            && ($entity->getCreateur() === $user || ($user->getId() !== null && $entity->getCreateur()->getId() === $user->getId()));
    }
}
