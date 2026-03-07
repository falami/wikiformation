<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Public\PublicContext;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class PublicBrandingExtension extends AbstractExtension
{
    public function __construct(
        private readonly PublicContext $publicContext,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('public_branding', [$this, 'getBranding']),
            new TwigFunction('public_host_config', [$this, 'getHostConfig']),
        ];
    }

    public function getBranding(): array
    {
        return $this->publicContext->getBranding();
    }

    public function getHostConfig(): ?\App\Entity\PublicHost
    {
        return $this->publicContext->getPublicHost();
    }
}