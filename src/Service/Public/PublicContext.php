<?php

declare(strict_types=1);

namespace App\Service\Public;

use App\Entity\Formation;
use App\Entity\PublicHost;

final class PublicContext
{
    private ?PublicHost $publicHost = null;
    private string $currentHost = '';

    public function setCurrentHost(string $host): void
    {
        $this->currentHost = mb_strtolower(trim($host));
    }

    public function getCurrentHost(): string
    {
        return $this->currentHost;
    }

    public function setPublicHost(?PublicHost $publicHost): void
    {
        $this->publicHost = $publicHost;
    }

    public function getPublicHost(): ?PublicHost
    {
        return $this->publicHost;
    }

    public function hasCustomHost(): bool
    {
        return $this->publicHost instanceof PublicHost;
    }

    public function isCatalogueEnabled(): bool
    {
        return $this->publicHost?->isCatalogueEnabled() ?? true;
    }

    public function isCalendarEnabled(): bool
    {
        return $this->publicHost?->isCalendarEnabled() ?? true;
    }

    public function isElearningEnabled(): bool
    {
        return $this->publicHost?->isElearningEnabled() ?? false;
    }

    public function isShopEnabled(): bool
    {
        return $this->publicHost?->isShopEnabled() ?? false;
    }

    public function allowsFormation(Formation $formation): bool
    {
        if (!$formation->isPublic()) {
            return false;
        }

        $host = $this->getPublicHost();

        // fallback : si aucun PublicHost résolu, on autorise les formations publiques
        if (!$host instanceof PublicHost) {
            return !$formation->isExcludeFromGlobalCatalogue();
        }

        if (!$host->isActive()) {
            return false;
        }

        // Host global Wikiformation : toutes les formations publiques
        // sauf celles que tu as explicitement retirées
        if ($host->isShowAllPublicFormations()) {
            if ($formation->isExcludeFromGlobalCatalogue()) {
                return false;
            }

            return true;
        }

        // Host spécifique d'entité : seulement les formations publiques de cette entité
        $hostEntite = $host->getEntite();
        $formationEntite = $formation->getEntite();

        if (!$hostEntite || !$formationEntite) {
            return false;
        }

        if ($hostEntite->getId() !== $formationEntite->getId()) {
            return false;
        }

        // Mode avancé optionnel : restriction manuelle si activée
        if ($host->isRestrictToAssignedFormations()) {
            foreach ($formation->getPublicHosts() as $assignedHost) {
                if ($assignedHost->getId() === $host->getId()) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    public function getBranding(): array
    {
        $host = $this->getPublicHost();
        $logoPath = $host?->getLogoPath();

        return [
            'host' => $this->getCurrentHost(),
            'name' => $host?->getName() ?? 'Wikiformation',
            'logo' => $logoPath
                ? 'uploads/public-host/logo/' . $logoPath
                : 'uploads/photos/entite/logo/logo-wikiformation.png',
            'primaryColor' => $host?->getPrimaryColor() ?? '#233342',
            'secondaryColor' => $host?->getSecondaryColor() ?? '#ffc107',
            'tertiaryColor' => $host?->getTertiaryColor() ?? '#F0F0F0',
            'quaternaryColor' => $host?->getQuaternaryColor() ?? '#000000',
            'hasCustomHost' => $this->hasCustomHost(),
            'showAllPublicFormations' => $host?->isShowAllPublicFormations() ?? true,
        ];
    }
}