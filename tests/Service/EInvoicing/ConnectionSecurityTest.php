<?php
declare(strict_types=1);
namespace App\Tests\Service\EInvoicing;
use App\Entity\{Entite, EInvoiceConnection};
use App\Service\EInvoicing\{EInvoiceConfiguration, CredentialVault, ConnectionManager, SuperPdpClient};
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

final class ConnectionSecurityTest extends TestCase
{
    public function testMissingOrUnsafeConfigurationNeverEnablesTheConnector(): void
    {
        $key = base64_encode(random_bytes(32));
        foreach (['http://public.test/callback', 'https://user@example.test/callback', 'https://example.test/?secret=x', 'https://example.test/#fragment', '//example.test/callback'] as $uri) {
            self::assertFalse((new EInvoiceConfiguration('client', 'secret', $uri, $key))->ready());
        }
        self::assertFalse((new EInvoiceConfiguration(null, null, null, null))->ready());
        self::assertFalse((new EInvoiceConfiguration('client', 'secret', 'https://example.test/return', 'bad'))->ready());
        self::assertTrue((new EInvoiceConfiguration('client', 'secret', 'https://example.test/return', $key))->ready());
        self::assertTrue((new EInvoiceConfiguration('client', 'secret', 'http://localhost:8888/fr/return', $key))->ready());
    }
    public function testTokensAreAuthenticatedEncryptedAndWrongKeysAreRejected(): void
    {
        $vault = new CredentialVault(new EInvoiceConfiguration(null, null, null, base64_encode(random_bytes(32))));
        $tokens = ['access_token' => 'sensitive-access', 'refresh_token' => 'sensitive-refresh'];
        $encrypted = $vault->encrypt($tokens);
        self::assertNotSame($encrypted, $vault->encrypt($tokens));
        self::assertStringNotContainsString('sensitive', $encrypted);
        self::assertSame($tokens, $vault->decrypt($encrypted));
        $other = new CredentialVault(new EInvoiceConfiguration(null, null, null, base64_encode(random_bytes(32))));
        $this->expectException(\RuntimeException::class);
        $other->decrypt($encrypted);
    }
    public function testAnOrganismCannotConnectAnotherCompanyOrMixSandboxWithProduction(): void
    {
        $config = new EInvoiceConfiguration(null, null, null, null);
        $manager = new ConnectionManager($config, new CredentialVault($config), new SuperPdpClient(new MockHttpClient()), $this->createMock(EntityManagerInterface::class));
        $entity = (new Entite())->setSiret('12345678900012');
        $production = new EInvoiceConnection($entity, 'production');
        $manager->checkCompany($production, ['id' => '1', 'env' => 'production', 'number_scheme' => 'fr_siren', 'number' => '123456789']);
        foreach ([['production', '987654321'], ['sandbox', '123456789']] as [$env, $number]) {
            try { $manager->checkCompany($production, ['id' => '1', 'env' => $env, 'number_scheme' => 'fr_siren', 'number' => $number]); self::fail('Company must be rejected.'); }
            catch (\DomainException) { self::assertTrue(true); }
        }
        $sandbox = new EInvoiceConnection($entity, 'sandbox');
        $manager->checkCompany($sandbox, ['id' => 'test', 'env' => 'sandbox', 'number_scheme' => 'sandbox', 'number' => 'fictional']);
        $this->expectException(\DomainException::class);
        $sandbox->consentToBilling(1);
    }
    public function testConsentAndConnectionAreBothRequiredAndDisconnectPreservesBillingEvidence(): void
    {
        $connection = new EInvoiceConnection(new Entite(), 'production');
        $connection->connected(['id' => '1', 'number' => '123456789', 'formal_name' => 'Organisme'], 'encrypted');
        try { $connection->setAutomatic(true); self::fail('Consent is required.'); } catch (\DomainException) { self::assertFalse($connection->isAutomatic()); }
        $connection->consentToBilling(12); $connection->setAutomatic(true);
        self::assertSame(3, $connection->getUnitPriceCents()); self::assertSame(12, $connection->getBillingConsentBy());
        $enabledAt = $connection->getEnabledAt(); $consentAt = $connection->getBillingConsentAt();
        $connection->disconnect();
        self::assertNull($connection->getCredentials()); self::assertFalse($connection->isAutomatic());
        self::assertSame($enabledAt, $connection->getEnabledAt()); self::assertSame($consentAt, $connection->getBillingConsentAt());
        $this->expectException(\DomainException::class); $connection->setAutomatic(true);
    }
}
