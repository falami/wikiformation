<?php

declare(strict_types=1);
namespace App\Tests\Integration;

use App\Entity\{Depense, Entite, Utilisateur, UtilisateurEntite};
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ExpenseWorkflowsTest extends KernelTestCase
{
    private array $database;
    private $em;
    private $client;
    private Entite $entite;
    private Utilisateur $user;
    private string $base;
    private array $files = [];

    protected function setUp(): void
    {
        $this->database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->user = (new Utilisateur())->setEmail('expense@test.example')->setPassword('unused')->setPrenom('Test')->setNom('Expense');
        $this->entite = (new Entite())->setNom('Expense tenant')->setPublic(false)->setCreateur($this->user);
        $this->em->persist($this->user); $this->em->persist($this->entite); $this->em->flush();
        $this->user->setEntite($this->entite);
        $this->em->persist((new UtilisateurEntite())->setUtilisateur($this->user)->setEntite($this->entite)->setCreateur($this->user)->setRoles(['TENANT_ADMIN'])->setStatus('active'));
        $this->em->persist((new \App\Entity\Billing\EntiteSubscription())->setEntite($this->entite)->setStatus('active'));
        $this->em->flush();
        $this->client = self::getContainer()->get('test.client');
        $this->client->disableReboot(); $this->client->catchExceptions(false); $this->client->loginUser($this->user);
        $this->base = '/fr/administrateur/'.$this->entite->getId().'/depense';
    }
    protected function tearDown(): void
    {
        foreach ($this->files as $file) if (is_file($file)) unlink($file);
        parent::tearDown();
        foreach (['ENV','SERVER'] as $i=>$name) {
            if ($this->database[$i] === null) unset($GLOBALS['_'.$name]['DATABASE_URL']);
            else $GLOBALS['_'.$name]['DATABASE_URL'] = $this->database[$i];
        }
    }
    private function preview(string $csv = "Date;Libellé;Débit\n26/10/2026;Achat test;12,00\n"): array
    {
        $page = $this->client->request('GET', $this->base.'/import');
        $file = tempnam(sys_get_temp_dir(), 'expense-test-'); $this->files[] = $file; file_put_contents($file, $csv);
        $page = $this->client->request('POST', $this->base.'/import/preview', ['_token'=>$page->filter('input[name="_token"]')->attr('value')], ['importFile'=>new UploadedFile($file, 'statement.csv', 'text/csv', null, true)]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        return ['_token'=>$page->filter('input[name="_token"]')->first()->attr('value'), 'importBatch'=>$page->filter('input[name="importBatch"]')->attr('value'), 'payloadComplete'=>'1'];
    }
    private function row(array $changes = []): array
    {
        return array_replace(['accept'=>'1', 'date'=>'2026-10-26', 'libelle'=>'Achat test', 'devise'=>'EUR', 'tauxTva'=>'0', 'tvaDeductible'=>'0', 'tvaDeductiblePct'=>'0', 'montantHtCents'=>'1200', 'montantTvaCents'=>'0', 'montantTtcCents'=>'1200', 'payeurId'=>(string)$this->user->getId(), 'categorieId'=>'', 'fournisseurId'=>'', 'allowDuplicate'=>'0'], $changes);
    }
    public function testImportIsReplaySafeAndRequiresExplicitDuplicateConfirmation(): void
    {
        $payload = $this->preview();
        $payload['rowsJson'] = json_encode([$this->row()]);
        $this->client->request('POST', $this->base.'/import/commit', $payload);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, $this->em->getRepository(Depense::class)->count([]));
        $this->client->request('POST', $this->base.'/import/commit', $payload);
        self::assertSame(1, $this->em->getRepository(Depense::class)->count([]));
        $second = $this->preview(); $second['rowsJson'] = $payload['rowsJson'];
        $this->client->request('POST', $this->base.'/import/commit', $second);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, $this->em->getRepository(Depense::class)->count([]));
        $second['rowsJson'] = json_encode([$this->row(['allowDuplicate'=>'1'])]);
        $this->client->request('POST', $this->base.'/import/commit', $second);
        self::assertSame(2, $this->em->getRepository(Depense::class)->count([]));
    }
    public function testInvalidRowsAreAtomicAndCannotAssignForeignPayer(): void
    {
        $foreign = (new Utilisateur())->setEmail('foreign-expense@example.test')->setPassword('unused')->setNom('Foreign')->setPrenom('User');
        $this->em->persist($foreign); $this->em->flush();
        $payload = $this->preview("Date;Libellé;Débit\n26/10/2026;Achat test;12,00\n27/10/2026;Autre;15,00\n");
        $payload['rowsJson'] = json_encode([$this->row(), $this->row(['payeurId'=>(string)$foreign->getId(), 'date'=>'2026-10-27'])]);
        $this->client->request('POST', $this->base.'/import/commit', $payload);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->em->getRepository(Depense::class)->count([]));
        $payload['rowsJson'] = json_encode([$this->row()]); // PHP truncation or incomplete JSON submission
        $this->client->request('POST', $this->base.'/import/commit', $payload);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->em->getRepository(Depense::class)->count([]));
    }
    public function testInvalidDateAndProofAreRejectedWithoutSaving(): void
    {
        $payload = $this->preview();
        $payload['rowsJson'] = json_encode([$this->row(['date'=>'2026-02-31'])]);
        $this->client->request('POST', $this->base.'/import/commit', $payload);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $payload['rowsJson'] = json_encode([$this->row()]);
        $file = tempnam(sys_get_temp_dir(), 'unsafe-proof-'); $this->files[] = $file; file_put_contents($file, '<?php echo "not an image";');
        $this->client->request('POST', $this->base.'/import/commit', $payload, ['proofs'=>[new UploadedFile($file, 'proof.jpg', 'image/jpeg', null, true)]]);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->em->getRepository(Depense::class)->count([]));
    }
    public function testManualReceiptPreservesMixedVatAmountsAndZeroDeductiblePercentage(): void
    {
        $page = $this->client->request('GET', $this->base.'/new');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $form = $page->filter('form#depense')->form();
        $form['depense[libelle]'] = 'Ticket à plusieurs taux';
        $form['depense[dateDepense]'] = '26/10/2026';
        $form['depense[montantHtCents]'] = '100,00';
        $form['depense[montantTvaCents]'] = '12,50';
        $form['depense[montantTtcCents]'] = '112,50';
        $form['depense[tvaDeductible]']->tick();
        $form['depense[tvaDeductiblePct]'] = '0';
        $form['depense[amountsManual]']->tick();
        $result = $this->client->submit($form);
        self::assertSame(302, $this->client->getResponse()->getStatusCode(), $result->filter('form#depense')->text(''));
        $expense = $this->em->getRepository(Depense::class)->findOneBy([]);
        self::assertNotNull($expense);
        self::assertSame(1250, $expense->getMontantTvaCents());
        self::assertSame(11250, $expense->getMontantTtcCents());
        self::assertSame(0.0, $expense->getTvaDeductiblePct());
        $this->client->submit($form);
        self::assertSame(1, $this->em->getRepository(Depense::class)->count([]));
    }
    public function testManualInvalidFieldsNeverCrashOrSave(): void
    {
        foreach ([
            ['dateDepense'=>''], ['dateDepense'=>'31/02/2026'],
            ['montantTtcCents'=>'abc'], ['montantTvaCents'=>'-10'],
            ['montantHtCents'=>'20000000,00', 'montantTtcCents'=>'0'],
            ['montantHtCents'=>'', 'montantTvaCents'=>'', 'montantTtcCents'=>''],
        ] as $invalid) {
            $page = $this->client->request('GET', $this->base.'/new');
            $form = $page->filter('form#depense')->form();
            foreach (array_replace(['libelle'=>'Dépense invalide', 'dateDepense'=>'26/10/2026', 'montantHtCents'=>'10,00', 'montantTvaCents'=>'2,00', 'montantTtcCents'=>'12,00'], $invalid) as $field=>$value) $form['depense['.$field.']'] = $value;
            $this->client->submit($form);
            self::assertSame(422, $this->client->getResponse()->getStatusCode(), json_encode($invalid));
            self::assertSame(0, $this->em->getRepository(Depense::class)->count([]));
        }
    }
    public function testInvalidImportAmountRetainsPreviewAndInternalDuplicateCanBeConfirmed(): void
    {
        $payload = $this->preview("Date;Libellé;Débit\n26/10/2026;Achat test;12,00\n26/10/2026;Autre;12,00\n");
        $payload['rowsJson'] = json_encode([$this->row(), $this->row(['montantTtcCents'=>'abc'])]);
        $page = $this->client->request('POST', $this->base.'/import/commit', $payload);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Montant invalide', $page->text());
        $payload['rowsJson'] = json_encode([$this->row(), $this->row()]);
        $page = $this->client->request('POST', $this->base.'/import/commit', $payload);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertCount(2, $page->filter('.duplicate-confirm:not(.d-none)'));
        self::assertSame(0, $this->em->getRepository(Depense::class)->count([]));
        $payload['rowsJson'] = json_encode([$this->row(), $this->row(['allowDuplicate'=>'1'])]);
        $this->client->request('POST', $this->base.'/import/commit', $payload);
        self::assertSame(2, $this->em->getRepository(Depense::class)->count([]));
    }
    public function testMissingProofAndInvalidCsrfDoNotSaveImport(): void
    {
        $payload = $this->preview(); $payload['rowsJson'] = json_encode([$this->row(['hasProof'=>'1'])]);
        $this->client->request('POST', $this->base.'/import/commit', $payload);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->em->getRepository(Depense::class)->count([]));
        $payload['_token'] = 'invalid';
        $this->client->catchExceptions(true);
        $this->client->request('POST', $this->base.'/import/commit', $payload);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->em->getRepository(Depense::class)->count([]));
    }

    public function testImportPreservesEditedReceiptAmountsAndRejectsInconsistentTotals(): void
    {
        $payload = $this->preview();
        $payload['rowsJson'] = json_encode([$this->row(['montantHtCents'=>'1000', 'montantTvaCents'=>'125'])]);
        $this->client->request('POST', $this->base.'/import/commit', $payload);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->em->getRepository(Depense::class)->count([]));
        $payload['rowsJson'] = json_encode([$this->row(['montantHtCents'=>'1000', 'montantTvaCents'=>'125', 'montantTtcCents'=>'1125', 'tauxTva'=>'20'])]);
        $this->client->request('POST', $this->base.'/import/commit', $payload);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $expense = $this->em->getRepository(Depense::class)->findOneBy([]);
        self::assertSame(1000, $expense->getMontantHtCents());
        self::assertSame(125, $expense->getMontantTvaCents());
        self::assertSame(1125, $expense->getMontantTtcCents());
        self::assertTrue($expense->isAmountsManual());
    }

    public function testScanRejectsExpiredCsrfAndInvalidUploadWithoutLeakingConfiguration(): void
    {
        $this->client->request('POST', $this->base.'/scan-receipt', ['_token'=>'wrong']);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('credentials', $this->client->getResponse()->getContent());
    }
}
