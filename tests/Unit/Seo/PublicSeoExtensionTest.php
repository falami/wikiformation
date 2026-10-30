<?php

declare(strict_types=1);

namespace App\Tests\Unit\Seo;

use App\Entity\PublicHost;
use App\Service\Public\PublicContext;
use App\Service\Seo\PublicSeoPolicy;
use App\Twig\PublicSeoExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class PublicSeoExtensionTest extends TestCase
{
    private function metadata(string $url, string $route = 'app_public', ?PublicContext $context = null): array
    {
        $request = Request::create($url);
        $request->attributes->set('_route', $route);
        $request->attributes->set('_locale', str_contains($url, '/en/') ? 'en' : 'fr');
        $stack = new RequestStack();
        $stack->push($request);

        return (new PublicSeoExtension($stack, new PublicSeoPolicy($context ?? new PublicContext(), 'prod', 'https://wikiformation.fr')))->metadata();
    }

    public function testHomeAliasesShareCanonicalAndTrackingIsNotCopied(): void
    {
        foreach (['https://wikiformation.fr/?utm_source=google', 'https://www.wikiformation.fr/fr/', 'https://wikiformation.fr/en/'] as $url) {
            $seo = $this->metadata($url);
            self::assertSame('https://wikiformation.fr/fr/', $seo['canonical']);
            self::assertSame('https://wikiformation.fr/fr/', $seo['homeUrl']);
        }
        self::assertFalse($this->metadata('https://wikiformation.fr/en/')['indexable']);
        self::assertTrue($this->metadata('https://wikiformation.fr/fr/?utm_source=google')['indexable']);
    }

    public function testCataloguePaginationIsPreservedWithoutSearchOrTokens(): void
    {
        $seo = $this->metadata('https://wikiformation.fr/fr/cours-en-ligne?page=2&utm_source=x&token=private', 'app_market_catalogue.fr');
        self::assertSame('https://wikiformation.fr/fr/cours-en-ligne?page=2', $seo['canonical']);
        self::assertSame('https://wikiformation.fr/fr/cours-en-ligne', $this->metadata('https://wikiformation.fr/fr/cours-en-ligne?page[]=2', 'app_market_catalogue')['canonical']);
        $filtered = $this->metadata('https://wikiformation.fr/fr/cours-en-ligne?q=Excel', 'app_market_catalogue');
        self::assertFalse($filtered['indexable']);
        self::assertSame('noindex, follow', $filtered['robots']);
    }

    public function testCustomBrandKeepsItsOwnIdentityAndEscapesStructuredData(): void
    {
        $context = new PublicContext();
        $host = new PublicHost();
        $host->setHost('formations.exemple.fr');
        $host->setName('École </script><script>alert(1)</script> & associés');
        $host->setIsActive(true);
        $context->setPublicHost($host);
        $seo = $this->metadata('https://formations.exemple.fr/fr/catalogue', 'app_public_catalogue', $context);
        self::assertSame('https://formations.exemple.fr/fr/catalogue', $seo['canonical']);

        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates'), ['strict_variables' => true]);
        $twig->addFunction(new TwigFunction('asset', static fn (string $path): string => '/' . $path));
        $html = $twig->render('public/_site_schema.html.twig', ['seo' => $seo, 'brand' => $context->getBranding()]);
        self::assertSame(1, substr_count($html, '</script>'));
        preg_match('#<script[^>]*>(.*?)</script>#s', $html, $matches);
        $schema = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($host->getName(), $schema['@graph'][0]['name']);
        self::assertSame('https://formations.exemple.fr/fr/', $schema['@graph'][1]['url']);
        self::assertArrayNotHasKey('potentialAction', $schema['@graph'][1]);
    }
}
