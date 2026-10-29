<?php

declare(strict_types=1);

namespace App\Service\Entreprise;

use App\Entity\{Entite, Entreprise, Utilisateur};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/** Company representation is distinct from a trainee's employer associations. */
final class EntreprisePortalContext
{
    public function __construct(private EntityManagerInterface $em, private RequestStack $requests) {}

    public static function canRepresent(Utilisateur $user, ?Entreprise $company): bool
    {
        return $company?->getId() !== null && (
            $company->getRepresentant()?->getId() === $user->getId()
            || $user->getEntreprise()?->getId() === $company->getId()
        );
    }

    public static function isRepresentative(Utilisateur $user, ?Entreprise $company): bool
    {
        return $user->getId() !== null && $company?->getRepresentant()?->getId() === $user->getId();
    }

    /** @return list<Entreprise> */
    public function representedCompanies(Utilisateur $user, Entite $tenant): array
    {
        return $this->em->getRepository(Entreprise::class)->findBy(
            ['entite' => $tenant, 'representant' => $user], ['raisonSociale' => 'ASC']
        );
    }

    /** @return list<Entreprise> */
    public function companies(Utilisateur $user, Entite $tenant): array
    {
        $companies = $this->em->getRepository(Entreprise::class)->findBy(
            ['entite' => $tenant, 'representant' => $user], ['raisonSociale' => 'ASC']
        );
        $primary = $user->getEntreprise();
        if ($primary && $primary->getEntite()?->getId() === $tenant->getId()
            && !in_array($primary, $companies, true)) $companies[] = $primary;
        return $companies;
    }

    public function resolve(Utilisateur $user, Entite $tenant, bool $representativesOnly = false): Entreprise
    {
        $request = $this->requests->getCurrentRequest();
        $companies = $representativesOnly ? $this->representedCompanies($user, $tenant) : $this->companies($user, $tenant);
        $key = 'enterprise_portal.'.$tenant->getId().'.'.$user->getId();
        $explicit = $request?->query->has('entreprise') ?? false;
        $id = $explicit ? $request->query->getInt('entreprise') : ($request?->hasSession() ? $request->getSession()->get($key) : null);
        $selected = null;
        foreach ($companies as $company) {
            if ($company->getId() === (int) $id) $selected = $company;
        }
        if ($explicit && !$selected) throw new AccessDeniedException('Entreprise non autorisée pour cet organisme.');
        $selected ??= $companies[0] ?? null;
        if (!$selected) throw new AccessDeniedException('Aucune entreprise associée à ce compte pour cet organisme.');
        if ($request?->hasSession() && !$request->isXmlHttpRequest() && $request->isMethod('GET')) {
            $request->getSession()->set($key, $selected->getId());
        }
        return $selected;
    }
}
