<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\Seo\PublicSeoPolicy;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class PublicSeoSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly PublicSeoPolicy $policy) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['redirectRoot', 16], KernelEvents::RESPONSE => ['indexingHeaders', -32]];
    }

    public function redirectRoot(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) return;
        $request = $event->getRequest();
        if ($request->getPathInfo() !== '/' || !$this->policy->isIndexableHost($request)
            || !in_array($request->getMethod(), ['GET', 'HEAD'], true)) return;

        $query = $request->getQueryString();
        $event->setResponse(new RedirectResponse($this->policy->canonicalOrigin($request) . '/fr/' . ($query ? '?' . $query : ''), 301));
    }

    public function indexingHeaders(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) return;
        $response = $event->getResponse();
        if ($response->getStatusCode() < 400 && !$this->policy->shouldNoIndex($event->getRequest())) return;

        // Preserve stricter nofollow/noarchive directives set by security/document handlers.
        $existing = (string) $response->headers->get('X-Robots-Tag', '');
        if (!str_contains(strtolower($existing), 'noindex')) {
            $response->headers->set('X-Robots-Tag', $existing !== '' ? $existing . ', noindex' : 'noindex, follow');
        }
    }
}
