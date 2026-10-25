<?php

declare(strict_types=1);

namespace App\Tests\Service\Support;

use App\Service\Support\ErrorReport;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;

final class ErrorReportTest extends TestCase
{
    public function testReportLinksLogsWithoutEmailingSensitiveRequestDataAndSendsOnlyOnce(): void
    {
        $cache = new ArrayAdapter();
        $transport = $this->createMock(TransportInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $request = Request::create('/private/secret-path?token=secret-query', 'POST', ['password' => 'secret-password']);
        $request->attributes->set('_route', 'app_example');
        $exception = new \RuntimeException('secret-exception');
        $logger->expects(self::once())->method('error')->with('Incident {reference}', self::callback(fn(array $data) => $data['exception'] === $exception && str_starts_with($data['reference'], 'WK-')));
        $transport->expects(self::once())->method('send')->with(self::callback(function (Email $email): bool {
            self::assertSame('contact@wikiformation.fr', $email->getTo()[0]->getAddress());
            self::assertStringContainsString('app_example', $email->getTextBody());
            self::assertStringContainsString('WK-', $email->getTextBody());
            self::assertStringNotContainsString('secret-', $email->getTextBody());
            return true;
        }));
        $service = new ErrorReport($cache, $transport, $logger);
        $report = $service->capture($exception, $request, 500);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $report['token']);
        self::assertSame('sent', $service->send($report['token']));
        self::assertSame('sent', $service->send($report['token']));
        $cache->clear();
        self::assertSame('expired', $service->send($report['token']));
    }

    public function testInvalidOrForgedTokensNeverSendMail(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $transport->expects(self::never())->method('send');
        $service = new ErrorReport(new ArrayAdapter(), $transport, $this->createMock(LoggerInterface::class));
        self::assertSame('expired', $service->send('invalid'));
        self::assertSame('expired', $service->send(str_repeat('a', 64)));
    }

    public function testTransportFailureAllowsRetry(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $transport->expects(self::exactly(2))->method('send')->willReturnCallback(function () { static $attempt = 0; if (++$attempt === 1) throw new \RuntimeException('offline'); return null; });
        $service = new ErrorReport(new ArrayAdapter(), $transport, $this->createMock(LoggerInterface::class));
        $report = $service->capture(new \RuntimeException(), Request::create('/'), 500);
        try { $service->send($report['token']); self::fail('Expected failure'); } catch (\RuntimeException $e) { self::assertSame('offline', $e->getMessage()); }
        self::assertSame('sent', $service->send($report['token']));
    }
}
