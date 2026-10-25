<?php

declare(strict_types=1);

namespace App\Tests\Service\Support;

use App\EventSubscriber\ErrorReportSubscriber;
use App\Service\Support\ErrorReport;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class ErrorPageTest extends TestCase
{
    public function testProductionContextReachesErrorSubrequestAndIsNotCacheable(): void
    {
        $stack = new RequestStack();
        $request = Request::create('/missing');
        $stack->push($request);
        $kernel = $this->createMock(HttpKernelInterface::class);
        $transport = $this->createMock(TransportInterface::class);
        $transport->expects(self::never())->method('send');
        $reports = new ErrorReport(new ArrayAdapter(), $transport, new NullLogger());
        $listener = new ErrorReportSubscriber($reports, $stack, 'prod');
        $listener->capture(new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new NotFoundHttpException('private-message')));
        self::assertArrayHasKey('token', $request->attributes->get('_error_report'));
        $subrequest = Request::create('/missing');
        $listener->copyContext(new RequestEvent($kernel, $subrequest, HttpKernelInterface::SUB_REQUEST));
        self::assertSame($request->attributes->get('_error_report'), $subrequest->attributes->get('_error_report'));
        $response = new Response();
        $listener->preventCaching(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $devRequest = Request::create('/missing');
        (new ErrorReportSubscriber($reports, $stack, 'dev'))->capture(new ExceptionEvent($kernel, $devRequest, HttpKernelInterface::MAIN_REQUEST, new NotFoundHttpException()));
        self::assertFalse($devRequest->attributes->has('_error_report'));
    }

    public function testAllErrorPagesRenderSafeContentAndReportingForm(): void
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3).'/templates'), ['strict_variables' => true]);
        $twig->addFunction(new TwigFunction('asset', fn($path) => '/'.$path));
        $twig->addFunction(new TwigFunction('path', fn($route) => '/'.$route));
        foreach ([403, 404, 405, 429, 500, 503, 502] as $status) {
            foreach ([null, ['reference' => 'WK-TEST', 'token' => str_repeat('a', 64)]] as $report) {
                $request = Request::create('/');
                $request->attributes->set('_error_report', $report);
                $template = in_array($status, [403, 404, 500]) ? 'error'.$status : 'error';
                $html = $twig->render('bundles/TwigBundle/Exception/'.$template.'.html.twig', ['app' => ['request' => $request], 'status_code' => $status, 'status_text' => 'secret exception details']);
                self::assertStringContainsString('ERREUR '.$status, $html);
                self::assertStringNotContainsString('secret exception details', $html);
                self::assertStringContainsString('contact@wikiformation.fr', $html);
                self::assertSame($report !== null, str_contains($html, 'name="token"'));
            }
        }
        foreach (['sent', 'expired', 'limited', 'failed'] as $result) {
            $html = $twig->render('error/report.html.twig', ['result' => $result]);
            self::assertSame($result === 'sent', str_contains($html, 'Votre rapport a été transmis'));
        }
    }
}
