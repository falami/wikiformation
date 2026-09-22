<?php

namespace App\Security;

use App\Entity\UtilisateurEntite;
use Symfony\Component\Routing\RouterInterface;

/** Choose an implemented portal without granting a different tenant role. */
final class MembershipHomeRoute
{
    public function __construct(private readonly RouterInterface $router) {}

    public function forMembership(UtilisateurEntite $membership): ?string
    {
        if (!$membership->isActive() || !$membership->getEntite()?->getId()) {
            return null;
        }

        $routes = [
            UtilisateurEntite::TENANT_DIRIGEANT => 'app_administrateur_dashboard_index',
            UtilisateurEntite::TENANT_ADMIN => 'app_administrateur_dashboard_index',
            UtilisateurEntite::TENANT_OF => 'app_of_dashboard',
            UtilisateurEntite::TENANT_FORMATEUR => 'app_formateur_dashboard',
            UtilisateurEntite::TENANT_ENTREPRISE => 'app_entreprise_dashboard',
            UtilisateurEntite::TENANT_STAGIAIRE => 'app_stagiaire_dashboard',
        ];
        foreach ($routes as $role => $route) {
            if (!$membership->hasRole($role)) {
                continue;
            }
            if (in_array($role, [UtilisateurEntite::TENANT_OF, UtilisateurEntite::TENANT_ENTREPRISE], true)
                && !$membership->getUtilisateur()?->getEntreprise()) {
                continue;
            }
            if ($this->router->getRouteCollection()->get($route)) {
                return $route;
            }
        }

        return null;
    }
}
