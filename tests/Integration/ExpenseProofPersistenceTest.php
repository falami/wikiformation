<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\{Depense, Entite, Utilisateur, UtilisateurEntite};
use App\Service\Depense\DepenseUploader;
use App\Service\FileUploader;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ExpenseProofPersistenceTest extends KernelTestCase
{
    private array $database;
    private $em;
    private $client;
    private Utilisateur $user;
    private string $base;
    private string $storage;
    private array $temporaryFiles = [];
    private int $uploadCalls = 0;
    private ?int $failOnUpload = null;

    protected function setUp(): void
    {
        $this->database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->user = (new Utilisateur())->setEmail('proof-test@example.test')->setPassword('unused')->setPrenom('Test')->setNom('Proof');
        $entite = (new Entite())->setNom('Proof tenant')->setPublic(false)->setCreateur($this->user);
        $this->em->persist($this->user);
        $this->em->persist($entite);
        $this->em->flush();
        $this->user->setEntite($entite);
        $this->em->persist((new UtilisateurEntite())->setUtilisateur($this->user)->setEntite($entite)->setCreateur($this->user)->setRoles(['TENANT_ADMIN'])->setStatus('active'));
        $this->em->persist((new \App\Entity\Billing\EntiteSubscription())->setEntite($entite)->setStatus('active'));
        $this->em->flush();

        // Every file written by this test goes to its own temporary directory.
        $this->storage = sys_get_temp_dir().'/expense-proofs-'.bin2hex(random_bytes(8));
        mkdir($this->storage, 0700);
        $fileUploader = $this->createMock(FileUploader::class);
        $fileUploader->method('upload')->willReturnCallback(function (UploadedFile $file, string $directory): string {
            self::assertSame($this->storage, $directory);
            ++$this->uploadCalls;
            if ($this->uploadCalls === $this->failOnUpload) {
                throw new \RuntimeException('Simulated proof storage failure');
            }
            $name = 'proof-'.$this->uploadCalls.'.png';
            $file->move($directory, $name);
            return $name;
        });
        self::getContainer()->set(DepenseUploader::class, new DepenseUploader($fileUploader, $this->storage, '/isolated-test-proofs'));

        $this->client = self::getContainer()->get('test.client');
        $this->client->disableReboot();
        $this->client->catchExceptions(false);
        $this->client->loginUser($this->user);
        $this->base = '/fr/administrateur/'.$entite->getId().'/depense';
    }

    protected function tearDown(): void
    {
        foreach (array_merge($this->temporaryFiles, glob($this->storage.'/*') ?: []) as $path) {
            if (is_file($path)) unlink($path);
        }
        if (is_dir($this->storage)) rmdir($this->storage);
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $index => $name) {
            if ($this->database[$index] === null) unset($GLOBALS['_'.$name]['DATABASE_URL']);
            else $GLOBALS['_'.$name]['DATABASE_URL'] = $this->database[$index];
        }
    }

    private function upload(?string $contents = null, string $name = 'receipt.png'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'expense-proof-input-');
        $this->temporaryFiles[] = $path;
        file_put_contents($path, $contents ?? base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6WQAAAABJRU5ErkJggg=='));
        return new UploadedFile($path, $name, null, null, true);
    }

    private function submitExpense(string $suffix): void
    {
        $page = $this->client->request('GET', $this->base.$suffix);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $form = $page->filter('form#depense')->form();
        foreach (['libelle' => 'Justificatif test', 'dateDepense' => '26/10/2026', 'montantHtCents' => '10,00', 'montantTvaCents' => '2,00', 'montantTtcCents' => '12,00'] as $field => $value) {
            $form['depense['.$field.']'] = $value;
        }
        $this->client->request('POST', $this->base.$suffix, $form->getPhpValues(), ['depense' => ['justificatifFile' => $this->upload()]]);
    }

    private function savedProof(int $expenseId): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT justificatif_path FROM depense WHERE id = ?', [$expenseId]);
    }

    public function testCreationStoresProofAndSuccessfulReplacementRemovesOnlyPreviousProof(): void
    {
        $this->submitExpense('/new');
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $expense = $this->em->getRepository(Depense::class)->findOneBy([]);
        self::assertNotNull($expense);
        $first = $this->savedProof($expense->getId());
        self::assertSame('proof-1.png', $first);
        self::assertFileExists($this->storage.'/'.$first);

        $this->submitExpense('/'.$expense->getId().'/edit');
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $replacement = $this->savedProof($expense->getId());
        self::assertSame('proof-2.png', $replacement);
        self::assertFileExists($this->storage.'/'.$replacement);
        self::assertFileDoesNotExist($this->storage.'/'.$first);
        self::assertCount(1, glob($this->storage.'/*'));
        self::assertSame(1, $this->em->getRepository(Depense::class)->count([]));
    }

    public function testFailedReplacementPreservesOriginalProofAndDatabaseLink(): void
    {
        $this->submitExpense('/new');
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $expense = $this->em->getRepository(Depense::class)->findOneBy([]);
        self::assertNotNull($expense);
        $original = $this->savedProof($expense->getId());
        $originalHash = hash_file('sha256', $this->storage.'/'.$original);
        $this->failOnUpload = 2;

        $this->submitExpense('/'.$expense->getId().'/edit');
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame($original, $this->savedProof($expense->getId()));
        self::assertFileExists($this->storage.'/'.$original);
        self::assertSame($originalHash, hash_file('sha256', $this->storage.'/'.$original));
        self::assertCount(1, glob($this->storage.'/*'));
        self::assertStringContainsString('L’ancien justificatif a été conservé', $this->client->getResponse()->getContent());
    }

    public function testFailureOnSecondImportProofRollsBackExpensesAndRemovesFirstUpload(): void
    {
        $page = $this->client->request('GET', $this->base.'/import');
        $page = $this->client->request('POST', $this->base.'/import/preview', ['_token' => $page->filter('input[name="_token"]')->attr('value')], [
            'importFile' => $this->upload("Date;Libellé;Débit\n26/10/2026;Premier achat;12,00\n27/10/2026;Second achat;15,00\n", 'statement.csv'),
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $row = ['accept' => '1', 'date' => '2026-10-26', 'libelle' => 'Premier achat', 'devise' => 'EUR', 'tauxTva' => '0', 'tvaDeductible' => '0', 'tvaDeductiblePct' => '0', 'montantHtCents' => '1200', 'montantTvaCents' => '0', 'montantTtcCents' => '1200', 'payeurId' => (string) $this->user->getId(), 'categorieId' => '', 'fournisseurId' => '', 'allowDuplicate' => '0', 'hasProof' => '1'];
        $payload = [
            '_token' => $page->filter('input[name="_token"]')->first()->attr('value'),
            'importBatch' => $page->filter('input[name="importBatch"]')->attr('value'),
            'payloadComplete' => '1',
            'rowsJson' => json_encode([$row, array_replace($row, ['date' => '2026-10-27', 'libelle' => 'Second achat', 'montantHtCents' => '1500', 'montantTtcCents' => '1500'])], JSON_THROW_ON_ERROR),
        ];
        $this->failOnUpload = 2;
        $this->client->request('POST', $this->base.'/import/commit', $payload, ['proofs' => [$this->upload(), $this->upload()]]);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame($this->base.'/import', $this->client->getResponse()->headers->get('Location'));
        self::assertSame(2, $this->uploadCalls, 'The first proof must have been stored before the second fails.');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM depense'));
        self::assertSame([], glob($this->storage.'/*'));
        self::assertFalse($this->em->getConnection()->isTransactionActive());
    }
}
