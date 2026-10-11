<?php
declare(strict_types=1);
namespace App\Service\EInvoicing;

use App\Entity\EInvoiceConnection;
use Doctrine\ORM\EntityManagerInterface;

final class ConnectionManager
{
    public function __construct(private EInvoiceConfiguration $configuration, private CredentialVault $vault, private SuperPdpClient $api, private EntityManagerInterface $em) {}

    public function connect(EInvoiceConnection $connection, #[\SensitiveParameter] array $tokens): void
    {
        $access = $this->validTokens($tokens);
        $session = $this->api->session($access['access_token']);
        if (($session['company_verification_status'] ?? '') !== 'verified') throw new \DomainException('La plateforme doit d’abord valider votre organisme et votre habilitation.');
        $company = $this->api->company($access['access_token']);
        $this->checkCompany($connection, $company);
        $other = $this->em->getRepository(EInvoiceConnection::class)->findOneBy(['companyId' => (string) $company['id'], 'environment' => $connection->getEnvironment()]);
        if ($other && $other->getId() !== $connection->getId()) throw new \DomainException('Ce compte de plateforme est déjà rattaché à un autre espace. Chaque espace doit conserver sa propre connexion.');
        $connection->connected($company, $this->vault->encrypt($access));
    }

    /** Call under the connection's database lock: rotating refresh tokens cannot be reused concurrently. */
    public function accessToken(EInvoiceConnection $connection): string
    {
        if (!$connection->isVerified() || !$connection->getCredentials()) throw new \DomainException('Organisme non connecté à la plateforme.');
        $data = $this->vault->decrypt($connection->getCredentials());
        if (($data['expires_at'] ?? 0) <= time() + 120) {
            if (empty($data['refresh_token'])) throw new \DomainException('Reconnectez votre organisme à la plateforme.');
            $data = $this->validTokens($this->api->refreshToken((string) $this->configuration->clientId, (string) $this->configuration->clientSecret, $data['refresh_token']));
            $connection->setCredentials($this->vault->encrypt($data));
            $this->em->flush();
        }
        return $data['access_token'];
    }
    public function checkCompany(EInvoiceConnection $connection, array $company): void
    {
        if (empty($company['id']) || ($company['env'] ?? '') !== $connection->getEnvironment()) throw new \DomainException('Le compte de test et le compte de production ne peuvent pas être intervertis.');
        if ($connection->getCompanyId() && (string) $company['id'] !== $connection->getCompanyId()) throw new \DomainException('Le compte distant ne correspond plus à cet organisme.');
        if ($connection->getEnvironment() === 'production') {
            $siret = preg_replace('/\s+/', '', $connection->getEntite()->getSiret() ?? '');
            if (!preg_match('/^\d{14}$/', $siret) || ($company['number_scheme'] ?? '') !== 'fr_siren' || ($company['number'] ?? '') !== substr($siret, 0, 9)) throw new \DomainException('Le SIREN du compte distant doit correspondre à celui de cet organisme.');
        } elseif (($company['number_scheme'] ?? '') !== 'sandbox') throw new \DomainException('Un compte fictif sandbox est requis pour les essais.');
    }
    private function validTokens(#[\SensitiveParameter] array $tokens): array
    {
        if (!is_string($tokens['access_token'] ?? null) || $tokens['access_token'] === '' || !is_string($tokens['refresh_token'] ?? null) || (int) ($tokens['expires_in'] ?? 0) <= 0) throw new \DomainException('La plateforme n’a pas fourni une connexion renouvelable valide.');
        return ['access_token' => $tokens['access_token'], 'refresh_token' => $tokens['refresh_token'], 'expires_at' => time() + (int) $tokens['expires_in']];
    }
}
