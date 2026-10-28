<?php
declare(strict_types=1);

namespace App\Service\Billing;

use App\Repository\Billing\PlanRepository;
use Twig\Environment;
use Twig\Extension\RuntimeExtensionInterface;

/** The displayed document and the accepted evidence use the same rendered clauses. */
final class SubscriptionTerms implements RuntimeExtensionInterface
{
    public const VERSION = '2026-10-28.2';

    public function __construct(private Environment $twig, private PlanRepository $plans) {}

    /** @return array{version: string, html: string, sha256: string} */
    public function snapshot(): array
    {
        $html = $this->twig->load('public/cgv.html.twig')->renderBlock('contract_terms', [
            'plans' => $this->plans->findPublicOffers(),
        ]);

        return ['version' => self::VERSION, 'html' => $html, 'sha256' => hash('sha256', $html)];
    }
}
