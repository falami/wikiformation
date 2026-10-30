<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Service\Seo\PublicSeoPolicy;
use App\Service\Seo\PublicSitemap;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class SeoController extends AbstractController
{
    public function __construct(private readonly PublicSeoPolicy $policy, private readonly PublicSitemap $sitemap) {}

    public function robots(Request $request): Response
    {
        $body = "User-agent: *\n";
        if (!$this->policy->isIndexableHost($request)) {
            $body .= "Disallow: /\n";
        } else {
            // Keep JS, CSS and public images crawlable. Private routes also send noindex headers.
            $body .= "Allow: /\nDisallow: /_profiler/\nDisallow: /_wdt/\n\nSitemap: " . $this->policy->canonicalOrigin($request) . "/sitemap.xml\n";
        }
        return new Response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'public, max-age=300']);
    }

    public function sitemap(Request $request): Response
    {
        return new Response($this->sitemap->xml($this->sitemap->urls($request)), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
