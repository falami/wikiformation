<?php
namespace App\Tests\Unit\Security;

use App\EventSubscriber\SecurityResponseHeaders;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\HttpKernel\{HttpKernelInterface, Event\ResponseEvent};

final class SecurityResponseHeadersTest extends TestCase
{
    public function testDefaultsProtectMainResponsesAndPreserveDocumentPolicies(): void
    {
        $response = new Response();
        $event = new ResponseEvent($this->createMock(HttpKernelInterface::class), new Request(), HttpKernelInterface::MAIN_REQUEST, $response);
        (new SecurityResponseHeaders())($event);
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Frame-Options', 'DENY');
        (new SecurityResponseHeaders())($event);
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
    }

    public function testPrivatePagesAndBearerLinksCannotBeCachedOrDisclosedAsReferrers(): void
    {
        foreach (['/fr/administrateur/1/users', '/en/of/1/documents', '/fr/login', '/fr/reset-password/reset/secret', '/fr/dossier-formation/1/token', '/fr/signature-convention/token'] as $path) {
            $response = new Response('private', 200, ['Cache-Control' => 'public, max-age=3600', 'ETag' => '"old"', 'Last-Modified' => 'Wed, 21 Oct 2015 07:28:00 GMT']);
            $event = new ResponseEvent($this->createMock(HttpKernelInterface::class), Request::create($path), HttpKernelInterface::MAIN_REQUEST, $response);
            (new SecurityResponseHeaders())($event);
            self::assertTrue($response->headers->hasCacheControlDirective('no-store'), $path);
            self::assertTrue($response->headers->hasCacheControlDirective('private'));
            self::assertFalse($response->headers->hasCacheControlDirective('public'));
            self::assertFalse($response->headers->has('ETag'));
            self::assertFalse($response->headers->has('Last-Modified'));
            self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
            self::assertSame('noindex, nofollow, noarchive', $response->headers->get('X-Robots-Tag'));
        }
    }

    public function testSignedInResponseIsPrivateRegardlessOfItsRouteButPublicBundlesArePreserved(): void
    {
        $user = $this->createMock(\Symfony\Component\Security\Core\User\UserInterface::class);
        $token = $this->createMock(\Symfony\Component\Security\Core\Authentication\Token\TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $storage = $this->createMock(\Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface::class);
        $storage->method('getToken')->willReturn($token);
        $subscriber = new SecurityResponseHeaders($storage);
        $request = Request::create('/fr/cours-en-ligne/mes-donnees');
        $response = new Response('{}');
        $subscriber(new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $request = Request::create('/fr/recrutement-assets/recruitment.css');
        $request->attributes->set('_route', 'app_recruitment_asset.fr');
        $response = new Response('', 200, ['Cache-Control' => 'public, max-age=3600']);
        $subscriber(new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response));
        self::assertFalse($response->headers->hasCacheControlDirective('no-store'));
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
    }

    public function testPublicMarketingResponseKeepsItsCachePolicy(): void
    {
        $response = new Response('', 200, ['Cache-Control' => 'public, max-age=600']);
        (new SecurityResponseHeaders())(new ResponseEvent($this->createMock(HttpKernelInterface::class), Request::create('/fr/'), HttpKernelInterface::MAIN_REQUEST, $response));
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        self::assertFalse($response->headers->hasCacheControlDirective('no-store'));
    }
}
