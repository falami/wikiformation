<?php

declare(strict_types=1);

namespace App\Service\Seo;

use App\Entity\Marketplace\Offer;
use App\Repository\FormationRepository;
use App\Service\Public\PublicContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class PublicSitemap
{
    public function __construct(
        private readonly PublicContext $context,
        private readonly PublicSeoPolicy $policy,
        private readonly FormationRepository $formations,
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $router,
    ) {}

    /** @return list<string> Canonical French URLs only; no account, filter or transaction URLs. */
    public function urls(Request $request): array
    {
        if (!$this->policy->isIndexableHost($request)) return [];

        $origin = $this->policy->canonicalOrigin($request);
        $urls = [];
        $add = function (string $route, array $parameters = []) use (&$urls, $origin): void {
            $url = $origin . $this->router->generate($route, ['_locale' => 'fr'] + $parameters, UrlGeneratorInterface::ABSOLUTE_PATH);
            $urls[$url] = true;
        };

        $host = $this->context->getPublicHost();
        if (!$host) {
            foreach (['app_public', 'app_public_pricing', 'app_public_contact', 'app_recruitment', 'app_market_catalogue'] as $route) {
                $add($route);
            }
            foreach ($this->em->getRepository(Offer::class)->findBy(['published' => true, 'publicVisible' => true], ['id' => 'ASC']) as $offer) {
                // Match anonymous marketplace visibility, never include members-only offers.
                if ($offer->published && $offer->publicVisible && $offer->id !== null) $add('app_market_show', ['id' => $offer->id]);
            }
        }
        if ($this->context->isCalendarEnabled()) $add('app_public_formation');
        if ($this->context->isCatalogueEnabled()) $add('app_public_catalogue');
        if ($this->context->isCatalogueEnabled() || $this->context->isCalendarEnabled()) {
            foreach ($this->formations->findPublicCatalogue() as $formation) {
                if (!$this->context->allowsFormation($formation) || !$formation->getSlug()) continue;
                $add('app_public_show', ['slug' => $formation->getSlug()]);
                if (!$this->context->isCatalogueEnabled()) continue;
                // Only categories containing a visible course (and their ancestors).
                $category = $formation->getCategorie();
                $seen = [];
                while ($category && !isset($seen[spl_object_id($category)])) {
                    $seen[spl_object_id($category)] = true;
                    if ($host && !$host->isShowAllPublicFormations() && $category->getEntite()?->getId() !== $host->getEntite()?->getId()) break;
                    if ($category->getSlug()) $add('app_public_categorie_show', ['slug' => $category->getSlug()]);
                    $category = $category->getParent();
                }
            }
        }
        // Legacy tenant e-learning endpoints have no view templates. Only the working
        // marketplace landing pages above are eligible for search discovery.

        return array_keys($urls);
    }

    /** @param list<string> $urls */
    public function xml(array $urls): string
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;
        $root = $document->createElementNS('http://www.sitemaps.org/schemas/sitemap/0.9', 'urlset');
        $document->appendChild($root);
        foreach ($urls as $url) {
            $item = $document->createElement('url');
            $loc = $document->createElement('loc');
            $loc->appendChild($document->createTextNode($url));
            $item->appendChild($loc);
            $root->appendChild($item);
        }
        return (string) $document->saveXML();
    }
}
