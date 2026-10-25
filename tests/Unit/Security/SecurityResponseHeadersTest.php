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
}
