<?php
namespace App\Twig;

use App\Entity\{Entite, Utilisateur, UtilisateurEntite};
use App\Repository\UtilisateurEntiteRepository;
use App\Security\MembershipHomeRoute;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class MarketplaceNavigationExtension extends AbstractExtension
{
    public function __construct(private UtilisateurEntiteRepository $memberships, private MembershipHomeRoute $home, private RequestStack $requests) {}

    public function getFunctions(): array
    {
        return [new TwigFunction('market_navigation', [$this, 'navigation'])];
    }

    public function navigation(?Utilisateur $user, ?Entite $entity = null): array
    {
        if (!$user) return ['entity' => null, 'home' => null, 'studio' => false];
        $current = $entity?->getId() ?? $this->requests->getCurrentRequest()?->getSession()->get('current_entite_id') ?? $user->getEntite()?->getId();
        $active = array_values(array_filter($this->memberships->findAllForUser($user), fn ($m) => $m->isActive()));
        $membership = null;
        foreach ($active as $candidate) {
            if ($candidate->getEntite()?->getId() === (int) $current) { $membership = $candidate; break; }
        }
        if (!$entity && !$membership) $membership = $active[0] ?? null;
        $entity ??= $membership?->getEntite();
        if ($user->isSuperAdmin()) {
            $entity ??= $user->getEntite();
            return ['entity' => $entity, 'home' => $entity ? 'app_administrateur_dashboard_index' : null, 'studio' => (bool) $entity];
        }
        return [
            'entity' => $membership?->getEntite(),
            'home' => $membership ? $this->home->forMembership($membership) : null,
            'studio' => $membership && $membership->hasRole(UtilisateurEntite::TENANT_FORMATEUR),
        ];
    }
}
