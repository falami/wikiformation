<?php

declare(strict_types=1);

namespace App\Service\Seo;

use App\Service\Public\PublicContext;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/** Shared by HTML metadata, discovery documents and HTTP indexing headers. */
final class PublicSeoPolicy
{
    private const PUBLIC_ROUTES = [
        'app_public', 'home_locale', 'app_public_formation', 'app_public_catalogue',
        'app_public_show', 'app_public_categorie_show', 'app_public_contact',
        'app_public_pricing', 'app_public_politique_confidentialite',
        'app_public_mentions_legales', 'app_public_cgu', 'app_public_cgv',
        'app_public_cookies', 'app_recruitment', 'app_market_catalogue',
        'app_market_show', 'app_market_terms', 'app_public_engin', 'app_public_engin_show',
        'app_seo_robots', 'app_seo_sitemap',
    ];

    public function __construct(
        private readonly PublicContext $publicContext,
        #[Autowire(param: 'kernel.environment')] private readonly string $environment,
        #[Autowire(param: 'app.url')] private readonly string $appUrl,
    ) {}

    public function canonicalOrigin(Request $request): string
    {
        $custom = $this->publicContext->getPublicHost();
        if ($custom?->isActive() && $custom->getHost() === strtolower($request->getHost())) {
            return 'https://' . $custom->getHost();
        }

        $parts = parse_url($this->appUrl);
        if (is_array($parts) && !empty($parts['host'])) {
            $scheme = in_array($parts['scheme'] ?? '', ['http', 'https'], true) ? $parts['scheme'] : 'https';
            return $scheme . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
        }

        // Never make a user-controlled Host header the canonical fallback.
        return 'https://wikiformation.fr';
    }

    public function isIndexableHost(Request $request): bool
    {
        $host = strtolower($request->getHost());
        if ($this->environment !== 'prod' || $this->isNonPublicHost($host)) {
            return false;
        }

        $custom = $this->publicContext->getPublicHost();
        if ($custom?->isActive() && $custom->getHost() === $host) {
            return true;
        }

        $main = strtolower((string) parse_url($this->appUrl, PHP_URL_HOST));
        if ($main === '' || $this->isNonPublicHost($main)) {
            return false;
        }

        return preg_replace('/^www\./', '', $host) === preg_replace('/^www\./', '', $main);
    }

    public function shouldNoIndex(Request $request): bool
    {
        if (!$this->isIndexableHost($request) || !in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return true;
        }

        // The current public pages contain French copy even on /en/ routes.
        if ($request->attributes->get('_locale', 'fr') !== 'fr' || str_starts_with($request->getPathInfo(), '/en/')) {
            return true;
        }

        $route = preg_replace('/\.(fr|en)$/', '', (string) $request->attributes->get('_route', ''));
        if (!in_array($route, self::PUBLIC_ROUTES, true) || $request->isXmlHttpRequest()
            || $request->attributes->has('token') || $request->query->has('signature')) {
            return true;
        }

        // Filters generate combinations, not distinct landing pages. Tracking is harmless.
        foreach (['q', 'search', 'destination', 'from', 'to', 'dates', 'enginType', 'category', 'categorie', 'sort'] as $key) {
            $value = $request->query->all()[$key] ?? null;
            if ($this->hasFilterValue($value)) {
                return true;
            }
        }

        if ($this->publicContext->hasCustomHost()) {
            if (in_array($route, ['app_public_catalogue', 'app_public_categorie_show'], true)) {
                return !$this->publicContext->isCatalogueEnabled();
            }
            if ($route === 'app_public_show') {
                return !$this->publicContext->isCatalogueEnabled() && !$this->publicContext->isCalendarEnabled();
            }
            if ($route === 'app_public_formation') {
                return !$this->publicContext->isCalendarEnabled();
            }
            // Platform-wide marketing and marketplace pages are not tenant landing pages.
            return !in_array($route, ['app_public', 'home_locale', 'app_seo_robots', 'app_seo_sitemap'], true);
        }

        return false;
    }

    private function isNonPublicHost(string $host): bool
    {
        return $host === 'localhost' || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false
            || preg_match('/(?:^|[.\-])(?:test|testing|stage|staging|preprod|preview|dev|development|localhost)(?:[.\-]|$)/', $host) === 1
            || preg_match('/\.(?:local|localhost|test|internal|invalid)$/', $host) === 1;
    }

    private function hasFilterValue(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) if ($this->hasFilterValue($item)) return true;
            return false;
        }
        return $value !== null && trim((string) $value) !== '';
    }
}
