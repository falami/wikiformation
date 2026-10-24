<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Document\RealisationCertificate;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class RealisationCertificateExtension extends AbstractExtension
{
    public function __construct(private readonly RealisationCertificate $certificate) {}
    public function getFunctions(): array
    {
        return [new TwigFunction('certificate_available', [$this->certificate, 'available']), new TwigFunction('realisation_certificate', [$this->certificate, 'data'])];
    }
}
