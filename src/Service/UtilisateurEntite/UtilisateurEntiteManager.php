<?php

namespace App\Service\UtilisateurEntite;

use App\Entity\Entite;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurEntite;
use App\Repository\UtilisateurEntiteRepository;
use App\Service\Doctrine\DoctrineManager;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class UtilisateurEntiteManager
{
    public function __construct(
        private readonly DoctrineManager $doctrineManager,
        private readonly UtilisateurEntiteRepository $utilisateurEntiteRepository,
        private readonly Security $security,
    ) {}

    // =====================
    // CRUD
    // =====================
    public function create(UtilisateurEntite $utilisateurEntite): bool
    {
        return $this->doctrineManager->saveInDb($utilisateurEntite, 'Problème à la création du lien utilisateur/entité');
    }

    public function edit(UtilisateurEntite $utilisateurEntite): bool
    {
        return $this->doctrineManager->saveInDb($utilisateurEntite, 'Erreur à la modification du lien utilisateur/entité');
    }

    public function delete(UtilisateurEntite $utilisateurEntite): bool
    {
        return $this->doctrineManager->delete($utilisateurEntite, 'Erreur à la suppression du lien utilisateur/entité');
    }

    public function getRepository(): UtilisateurEntiteRepository
    {
        return $this->utilisateurEntiteRepository;
    }

    // =====================
    // Helpers user / membership
    // =====================

    public function getCurrentUser(): ?Utilisateur
    {
        $user = $this->security->getUser();
        return $user instanceof Utilisateur ? $user : null;
    }

    /**
     * Retourne le lien pivot (UtilisateurEntite) entre user courant et entité.
     * null si pas rattaché / pas actif.
     */
    public function getUserEntiteLink(Entite $entite, bool $onlyActive = true): ?UtilisateurEntite
    {
        $user = $this->getCurrentUser();
        if (!$user) return null;

        $link = $this->utilisateurEntiteRepository->findOneBy([
            'utilisateur' => $user,
            'entite'      => $entite,
        ]);

        if (!$link instanceof UtilisateurEntite) {
            return null;
        }

        if ($onlyActive && !$link->isActive()) {
            return null;
        }

        return $link;
    }

    /**
     * Refuse l'accès si l'utilisateur n'est pas rattaché à l'entité (et actif).
     * Retourne le link (pratique pour le contrôleur).
     */
    public function denyAccessUnlessMember(Entite $entite): UtilisateurEntite
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            throw new AccessDeniedException('Vous devez être connecté.');
        }

        $link = $this->getUserEntiteLink($entite, true);
        if (!$link) {
            throw new AccessDeniedException("Vous n'avez pas accès à cet organisme.");
        }

        return $link;
    }

    /**
     * ✅ Recommandé : refuse l'accès si le Voter ne donne pas la permission.
     * Ex: denyAccessUnlessGrantedToEntite($entite, TenantPermission::BILLING_MANAGE)
     */
    public function denyAccessUnlessGrantedToEntite(Entite $entite, string $permission): void
    {
        if (!$this->security->isGranted($permission, $entite)) {
            throw new AccessDeniedException("Droits insuffisants sur cet organisme.");
        }
    }

    /**
     * Alternative : contrôle direct sur les rôles JSON du membership.
     * - $requiredRole : ex UtilisateurEntite::TENANT_ADMIN
     * - $allowAdminOverride : si true, TENANT_ADMIN / TENANT_DIRIGEANT suffisent toujours
     */
    public function denyAccessUnlessHasTenantRole(
        Entite $entite,
        string $requiredRole,
        bool $allowAdminOverride = true
    ): UtilisateurEntite {
        $link = $this->denyAccessUnlessMember($entite);

        if ($allowAdminOverride && $link->isTenantAdmin()) {
            return $link;
        }

        if (!$link->hasRole($requiredRole)) {
            throw new AccessDeniedException("Droits insuffisants sur cet organisme.");
        }

        return $link;
    }

    // =====================
    // Bool helpers
    // =====================

    public function userHasAccessToEntite(Entite $entite): bool
    {
        return (bool) $this->getUserEntiteLink($entite, true);
    }

    public function userIsGrantedOnEntite(Entite $entite, string $permission): bool
    {
        return $this->security->isGranted($permission, $entite);
    }

    public function userHasTenantRole(Entite $entite, string $role, bool $adminOverride = true): bool
    {
        $link = $this->getUserEntiteLink($entite, true);
        if (!$link) return false;

        if ($adminOverride && $link->isTenantAdmin()) return true;

        return $link->hasRole($role);
    }
}
