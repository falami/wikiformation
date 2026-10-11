<?php
declare(strict_types=1);
namespace App\Service\EInvoicing;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class EInvoiceConfiguration
{
    public function __construct(
        #[Autowire('%env(default::EINVOICING_CLIENT_ID)%')] public readonly ?string $clientId,
        #[Autowire('%env(default::EINVOICING_CLIENT_SECRET)%')] public readonly ?string $clientSecret,
        #[Autowire('%env(default::EINVOICING_REDIRECT_URI)%')] public readonly ?string $redirectUri,
        #[Autowire('%env(default::EINVOICING_ENCRYPTION_KEY)%')] private readonly ?string $key,
    ) {}
    public function ready(): bool
    {
        return (bool) ($this->clientId && $this->clientSecret && $this->redirectUri && $this->validRedirect() && $this->encryptionKey());
    }
    public function encryptionKey(): ?string
    {
        $key = base64_decode($this->key ?? '', true);
        return function_exists('sodium_crypto_secretbox') && is_string($key) && strlen($key) === 32 ? $key : null;
    }
    private function validRedirect(): bool
    {
        $url = parse_url($this->redirectUri ?? '');
        return isset($url['host']) && !isset($url['user']) && !isset($url['pass']) && !isset($url['query']) && !isset($url['fragment'])
            && (($url['scheme'] ?? '') === 'https' || (($url['scheme'] ?? '') === 'http' && in_array($url['host'], ['localhost', '127.0.0.1'], true)));
    }
}
