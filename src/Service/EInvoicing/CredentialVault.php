<?php
declare(strict_types=1);
namespace App\Service\EInvoicing;

final class CredentialVault
{
    public function __construct(private EInvoiceConfiguration $configuration) {}
    public function encrypt(#[\SensitiveParameter] array $credentials): string
    {
        $key = $this->configuration->encryptionKey() ?? throw new \RuntimeException('Clé de chiffrement de la connexion absente.');
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox(json_encode($credentials, JSON_THROW_ON_ERROR), $nonce, $key));
    }
    public function decrypt(#[\SensitiveParameter] string $encrypted): array
    {
        $key = $this->configuration->encryptionKey() ?? throw new \RuntimeException('Clé de chiffrement de la connexion absente.');
        $bytes = base64_decode($encrypted, true);
        if (!$bytes || strlen($bytes) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) throw new \RuntimeException('Connexion chiffrée illisible.');
        $value = sodium_crypto_secretbox_open(substr($bytes, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($bytes, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key);
        if ($value === false) throw new \RuntimeException('Connexion chiffrée illisible.');
        return json_decode($value, true, 16, JSON_THROW_ON_ERROR);
    }
}
