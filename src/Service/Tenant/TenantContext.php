<?php

namespace App\Service\Tenant;

use App\Entity\Entite;
use App\Entity\Utilisateur;
use App\Repository\EntiteRepository;
use App\Repository\UtilisateurEntiteRepository;
use Symfony\Component\HttpFoundation\RequestStack;

final class TenantContext
{
    public const PLATFORM_ENTITE_ID = 1;

    public function __construct(
        private RequestStack $requestStack,
        private EntiteRepository $entiteRepo,
        private UtilisateurEntiteRepository $ueRepo,
    ) {}

    public function getCurrentEntiteForUser(Utilisateur $user): ?Entite
    {
        $session = $this->requestStack->getSession();
        $entiteId = (int) $session->get('current_entite_id', 0);

        if ($entiteId > 0) {
            $entite = $this->entiteRepo->find($entiteId);
            if ($entite && $this->ueRepo->userHasEntite($user, $entite)) {
                return $entite;
            }
        }

        // fallback : entité stockée sur user si valide
        $entite = $user->getEntite();
        if ($entite && $this->ueRepo->userHasEntite($user, $entite)) {
            $session->set('current_entite_id', $entite->getId());
            return $entite;
        }

        // fallback : première entité dispo via relation UtilisateurEntite
        $first = $this->ueRepo->findFirstEntiteForUser($user);
        if ($first) {
            $session->set('current_entite_id', $first->getId());
            return $first;
        }

        return null;
    }

    public function setCurrentEntite(Utilisateur $user, Entite $entite): void
    {
        if (!$this->ueRepo->userHasEntite($user, $entite)) {
            throw new \RuntimeException('Accès interdit à cette entité.');
        }

        $this->requestStack->getSession()->set('current_entite_id', $entite->getId());

        // optionnel : tu peux synchroniser user->entite comme "courante"
        $user->setEntite($entite);
    }

    public function isPlatformEntite(?Entite $entite): bool
    {
        return $entite && $entite->getId() === self::PLATFORM_ENTITE_ID;
    }
}