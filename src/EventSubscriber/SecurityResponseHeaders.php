<?php
declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/** Conservative browser protections, preserving stricter document-specific policies. */
#[AsEventListener(event: 'kernel.response', priority: -16)]
final class SecurityResponseHeaders
{
    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) return;
        $headers = $event->getResponse()->headers;
        foreach ([
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Frame-Options' => 'SAMEORIGIN',
        ] as $name => $value) {
            if (!$headers->has($name)) $headers->set($name, $value);
        }
    }
}
