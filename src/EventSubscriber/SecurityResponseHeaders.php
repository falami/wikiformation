<?php
declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/** Conservative browser protections, preserving stricter document-specific policies. */
#[AsEventListener(event: 'kernel.response', priority: -16)]
final class SecurityResponseHeaders
{
    public function __construct(private readonly ?TokenStorageInterface $tokens = null) {}

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) return;
        $response = $event->getResponse();
        $headers = $response->headers;
        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route', '');
        // These responses can contain identity, private documents or bearer links,
        // including authentication failures and redirects before login.
        $sensitivePath = preg_match('#^/(?:fr/|en/)?(?:administrateur|admin|super|formateur|stagiaire|entreprise|of|commercial|opco|notifications|compte|account|workspace|billing|login|logout|register|reset-password|verify/email|2fa|dossier-formation|signature-convention|reponse-proposition|presence-participant|appreciation-participant)(?:/|$)#', $request->getPathInfo()) === 1;
        $signedRequest = $request->attributes->has('token') || $request->query->has('signature');
        $authenticated = $this->tokens?->getToken()?->getUser() instanceof UserInterface;
        // Public static bundles remain cacheable even for signed-in visitors.
        $publicAsset = str_starts_with($route, 'app_recruitment_asset');
        if (!$publicAsset && ($sensitivePath || $signedRequest || $authenticated || $route === 'app_protected_upload')) {
            $headers->set('Cache-Control', 'private, no-store, max-age=0');
            $headers->set('Pragma', 'no-cache');
            $headers->set('Expires', '0');
            $headers->remove('ETag');
            $headers->remove('Last-Modified');
            $headers->set('Referrer-Policy', 'no-referrer');
            $headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        }
        foreach ([
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Frame-Options' => 'SAMEORIGIN',
        ] as $name => $value) {
            if (!$headers->has($name)) $headers->set($name, $value);
        }
    }
}
