<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\Support\ErrorReport;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ErrorReportSubscriber implements EventSubscriberInterface
{
    public function __construct(private ErrorReport $reports, private RequestStack $requests, #[Autowire('%kernel.environment%')] private string $environment) {}

    public static function getSubscribedEvents(): array
    {
        return ['kernel.exception' => ['capture', -64], 'kernel.request' => ['copyContext', 256], 'kernel.response' => ['preventCaching', -32]];
    }

    public function capture(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest() || $this->environment !== 'prod') return;
        $error = $event->getThrowable();
        $status = $error instanceof HttpExceptionInterface ? $error->getStatusCode() : 500;
        try {
            $event->getRequest()->attributes->set('_error_report', $this->reports->capture($error, $event->getRequest(), $status));
        } catch (\Throwable) {
            // Reporting must never prevent the original error page from rendering.
        }
    }

    public function preventCaching(ResponseEvent $event): void
    {
        if ($event->getRequest()->attributes->has('_error_report')) {
            $event->getResponse()->headers->set('Cache-Control', 'no-store, private');
        }
    }

    public function copyContext(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            $event->getRequest()->attributes->set('_error_report', $this->requests->getMainRequest()?->attributes->get('_error_report'));
        }
    }
}
