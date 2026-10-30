<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Seo\PublicSeoPolicy;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class PublicSeoExtension extends AbstractExtension
{
    public function __construct(
        private readonly RequestStack $requests,
        private readonly PublicSeoPolicy $policy,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('public_seo', [$this, 'metadata'])];
    }

    public function metadata(): array
    {
        $request = $this->requests->getMainRequest();
        if ($request === null) {
            throw new \LogicException('Public SEO metadata requires an HTTP request.');
        }

        $origin = rtrim($this->policy->canonicalOrigin($request), '/');
        // Most public editorial content is currently French, including /en/ copies.
        // Only advertise translated alternatives once their content is translated.
        $path = preg_replace('#^/en(?=/|$)#', '/fr', $request->getPathInfo());
        if (in_array($path, ['/', '/fr'], true)) {
            $path = '/fr/';
        }
        $canonical = $origin . $path;

        // Paginated catalogues must keep their own URL; never canonicalize all
        // courses onto page one. Tracking, filters and private tokens are omitted.
        $route = preg_replace('/\.(fr|en)$/', '', (string) $request->attributes->get('_route', ''));
        if ($route === 'app_market_catalogue') {
            $page = filter_var($request->query->all()['page'] ?? 1, FILTER_VALIDATE_INT);
            if ($page !== false && $page > 1) {
                $canonical .= '?page=' . $page;
            }
        }

        $indexable = !$this->policy->shouldNoIndex($request);

        return [
            'origin' => $origin,
            'canonical' => $canonical,
            'homeUrl' => $origin . '/fr/',
            'organizationUrl' => $origin . '/#organization',
            'indexable' => $indexable,
            'robots' => $indexable ? 'index, follow, max-image-preview:large' : 'noindex, follow',
        ];
    }
}
