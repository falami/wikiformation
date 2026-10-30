<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Twig\Extra\String\StringExtension;
use Twig\Loader\{ArrayLoader, ChainLoader, FilesystemLoader};
use Twig\TwigFunction;
use Twig\TwigFilter;

final class PublicCourseMetadataTest extends TestCase
{
    private function twig(): Environment
    {
        $twig = new Environment(new ChainLoader([
            new ArrayLoader([
                'base.html.twig' => '<html><head><title>{% block title %}{% endblock %}</title>{% block metadata %}{% endblock %}</head></html>',
                'base_public.html.twig' => '<html><head><title>{% block title %}{% endblock %}</title><meta name="description" content="{% block meta_description %}{% endblock %}">{% block structured_data %}{% endblock %}</head></html>',
            ]),
            new FilesystemLoader(dirname(__DIR__, 2).'/templates'),
        ]), ['strict_variables' => true, 'autoescape' => 'html']);
        $twig->addExtension(new StringExtension());
        $twig->addFilter(new TwigFilter('trans', static fn(string $text): string => $text));
        $twig->addFunction(new TwigFunction('public_branding', static fn(): array => ['name' => 'WikiFormation', 'logo' => '/logo.png']));
        $twig->addFunction(new TwigFunction('public_host_config', static fn() => null));
        $twig->addFunction(new TwigFunction('public_seo', fn(): array => $this->seo()));
        $twig->addFunction(new TwigFunction('asset', static fn(string $path): string => '/'.ltrim($path, '/')));
        $twig->addFunction(new TwigFunction('path', static fn(string $route, array $params = []): string => '/fr/'.$route.'/'.($params['slug'] ?? $params['id'] ?? '')));
        foreach (['form_start', 'form_end', 'form_rest', 'form_errors', 'form_widget', 'csrf_token', 'market_navigation', 'is_granted'] as $name) {
            $twig->addFunction(new TwigFunction($name, static fn() => ''));
        }
        return $twig;
    }

    private function seo(): array
    {
        return ['origin' => 'https://training.example', 'canonical' => 'https://training.example/fr/course',
            'homeUrl' => 'https://training.example/fr/', 'robots' => 'index, follow', 'indexable' => true];
    }

    private function structuredData(string $html): array
    {
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $scripts = (new \DOMXPath($document))->query('//script[@type="application/ld+json"]');
        return array_map(static fn(\DOMNode $node): array => json_decode($node->textContent, true, 512, JSON_THROW_ON_ERROR), iterator_to_array($scripts));
    }

    public function testPublicCourseSchemaUsesItsActualProviderAndSafelyEncodesCourseContent(): void
    {
        $title = 'Formation </script><script>alert("test")</script>';
        $html = $this->twig()->render('public/show.html.twig', [
            'brand' => ['name' => 'WikiFormation'], 'seo' => $this->seo(),
            'formation' => ['titre' => $title, 'niveau' => null, 'description' => '<p>Apprendre & pratiquer.</p>',
                'entite' => ['nom' => 'Organisme partenaire'], 'photoBanniere' => 'course.jpg',
                'conditionPrealable' => '<p>Aucun prérequis.</p>', 'objectifs' => '<p>Créer un tableau.</p>'],
        ]);
        $data = $this->structuredData($html);
        self::assertCount(2, $data);
        self::assertSame($title, $data[0]['name']);
        self::assertSame('Organisme partenaire', $data[0]['provider']['name']);
        self::assertSame('https://training.example/uploads/photos/formation/course.jpg', $data[0]['image']);
        self::assertSame('Apprendre & pratiquer.', $data[0]['description']);
        self::assertSame('https://training.example/fr/course', $data[1]['itemListElement'][1]['item']);
        self::assertStringNotContainsString('<script>alert', $html);
        self::assertArrayNotHasKey('aggregateRating', $data[0]);
    }

    public function testMemberOnlyCourseHasNoPublicSchemaOrCanonical(): void
    {
        $request = Request::create('https://training.example/fr/cours-en-ligne/cours/5');
        $request->attributes->set('_route', 'app_market_show.fr');
        $offer = ['id' => 5, 'published' => true, 'publicVisible' => false,
            'snapshot' => ['title' => 'Cours réservé', 'description' => 'Programme réservé.', 'minutes' => 45],
            'seller' => ['legalName' => 'Organisme partenaire'], 'priceCents' => 1250];
        $context = ['app' => ['request' => $request, 'user' => new \stdClass()], 'offer' => $offer];
        $html = $this->twig()->render('marketplace/show.html.twig', $context);
        self::assertStringContainsString('noindex, nofollow', $html);
        self::assertStringNotContainsString('rel="canonical"', $html);
        self::assertCount(0, $this->structuredData($html));

        $context['offer']['publicVisible'] = true;
        $html = $this->twig()->render('marketplace/show.html.twig', $context);
        self::assertStringContainsString('rel="canonical"', $html);
        $data = $this->structuredData($html);
        self::assertCount(2, $data);
        self::assertSame('12.50', $data[0]['offers']['price']);
        self::assertSame('EUR', $data[0]['offers']['priceCurrency']);
        self::assertSame('PT45M', $data[0]['timeRequired']);
    }

    public function testCatalogueSchemaOnlyIncludesPublicOffersAndSkipsPersonalizedResults(): void
    {
        $request = Request::create('https://training.example/fr/cours-en-ligne');
        $request->attributes->set('_route', 'app_market_catalogue.fr');
        $context = ['app' => ['request' => $request, 'user' => null], 'page' => 1, 'offers' => [
            ['id' => 2, 'published' => true, 'publicVisible' => true, 'snapshot' => ['title' => 'Cours public']],
            ['id' => 3, 'published' => true, 'publicVisible' => false, 'snapshot' => ['title' => 'Cours réservé']],
        ]];
        $data = $this->structuredData($this->twig()->render('marketplace/catalogue.html.twig', $context));
        self::assertCount(1, $data);
        self::assertCount(1, $data[0]['itemListElement']);
        self::assertSame('Cours public', $data[0]['itemListElement'][0]['name']);
        $context['app']['user'] = new \stdClass();
        $html = $this->twig()->render('marketplace/catalogue.html.twig', $context);
        self::assertStringContainsString('noindex, nofollow', $html);
        self::assertCount(0, $this->structuredData($html));
    }
}
