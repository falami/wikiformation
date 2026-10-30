<?php

declare(strict_types=1);

namespace App\Tests\Unit\Seo;

use App\Entity\{Categorie, Entite, Formation, PublicHost};
use App\Entity\Marketplace\Offer;
use App\Controller\Public\SeoController;
use App\Repository\FormationRepository;
use App\Service\Public\PublicContext;
use App\Service\Seo\{PublicSeoPolicy, PublicSitemap};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class PublicSitemapTest extends TestCase
{
    public function testGlobalSitemapExcludesUnpublishedExcludedAndMembersOnlyContent(): void
    {
        $root = (new Categorie())->setSlug('bureautique');
        $category = (new Categorie())->setSlug('excel')->setParent($root);
        $public = (new Formation())->setSlug('excel-initial')->setIsPublic(true)->setCategorie($category);
        $private = (new Formation())->setSlug('secret')->setIsPublic(false);
        $excluded = (new Formation())->setSlug('tenant-only')->setIsPublic(true)->setExcludeFromGlobalCatalogue(true);
        $visible = new Offer(); $visible->id = 7; $visible->publicVisible = true; $visible->published = true;
        $members = new Offer(); $members->id = 8; $members->membersVisible = true; $members->published = true;
        $draft = new Offer(); $draft->id = 9; $draft->publicVisible = true;
        $builder = $this->builder(new PublicContext(), [$public, $private, $excluded], [$visible, $members, $draft]);
        $urls = $builder->urls(Request::create('https://wikiformation.fr/sitemap.xml'));
        self::assertContains('https://wikiformation.fr/fr/formation/excel-initial', $urls);
        self::assertContains('https://wikiformation.fr/fr/categorie/bureautique', $urls);
        self::assertContains('https://wikiformation.fr/fr/categorie/excel', $urls);
        self::assertContains('https://wikiformation.fr/fr/cours-en-ligne/cours/7', $urls);
        self::assertNotContains('https://wikiformation.fr/fr/formation/secret', $urls);
        self::assertNotContains('https://wikiformation.fr/fr/formation/tenant-only', $urls);
        self::assertNotContains('https://wikiformation.fr/fr/cours-en-ligne/cours/8', $urls);
        self::assertNotContains('https://wikiformation.fr/fr/cours-en-ligne/cours/9', $urls);
        self::assertCount(count(array_unique($urls)), $urls);
    }

    public function testTenantSitemapHonoursAssignmentsAndModuleFlags(): void
    {
        $entite = $this->withId(new Entite(), 1);
        $other = $this->withId(new Entite(), 2);
        $host = $this->withId((new PublicHost())->setHost('training.example')->setEntite($entite)->setRestrictToAssignedFormations(true), 10);
        $context = new PublicContext(); $context->setPublicHost($host);
        $visible = (new Formation())->setSlug('allowed')->setIsPublic(true)->setEntite($entite)->addPublicHost($host);
        $visible->setCategorie((new Categorie())->setSlug('other-tenant-category')->setEntite($other));
        $unassigned = (new Formation())->setSlug('unassigned')->setIsPublic(true)->setEntite($entite);
        $wrongTenant = (new Formation())->setSlug('other-tenant')->setIsPublic(true)->setEntite($other)->addPublicHost($host);
        $builder = $this->builder($context, [$visible, $unassigned, $wrongTenant]);
        $request = Request::create('https://training.example/sitemap.xml');
        $urls = $builder->urls($request);
        self::assertContains('https://training.example/fr/formation/allowed', $urls);
        self::assertNotContains('https://training.example/fr/formation/unassigned', $urls);
        self::assertNotContains('https://training.example/fr/formation/other-tenant', $urls);
        self::assertNotContains('https://training.example/fr/categorie/other-tenant-category', $urls);
        self::assertNotContains('https://training.example/fr/tarifs', $urls);
        self::assertNotContains('https://training.example/fr/cours-en-ligne', $urls);
        $host->setCatalogueEnabled(false)->setCalendarEnabled(false);
        self::assertSame([], $builder->urls($request));
        $host->setElearningEnabled(true);
        self::assertSame([], $builder->urls($request), 'Legacy e-learning routes without templates are not advertised.');
        $host->setCalendarEnabled(true);
        self::assertContains('https://training.example/fr/formation/allowed', $builder->urls($request));
        self::assertNotContains('https://training.example/fr/catalogue', $builder->urls($request));
    }

    public function testNonProductionSitemapDoesNotReadOrExposeAnyContent(): void
    {
        $context = new PublicContext();
        $formations = $this->createMock(FormationRepository::class);
        $formations->expects(self::never())->method('findPublicCatalogue');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('getRepository');
        $builder = new PublicSitemap($context, new PublicSeoPolicy($context, 'dev', 'https://wikiformation.fr'), $formations, $em, $this->router());
        self::assertSame([], $builder->urls(Request::create('http://localhost:8888/sitemap.xml')));
    }

    public function testXmlEscapesUrlsAndNeverInventsLastModifiedDates(): void
    {
        $builder = $this->builder(new PublicContext(), []);
        $url = 'https://wikiformation.fr/fr/formation/excel?one=1&two=2';
        $xml = $builder->xml([$url]);
        $document = new \DOMDocument();
        self::assertTrue($document->loadXML($xml));
        self::assertSame('http://www.sitemaps.org/schemas/sitemap/0.9', $document->documentElement->namespaceURI);
        self::assertSame($url, $document->getElementsByTagName('loc')->item(0)->textContent);
        self::assertStringContainsString('&amp;', $xml);
        self::assertStringNotContainsString('<lastmod>', $xml);
    }

    public function testRobotsAdvertisesOnlyProductionSitemapAndLeavesAssetsCrawlable(): void
    {
        $context = new PublicContext();
        $controller = new SeoController(new PublicSeoPolicy($context, 'prod', 'https://wikiformation.fr'), $this->builder($context, []));
        $response = $controller->robots(Request::create('https://wikiformation.fr/robots.txt'));
        self::assertSame('text/plain; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertStringContainsString('Sitemap: https://wikiformation.fr/sitemap.xml', $response->getContent());
        self::assertStringNotContainsString('Disallow: /dist/', $response->getContent());
        $response = $controller->robots(Request::create('https://test.wikiformation.fr/robots.txt'));
        self::assertSame("User-agent: *\nDisallow: /\n", $response->getContent());
        self::assertStringNotContainsString('Sitemap:', $response->getContent());
    }

    private function builder(PublicContext $context, array $formations, array $offers = []): PublicSitemap
    {
        $repository = $this->createMock(FormationRepository::class);
        $repository->method('findPublicCatalogue')->willReturn($formations);
        $offersRepository = $this->createMock(EntityRepository::class);
        $offersRepository->method('findBy')->willReturn($offers);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($offersRepository);
        return new PublicSitemap($context, new PublicSeoPolicy($context, 'prod', 'https://wikiformation.fr'), $repository, $em, $this->router());
    }

    private function router(): UrlGeneratorInterface
    {
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static function (string $route, array $parameters): string {
            self::assertSame('fr', $parameters['_locale']);
            return '/fr/' . match ($route) {
                'app_public' => '', 'app_public_pricing' => 'tarifs', 'app_public_contact' => 'contact',
                'app_recruitment' => 'recrutement', 'app_market_catalogue' => 'cours-en-ligne',
                'app_market_show' => 'cours-en-ligne/cours/' . $parameters['id'],
                'app_public_catalogue' => 'catalogue', 'app_public_formation' => 'formation',
                'app_public_show' => 'formation/' . $parameters['slug'],
                'app_public_categorie_show' => 'categorie/' . $parameters['slug'],
            };
        });
        return $router;
    }

    private function withId(object $entity, int $id): object
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);
        return $entity;
    }
}
