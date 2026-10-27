<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Logging\PrivacyProcessor;
use Monolog\{Level, LogRecord};
use PHPUnit\Framework\TestCase;

final class PrivacyProcessorTest extends TestCase
{
    public function testProductionRecordRetainsDiagnosticLocationsWithoutPayloads(): void
    {
        $secret = 'person@example.test';
        $exception = new \RuntimeException('SELECT email FROM users WHERE email = '.$secret.' password=secret');
        $record = new LogRecord(new \DateTimeImmutable(), 'request', Level::Error,
            'Uncaught PHP Exception RuntimeException: '.$exception->getMessage(),
            ['exception' => $exception, 'reference' => 'WK-ABCDEF', 'route' => 'app_session', 'parameters' => ['email' => $secret],
                'request_uri' => 'https://example.test/fr/private?token=secret', 'nested' => ['authorization' => 'Bearer secret', 'email' => $secret]],
            ['cookie' => 'session=secret']);
        $clean = (new PrivacyProcessor())($record);
        self::assertSame('WK-ABCDEF', $clean->context['reference']);
        self::assertSame('app_session', $clean->context['route']);
        self::assertSame(\RuntimeException::class, $clean->context['exception']['class']);
        self::assertSame(__FILE__, $clean->context['exception']['file']);
        self::assertIsInt($clean->context['exception']['line']);
        self::assertStringNotContainsString('secret', json_encode($clean));
        self::assertStringNotContainsString($secret, json_encode($clean));
        self::assertStringNotContainsString('SELECT', json_encode($clean));
        self::assertSame('[redacted]', $clean->context['parameters']);
        self::assertSame('[redacted]', $clean->extra['cookie']);
        self::assertSame($exception, $record->context['exception'], 'Original exception is not modified.');
    }

    public function testMessagesScrubBearerUrlsConnectionsEmailAndOpaqueTokens(): void
    {
        $message = 'smtp://user:password@host:25 https://example.test/path?q=secret person@example.test Bearer secret abcdefabcdefabcdefabcdefabcdefabcdef sk_test_123456';
        $record = new LogRecord(new \DateTimeImmutable(), 'app', Level::Warning, $message);
        $clean = (new PrivacyProcessor())($record);
        foreach (['password', 'secret', 'person@example', 'abcdef', 'sk_test_'] as $fragment) self::assertStringNotContainsString($fragment, $clean->message);
    }
}
