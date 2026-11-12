<?php

declare(strict_types=1);

namespace App\Tests\Service\Security;

use App\Service\Security\ContactCaptcha;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\{Request, RequestStack};
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class ContactCaptchaTest extends TestCase
{
    private function service(callable $response, string $siteKey = '0x-site', string $secret = 'secret'): ContactCaptcha
    {
        $request = Request::create('https://wikiformation.fr/fr/contact');
        $session = new Session(new MockArraySessionStorage());
        $session->set('contact_turnstile_context', 'session-context');
        $request->setSession($session);
        $stack = new RequestStack();
        $stack->push($request);
        return new ContactCaptcha($stack, new MockHttpClient($response), new ArrayAdapter(),
            new LockFactory(new InMemoryStore()), new NullLogger(), $siteKey, $secret);
    }

    private function success(): array
    {
        return ['success' => true, 'hostname' => 'wikiformation.fr', 'action' => 'public_contact', 'cdata' => 'session-context'];
    }

    public function testValidTokenIsCheckedAndCannotBeReplayed(): void
    {
        $calls = 0;
        $captcha = $this->service(function ($method, $url, $options) use (&$calls) {
            ++$calls;
            self::assertSame('POST', $method);
            self::assertSame('https://challenges.cloudflare.com/turnstile/v0/siteverify', $url);
            parse_str($options['body'], $body);
            self::assertSame(['secret' => 'secret', 'response' => 'token'], $body);
            return new MockResponse(json_encode($this->success()));
        });
        self::assertSame('0x-site', $captcha->configuration()['siteKey']);
        self::assertTrue($captcha->verify('token'));
        self::assertFalse($captcha->verify('token'));
        self::assertSame(1, $calls);
    }

    public function testInvalidProviderResponsesFailClosed(): void
    {
        foreach ([
            ['success' => false, 'error-codes' => ['timeout-or-duplicate']],
            ['hostname' => 'attacker.test'],
            ['action' => 'login'],
            ['cdata' => 'another-session'],
            ['success' => 'true'],
        ] as $override) {
            $captcha = $this->service(fn() => new MockResponse(json_encode($override + $this->success())));
            self::assertFalse($captcha->verify('token'));
        }
        foreach ([new MockResponse('not json'), new MockResponse('{}', ['http_code' => 503])] as $response) {
            self::assertFalse($this->service(fn() => $response)->verify('token'));
        }
        self::assertFalse($this->service(static function () { throw new \RuntimeException('Network unavailable'); })->verify('token'));
    }

    public function testMissingConfigurationAndTestKeysAreNotAllowedInProduction(): void
    {
        $never = static function () { self::fail('Provider must not be called.'); };
        foreach ([['', 'secret'], ['site', ''], ['1x00000000000000000000AA', 'secret'], ['site', '2x0000000000000000000000000000000AA']] as [$site, $secret]) {
            $captcha = $this->service($never, $site, $secret);
            self::assertSame('', $captcha->configuration()['siteKey']);
            self::assertFalse($captcha->verify('token'));
        }
        self::assertFalse($this->service($never)->verify(''));
        self::assertFalse($this->service($never)->verify(str_repeat('a', 2049)));
    }
}
