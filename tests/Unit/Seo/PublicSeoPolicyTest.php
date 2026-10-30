<?php

declare(strict_types=1);

namespace App\Tests\Unit\Seo;

use App\Entity\PublicHost;
use App\EventSubscriber\PublicSeoSubscriber;
use App\Service\Public\PublicContext;
use App\Service\Seo\PublicSeoPolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\HttpKernel\{HttpKernelInterface, Event\RequestEvent, Event\ResponseEvent};

final class PublicSeoPolicyTest extends TestCase
{
    public function testOnlyProductionAndKnownPublicHostsMayBeIndexed(): void
    {
        $context = new PublicContext();
        $production = new PublicSeoPolicy($context, 'prod', 'https://wikiformation.fr');
        self::assertTrue($production->isIndexableHost(Request::create('https://wikiformation.fr/fr/')));
        self::assertTrue($production->isIndexableHost(Request::create('https://www.wikiformation.fr/fr/')));
        foreach (['localhost', '127.0.0.1', 'test.wikiformation.fr', 'staging.wikiformation.fr', 'unknown.example'] as $host) {
            self::assertFalse($production->isIndexableHost(Request::create('https://' . $host . '/fr/')), $host);
        }
        self::assertFalse((new PublicSeoPolicy($context, 'dev', 'https://wikiformation.fr'))->isIndexableHost(Request::create('https://wikiformation.fr/fr/')));

        $context->setPublicHost((new PublicHost())->setHost('formation.example')->setIsActive(true));
        self::assertTrue($production->isIndexableHost(Request::create('https://formation.example/fr/catalogue')));
        self::assertSame('https://formation.example', $production->canonicalOrigin(Request::create('https://formation.example/fr/catalogue')));
        self::assertFalse($production->isIndexableHost(Request::create('https://other.example/fr/catalogue')));
        $context->setPublicHost((new PublicHost())->setHost('test.wikiformation.fr')->setIsActive(true));
        self::assertFalse($production->isIndexableHost(Request::create('https://test.wikiformation.fr/fr/catalogue')));
    }

    public function testCanonicalCannotBePoisonedByUnknownHost(): void
    {
        $policy = new PublicSeoPolicy(new PublicContext(), 'prod', 'https://wikiformation.fr/');
        self::assertSame('https://wikiformation.fr', $policy->canonicalOrigin(Request::create('https://untrusted.example/fr/')));
    }

    public function testSearchPrivateAndUntranslatedPagesAreNoindexButTrackingIsNot(): void
    {
        $policy = new PublicSeoPolicy(new PublicContext(), 'prod', 'https://wikiformation.fr');
        foreach ([
            ['/fr/catalogue?q=excel', 'app_public_catalogue.fr'],
            ['/fr/formation?destination=4', 'app_public_formation.fr'],
            ['/fr/formation?enginType[]=grue', 'app_public_formation.fr'],
            ['/en/catalogue', 'app_public_catalogue.en'],
            ['/fr/administrateur/1/session', 'app_admin_session_index.fr'],
            ['/fr/cours-en-ligne/bibliotheque', 'app_market_library.fr'],
            ['/fr/formation/excel/reservation', 'app_public_reservation_new.fr'],
            ['/fr/new-unreviewed-public-page', 'unknown_route.fr'],
        ] as [$path, $route]) {
            self::assertTrue($policy->shouldNoIndex($this->request($path, $route)), $path);
        }
        self::assertFalse($policy->shouldNoIndex($this->request('/fr/catalogue?utm_source=newsletter&gclid=test', 'app_public_catalogue.fr')));
        self::assertFalse($policy->shouldNoIndex($this->request('/fr/formation?q=&from=&enginType[]=', 'app_public_formation.fr')));
        self::assertFalse($policy->shouldNoIndex($this->request('/fr/cours-en-ligne?page=2', 'app_market_catalogue.fr')));
    }

    public function testRootRedirectIsPermanentAndNeverRedirectsLocalOrPostRequests(): void
    {
        $subscriber = new PublicSeoSubscriber(new PublicSeoPolicy(new PublicContext(), 'prod', 'https://wikiformation.fr'));
        $event = new RequestEvent($this->createMock(HttpKernelInterface::class), Request::create('https://wikiformation.fr/?utm_source=google'), HttpKernelInterface::MAIN_REQUEST);
        $subscriber->redirectRoot($event);
        self::assertSame(301, $event->getResponse()?->getStatusCode());
        self::assertSame('https://wikiformation.fr/fr/?utm_source=google', $event->getResponse()?->headers->get('Location'));
        foreach ([Request::create('http://localhost:8888/'), Request::create('https://wikiformation.fr/', 'POST'), Request::create('https://wikiformation.fr/fr/')] as $request) {
            $event = new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
            $subscriber->redirectRoot($event);
            self::assertNull($event->getResponse());
        }
    }

    public function testHeadersPreserveExistingPrivateDirectivesAndProtectErrors(): void
    {
        $subscriber = new PublicSeoSubscriber(new PublicSeoPolicy(new PublicContext(), 'prod', 'https://wikiformation.fr'));
        $request = $this->request('/fr/administrateur/1/session', 'app_admin_session_index.fr');
        $response = new Response('', 200, ['X-Robots-Tag' => 'noindex, nofollow, noarchive']);
        $subscriber->indexingHeaders(new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response));
        self::assertSame('noindex, nofollow, noarchive', $response->headers->get('X-Robots-Tag'));

        $request = $this->request('/fr/formation/absente', 'app_public_show.fr');
        $response = new Response('', 404);
        $subscriber->indexingHeaders(new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response));
        self::assertSame('noindex, follow', $response->headers->get('X-Robots-Tag'));
        $response = new Response('', 200);
        $subscriber->indexingHeaders(new ResponseEvent($this->createMock(HttpKernelInterface::class), $this->request('/fr/catalogue', 'app_public_catalogue.fr'), HttpKernelInterface::MAIN_REQUEST, $response));
        self::assertFalse($response->headers->has('X-Robots-Tag'));
    }

    private function request(string $path, string $route): Request
    {
        $request = Request::create('https://wikiformation.fr' . $path);
        $request->attributes->set('_route', $route);
        return $request;
    }
}
