<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\Public\PublicContext;
use App\Service\Public\PublicHostResolver;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class PublicHostRequestSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly PublicContext $publicContext,
        private readonly PublicHostResolver $publicHostResolver,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 50],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $host = (string) $request->getHost();

        $this->publicContext->setCurrentHost($host);
        $this->publicContext->setPublicHost(
            $this->publicHostResolver->resolve($host)
        );
    }
}