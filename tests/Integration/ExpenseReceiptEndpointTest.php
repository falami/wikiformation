<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\{Depense, Entite, Utilisateur, UtilisateurEntite};
use App\Service\Depense\ReceiptOcrProviderInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ExpenseReceiptEndpointTest extends KernelTestCase
{
    private array $database;
    private $em;
    private $client;
    private string $base;
    private array $files = [];

    protected function setUp(): void
    {
        $this->database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $user = (new Utilisateur())->setEmail('receipt-endpoint@example.test')->setPassword('unused')->setPrenom('Test')->setNom('Receipt');
        $entite = (new Entite())->setNom('Receipt endpoint tenant')->setPublic(false)->setCreateur($user);
        $this->em->persist($user);
        $this->em->persist($entite);
        $this->em->flush();
        $user->setEntite($entite);
        $this->em->persist((new UtilisateurEntite())->setUtilisateur($user)->setEntite($entite)->setCreateur($user)->setRoles(['TENANT_ADMIN'])->setStatus('active'));
        $this->em->persist((new \App\Entity\Billing\EntiteSubscription())->setEntite($entite)->setStatus('active'));
        $this->em->flush();
        $this->client = self::getContainer()->get('test.client');
        $this->client->disableReboot();
        $this->client->catchExceptions(false);
        $this->client->loginUser($user);
        $this->base = '/fr/administrateur/'.$entite->getId().'/depense';
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) unlink($file);
        }
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $index => $name) {
            if ($this->database[$index] === null) unset($GLOBALS['_'.$name]['DATABASE_URL']);
            else $GLOBALS['_'.$name]['DATABASE_URL'] = $this->database[$index];
        }
    }

    private function token(): string
    {
        $this->client->request('GET', $this->base.'/new');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, preg_match('/window\.WikiExpenseReceipt\(\{.*?token:\s*("[^"]*")/s', $this->client->getResponse()->getContent(), $matches));
        return json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
    }

    private function upload(?string $contents = null, int $error = UPLOAD_ERR_OK): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'receipt-endpoint-');
        $this->files[] = $path;
        file_put_contents($path, $contents ?? base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6WQAAAABJRU5ErkJggg=='));
        return new UploadedFile($path, 'receipt.png', 'image/png', $error, true);
    }

    private function provider(?string $text = null, ?\Throwable $error = null, bool $called = true): void
    {
        $provider = $this->createMock(ReceiptOcrProviderInterface::class);
        $expectation = $provider->expects($called ? self::once() : self::never())->method('extractText');
        if ($error !== null) $expectation->willThrowException($error);
        else $expectation->willReturn(['text' => $text ?? '', 'lines' => []]);
        self::getContainer()->set(ReceiptOcrProviderInterface::class, $provider);
    }

    private function scan(?UploadedFile $file, ?string $token = null): array
    {
        $this->client->request('POST', $this->base.'/scan-receipt', ['_token' => $token ?? $this->token()], $file ? ['file' => $file] : [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertSame(0, $this->em->getRepository(Depense::class)->count([]), 'Reading a receipt must not create an expense.');
        self::assertStringStartsWith('application/json', $this->client->getResponse()->headers->get('Content-Type'));
        return json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testSuccessfulScanPreservesUnknownTaxAsNull(): void
    {
        $this->provider("Commerce Exemple\nDate 26/10/2026\nTotal TTC 12,00");
        $json = $this->scan($this->upload());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertTrue($json['ok']);
        self::assertSame(1200, $json['proposal']['montantTtcCents']);
        self::assertSame('2026-10-26', $json['proposal']['date']);
        self::assertNull($json['proposal']['montantHtCents']);
        self::assertNull($json['proposal']['montantTvaCents']);
        self::assertNull($json['proposal']['tauxTva']);
        self::assertFalse($json['proposal']['multipleVatRates']);
        self::assertNotEmpty($json['proposal']['warnings']);
        self::assertArrayNotHasKey('debug', $json['proposal']);
    }

    public function testSuccessfulScanKeepsMixedVatTotalsWithoutInventingOneRate(): void
    {
        $this->provider("Total TTC 23,00\nTVA 20% 2,00\nTVA 10% 1,00");
        $json = $this->scan($this->upload());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertTrue($json['ok']);
        self::assertSame(2300, $json['proposal']['montantTtcCents']);
        self::assertSame(2000, $json['proposal']['montantHtCents']);
        self::assertSame(300, $json['proposal']['montantTvaCents']);
        self::assertNull($json['proposal']['tauxTva']);
        self::assertTrue($json['proposal']['multipleVatRates']);
        self::assertNotEmpty($json['proposal']['warnings']);
    }

    public function testProviderFailureIsGenericAndDoesNotExposeConfiguration(): void
    {
        $this->provider(error: new \RuntimeException('private-key secret-token /private/credentials.json'));
        $json = $this->scan($this->upload());
        self::assertSame(502, $this->client->getResponse()->getStatusCode());
        self::assertFalse($json['ok']);
        self::assertStringContainsString('indisponible', $json['message']);
        self::assertStringNotContainsString('secret-token', $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('/private/', $this->client->getResponse()->getContent());
        self::assertArrayNotHasKey('proposal', $json);
    }

    public function testProviderConfigurationErrorIsAlsoGeneric(): void
    {
        $this->provider(error: new \InvalidArgumentException('secret-token /private/credentials.json'));
        $json = $this->scan($this->upload());
        self::assertSame(502, $this->client->getResponse()->getStatusCode());
        self::assertFalse($json['ok']);
        self::assertStringNotContainsString('secret-token', $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('/private/', $this->client->getResponse()->getContent());
    }

    public function testUnreadableTextReturnsActionableValidationFailure(): void
    {
        $this->provider(' ');
        $json = $this->scan($this->upload());
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertFalse($json['ok']);
        self::assertStringContainsString('Aucun texte lisible', $json['message']);
    }

    public function testInvalidFileIsRejectedBeforeCallingProvider(): void
    {
        $this->provider(called: false);
        $json = $this->scan($this->upload('<?php echo "not an image";'));
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertFalse($json['ok']);
    }

    public function testPartialUploadIsRejectedBeforeCallingProvider(): void
    {
        $this->provider(called: false);
        $json = $this->scan($this->upload('partial', UPLOAD_ERR_PARTIAL));
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertFalse($json['ok']);
    }

    public function testMissingFileIsRejectedBeforeCallingProvider(): void
    {
        $this->provider(called: false);
        $json = $this->scan(null);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertFalse($json['ok']);
    }

    public function testInvalidCsrfIsRejectedBeforeCallingProvider(): void
    {
        $this->provider(called: false);
        $json = $this->scan($this->upload(), 'wrong-token');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertFalse($json['ok']);
    }
}
