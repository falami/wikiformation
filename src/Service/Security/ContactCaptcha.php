<?php

declare(strict_types=1);

namespace App\Service\Security;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Lock\LockFactory;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Fail closed; never trust a widget's client-side success alone. */
final class ContactCaptcha
{
    public function __construct(
        private readonly RequestStack $requests,
        private readonly HttpClientInterface $http,
        #[Autowire(service: 'cache.app')] private readonly CacheItemPoolInterface $cache,
        private readonly LockFactory $locks,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'TURNSTILE_SITE_KEY')] private readonly string $siteKey = '',
        #[Autowire(env: 'TURNSTILE_SECRET_KEY')] private readonly string $turnstileSecret = '',
        #[Autowire(param: 'kernel.environment')] private readonly string $environment = 'prod',
    ) {}

    public function configuration(): array
    {
        $session = $this->requests->getSession();
        if (!$session->has('contact_turnstile_context')) {
            $session->set('contact_turnstile_context', bin2hex(random_bytes(24)));
        }
        return [
            'siteKey' => $this->configured() ? $this->siteKey : '',
            'context' => $session->get('contact_turnstile_context'),
        ];
    }

    private function configured(): bool
    {
        if ($this->siteKey === '' || $this->turnstileSecret === '') return false;
        // Cloudflare's public demonstration keys must never authorize production mail.
        return $this->environment !== 'prod'
            || (!preg_match('/^[123]x/', $this->siteKey) && !preg_match('/^[123]x/', $this->turnstileSecret));
    }

    public function verify(string $token): bool
    {
        $request = $this->requests->getMainRequest();
        if (!$this->configured() || !$request || $token === '' || strlen($token) > 2048) return false;
        $context = $request->getSession()->get('contact_turnstile_context');
        if (!is_string($context) || $context === '') return false;
        $key = 'contact_turnstile_used_'.hash('sha256', $token);
        $lock = $this->locks->createLock($key, 30);
        try {
            if (!$lock->acquire()) return false;
            $item = $this->cache->getItem($key);
            if ($item->isHit()) return false;
            $result = $this->http->request('POST', 'https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'body' => ['secret' => $this->turnstileSecret, 'response' => $token],
                'timeout' => 5,
                'max_duration' => 8,
                'max_redirects' => 0,
            ])->toArray();
            if (($result['success'] ?? false) !== true
                || ($result['action'] ?? null) !== 'public_contact'
                || ($result['hostname'] ?? null) !== $request->getHost()
                || ($result['cdata'] ?? null) !== $context) {
                $this->logger->notice('Public contact Turnstile verification rejected.');
                return false;
            }
            $item->set(true)->expiresAfter(300);
            return $this->cache->save($item);
        } catch (\Throwable $e) {
            // Do not log provider response bodies, tokens, IPs or credentials.
            $this->logger->warning('Public contact verification unavailable.', ['type' => $e::class]);
            return false;
        } finally {
            $lock->release();
        }
    }
}
