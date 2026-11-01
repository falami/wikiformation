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

    public function getContactEmail(): ?string
    {
        if (!$this->hasCustomHost()) return 'contact@wikiformation.fr';
        $email = trim((string) $this->publicHost?->getEntite()?->getEmail());
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
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

        // Host global Wiki Formation : toutes les formations publiques
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

    public function getContactEmailTheme(): array
    {
        $host = $this->getPublicHost();
        $entity = $host?->getEntite();
        $normalize = static function (?string $value, string $fallback): string {
            $value = trim((string) $value);
            if (preg_match('/^#[0-9a-f]{3}$/i', $value)) {
                return '#' . $value[1] . $value[1] . $value[2] . $value[2] . $value[3] . $value[3];
            }
            return preg_match('/^#[0-9a-f]{6}$/i', $value) ? $value : $fallback;
        };
        $primary = $normalize($entity?->getCouleurPrincipal(), $normalize($host?->getPrimaryColor(), '#142b39'));
        $accent = $normalize($entity?->getCouleurSecondaire(), $normalize($host?->getSecondaryColor(), '#e7bd63'));
        $contrast = static function (string $color): string {
            $channels = array_map(static function (string $channel): float {
                $value = hexdec($channel) / 255;
                return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            }, str_split(substr($color, 1), 2));
            $luminance = 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
            return $luminance > 0.179 ? '#000000' : '#ffffff';
        };
        return ['primary' => $primary, 'accent' => $accent, 'onPrimary' => $contrast($primary), 'onAccent' => $contrast($accent)];
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
            'homeUrl' => $host?->getHomeUrl(),
            'showPricing' => !$this->hasCustomHost(),
            'showContact' => !$this->hasCustomHost(),
        ];
    }


    public function getHomeUrl(): ?string
    {
        return $this->publicHost?->getHomeUrl();
    }

    public function shouldShowPricing(): bool
    {
        return !$this->hasCustomHost();
    }

    public function shouldShowContact(): bool
    {
        return !$this->hasCustomHost();
    }

    public function shouldShowHomeAsExternalLink(): bool
    {
        return $this->hasCustomHost() && !empty($this->getHomeUrl());
    }
}
