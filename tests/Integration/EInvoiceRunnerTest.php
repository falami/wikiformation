<?php
declare(strict_types=1);
namespace App\Tests\Integration;

use App\Entity\{EInvoiceConnection, EInvoiceReceived, EInvoiceTransmission, EInvoiceUsage, Entite, EntitePreferences, Entreprise, Facture, LigneFacture, Utilisateur};
use App\Entity\Billing\EntiteSubscription;
use App\Service\Billing\{InvoiceIssuer, InvoiceSnapshot};
use App\Service\EInvoicing\{ConnectionLease, ConnectionManager, CredentialVault, EInvoiceConfiguration, EInvoiceRunner, EInvoiceUsageBilling, InvoicePayload, SuperPdpClient, UsageInvoiceGateway};
use App\Service\Sequence\{FactureNumberGenerator, SequenceNumberManager};
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Console\Tester\CommandTester;
use App\Command\SyncEInvoicesCommand;

final class EInvoiceRunnerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private array $database;
    private Entite $entity;
    private Entreprise $customer;
    private Utilisateur $user;
    private EInvoiceConnection $connection;
    private CredentialVault $vault;
    private EInvoiceConfiguration $configuration;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->configuration = new EInvoiceConfiguration('client', 'secret', 'https://example.test/callback', base64_encode(str_repeat('K', 32)));
        $this->vault = new CredentialVault($this->configuration);
        $this->user = (new Utilisateur())->setEmail('runner@example.test')->setPassword('unused')->setNom('Admin')->setPrenom('Test');
        $this->entity = (new Entite())->setNom('Organisme test')->setCreateur($this->user)->setPublic(false)
            ->setAdresse('10 rue de la Formation')->setCodePostal('75001')->setVille('Paris')->setPays('France')->setSiret('12345678900012')->setNumeroTva('FR12345678901');
        $this->customer = (new Entreprise())->setEntite($this->entity)->setCreateur($this->user)->setRaisonSociale('Client test')
            ->setAdresse('20 rue du Client')->setCodePostal('69001')->setVille('Lyon')->setPays('France')->setSiret('98765432100012')->setNumeroTVA('FR98765432109');
        $prefs = (new EntitePreferences())->setEntite($this->entity)->setCreateur($this->user)->setInvoicePreferences([
            'confirmed' => true, 'dueDays' => 30, 'paymentMeans' => 'Virement', 'earlyPaymentDiscount' => 'Pas d’escompte',
            'latePaymentPenalty' => 'Taux contractuel vérifié', 'recoveryCompensationCents' => 4000, 'legalIdentity' => 'Identité juridique test', 'vatCategory' => 'S', 'vatOnDebits' => false]);
        $this->entity->setPreferences($prefs);
        foreach ([$this->user, $this->entity, $this->customer, $prefs] as $row) $this->em->persist($row);
        $this->em->flush();
        $sub = (new EntiteSubscription())->setEntite($this->entity)->setStatus('active')->setStripeCustomerId('cus_test')->setStripeSubscriptionId('sub_test');
        $this->connection = new EInvoiceConnection($this->entity, 'production');
        $this->connection->connected($this->company(), $this->tokens());
        $this->connection->consentToBilling($this->user->getId()); $this->connection->setAutomatic(true);
        (new \ReflectionProperty(EInvoiceConnection::class, 'enabledAt'))->setValue($this->connection, new \DateTimeImmutable('-1 day'));
        $this->em->persist($sub); $this->em->persist($this->connection); $this->em->flush();
    }
    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $index => $key) {
            if ($this->database[$index] === null) unset($GLOBALS['_'.$key]['DATABASE_URL']);
            else $GLOBALS['_'.$key]['DATABASE_URL'] = $this->database[$index];
        }
    }
    private function company(): array { return ['id' => 77, 'env' => 'production', 'number_scheme' => 'fr_siren', 'number' => '123456789', 'formal_name' => 'Organisme test']; }
    private function tokens(int $expires = 3600): string { return $this->vault->encrypt(['access_token' => 'access', 'refresh_token' => 'refresh', 'expires_at' => time() + $expires]); }
    private function runner(callable $callback): EInvoiceRunner
    {
        $api = new SuperPdpClient(new MockHttpClient($callback));
        $lease = new ConnectionLease($this->em->getConnection());
        $stripe = $this->createMock(UsageInvoiceGateway::class);
        $stripe->expects(self::never())->method('create'); $stripe->expects(self::never())->method('validate'); $stripe->expects(self::never())->method('find');
        $usage = new EInvoiceUsageBilling($this->em, $this->em->getRepository(EntiteSubscription::class), $lease, $stripe);
        return new EInvoiceRunner($this->em, $api, new ConnectionManager($this->configuration, $this->vault, $api, $this->em), $lease, new InvoicePayload(), $usage);
    }
    private function invoice(): Facture
    {
        $invoice = (new Facture())->setEntite($this->entity)->setCreateur($this->user)->setEntrepriseDestinataire($this->customer)
            ->setMeta(['invoice_terms' => ['serviceDate' => (new \DateTimeImmutable())->format('Y-m-d')]])
            ->addLigne((new LigneFacture())->setEntite($this->entity)->setCreateur($this->user)->setLabel('Formation test')->setQte(1)->setPuHtCents(10000)->setTva(20));
        InvoiceIssuer::persist($this->em, new FactureNumberGenerator(new SequenceNumberManager($this->em->getConnection())), $invoice);
        return $invoice;
    }
    private function json(array $data): MockResponse { return new MockResponse(json_encode($data, JSON_THROW_ON_ERROR), ['response_headers' => ['content-type: application/json']]); }
    private function emptyPage(): array { return ['data' => [], 'has_after' => false]; }
    private function usual(string $method, string $url, array $options): MockResponse
    {
        $path = parse_url($url, PHP_URL_PATH);
        return match ($path) {
            '/v1.beta/oauth2_sessions/me' => $this->json(['company_verification_status' => 'verified']),
            '/v1.beta/companies/me' => $this->json($this->company()),
            '/v1.beta/invoices', '/v1.beta/invoice_events' => $this->json($this->emptyPage()),
            '/v1.beta/french_directory/entries' => $this->json(['data' => [['company' => ['number' => '987654321'], 'is_active' => true, 'identifier' => '0225:987654321']]]),
            '/v1.beta/invoices/convert' => new MockResponse('<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"/>'),
            '/v1.beta/validation_reports' => $this->json(['data' => [['is_valid' => true]]]),
            default => throw new \LogicException('Unexpected test request: '.$path),
        };
    }
    public function testDefaultDryRunMakesNoNetworkAndNoMutationAndExcludesHistoricalInvoices(): void
    {
        $fresh = $this->invoice(); $old = $this->invoice()->setDateCreation(new \DateTimeImmutable('-2 days'));
        $this->em->flush();
        $runner = $this->runner(function () { self::fail('No network in dry run.'); });
        $before = $this->connection->getCredentials();
        $result = $runner->run($this->connection);
        self::assertSame('simulation', $result['mode']); self::assertSame(1, $result['discovered']); self::assertSame(0, $result['blocked']);
        self::assertSame(0, $this->em->getRepository(EInvoiceTransmission::class)->count([])); self::assertNull($this->connection->getDiscoveryCursor());
        self::assertSame($before, $this->connection->getCredentials());
    }
    public function testSuccessfulSendIsDurableBeforePostAndRecordsExactlyOneUsageAndNeverResends(): void
    {
        $invoice = $this->invoice(); $posts = 0;
        $runner = $this->runner(function ($method, $url, $options) use (&$posts) {
            if ($method === 'POST' && parse_url($url, PHP_URL_PATH) === '/v1.beta/invoices') {
                ++$posts;
                $row = $this->em->getConnection()->fetchAssociative('SELECT status, attempted_at, payload, sha256, usage_terms FROM einvoice_transmission');
                self::assertSame('submitting', $row['status']); self::assertNotNull($row['attempted_at']);
                self::assertSame(hash('sha256', $row['payload']), $row['sha256']); self::assertStringContainsString('sub_test', $row['usage_terms']);
                return $this->json(['id' => 100, 'company_id' => 77, 'direction' => 'out']);
            }
            return $this->usual($method, $url, $options);
        });
        $result = $runner->run($this->connection, true);
        self::assertSame([], $result['errors']); self::assertSame(1, $result['submitted']);
        self::assertSame(1, $this->em->getRepository(EInvoiceUsage::class)->count([]));
        $result = $runner->run($this->connection, true);
        self::assertSame([], $result['errors']); self::assertSame(0, $result['submitted']); self::assertSame(1, $posts);
        self::assertSame(1, $this->em->getRepository(EInvoiceUsage::class)->count([]));
    }
    public function testUncertainPostReconcilesByExternalIdWithoutSecondPostOrPrematureBilling(): void
    {
        $this->invoice(); $posts = 0; $reconcile = false;
        $runner = $this->runner(function ($method, $url, $options) use (&$posts, &$reconcile) {
            $path = parse_url($url, PHP_URL_PATH); parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
            if ($path === '/v1.beta/invoices' && $method === 'POST') { ++$posts; return new MockResponse('secret upstream error', ['http_code' => 503]); }
            if ($path === '/v1.beta/invoices' && $method === 'GET' && ($query['direction'] ?? null) === 'out' && $reconcile && empty($query['starting_after_id'])) {
                $t = $this->em->getRepository(EInvoiceTransmission::class)->findOneBy(['connection' => $this->connection]);
                return $this->json(['data' => [['id' => 101, 'company_id' => 77, 'direction' => 'out', 'external_id' => $t->getExternalId()]], 'has_after' => false]);
            }
            return $this->usual($method, $url, $options);
        });
        $first = $runner->run($this->connection, true); self::assertSame(1, $first['uncertain']); self::assertSame(0, $this->em->getRepository(EInvoiceUsage::class)->count([]));
        $reconcile = true; $second = $runner->run($this->connection, true);
        self::assertSame([], $second['errors']); self::assertSame(1, $second['reconciled']); self::assertSame(1, $posts);
        self::assertSame(1, $this->em->getRepository(EInvoiceUsage::class)->count([])); self::assertSame('101', $this->connection->getOutgoingCursor());
    }
    public function testValidationFailureBlocksBeforeProviderPostAndBeforeBilling(): void
    {
        $this->invoice();
        $runner = $this->runner(function ($method, $url, $options) {
            if (str_contains($url, '/validation_reports')) return $this->json(['data' => [['is_valid' => false]]]);
            if (parse_url($url, PHP_URL_PATH) === '/v1.beta/invoices' && $method === 'POST') self::fail('Invalid XML must not be sent.');
            return $this->usual($method, $url, $options);
        });
        $result = $runner->run($this->connection, true); self::assertSame([], $result['errors']); self::assertSame(1, $result['blocked']);
        $t = $this->em->getRepository(EInvoiceTransmission::class)->findOneBy(['connection' => $this->connection]);
        self::assertNull($t->getAttemptedAt()); self::assertSame('blocked', $t->getStatus()); self::assertSame(0, $this->em->getRepository(EInvoiceUsage::class)->count([]));
    }
    public function testSandboxAndBusyConnectionMakeNoNetworkCalls(): void
    {
        $sandbox = new EInvoiceConnection($this->entity, 'sandbox');
        $runner = $this->runner(function () { self::fail('No network in sandbox or busy lease.'); });
        self::assertNotNull($runner->run($sandbox, true)['skipped']);
        $lease = new ConnectionLease($this->em->getConnection()); $token = $lease->acquire($this->connection->getId());
        self::assertNotNull($runner->run($this->connection, true)['skipped']); $lease->release($this->connection->getId(), $token);
    }
    public function testIncomingInvoicesRemainScopedAndPaymentEventDoesNotMarkInvoicePaid(): void
    {
        $invoice = $this->invoice(); $t = new EInvoiceTransmission($this->connection, $invoice); $t->prepared('<Invoice/>'); $t->begin(); $t->accepted('100');
        $this->em->persist($t); $this->connection->setAutomatic(false); $this->em->flush();
        $runner = $this->runner(function ($method, $url, $options) {
            $path = parse_url($url, PHP_URL_PATH); parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
            if ($path === '/v1.beta/invoices' && ($query['direction'] ?? '') === 'in') return $this->json(['data' => [['id' => 400, 'company_id' => 77, 'direction' => 'in', 'created_at' => '2026-10-08T10:00:00Z']], 'has_after' => false]);
            if ($path === '/v1.beta/invoice_events') return $this->json(['data' => [['id' => 500, 'invoice_id' => 100, 'status_code' => 'fr:212', 'created_at' => '2026-10-08T10:00:00Z']], 'has_after' => false]);
            return $this->usual($method, $url, $options);
        });
        $before = $invoice->getStatus(); $result = $runner->run($this->connection, true);
        self::assertSame([], $result['errors']); self::assertSame(1, $result['received']); self::assertSame(1, $result['events']);
        self::assertSame($before, $invoice->getStatus()); self::assertSame('fr:212', $t->getEvents()[0]['status_code']); self::assertSame('400', $this->connection->getIncomingCursor());
        self::assertSame(0, $this->em->getRepository(EInvoiceUsage::class)->count([]));
    }
    public function testWrongRemoteCompanyStopsBeforeOutboxOrDocumentsAreRead(): void
    {
        $this->invoice(); $requests = 0;
        $runner = $this->runner(function ($method, $url, $options) use (&$requests) {
            ++$requests;
            if (str_contains($url, '/companies/me')) return $this->json(array_replace($this->company(), ['number' => '999999999']));
            return $this->usual($method, $url, $options);
        });
        $result = $runner->run($this->connection, true);
        self::assertNotEmpty($result['errors']); self::assertSame(2, $requests); self::assertSame(0, $this->em->getRepository(EInvoiceTransmission::class)->count([]));
    }
    public function testRefreshIsPersistedBeforeNextAuthenticatedRequest(): void
    {
        $this->connection->setCredentials($this->tokens(-1)); $this->connection->setAutomatic(false); $this->em->flush(); $refreshed = false;
        $runner = $this->runner(function ($method, $url, $options) use (&$refreshed) {
            if (str_contains($url, '/oauth2/token')) { $refreshed = true; return $this->json(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 3600, 'token_type' => 'Bearer']); }
            self::assertTrue($refreshed);
            $saved = $this->em->getConnection()->fetchOne('SELECT credentials FROM einvoice_connection');
            self::assertSame('new-refresh', $this->vault->decrypt($saved)['refresh_token']);
            return $this->usual($method, $url, $options);
        });
        self::assertSame([], $runner->run($this->connection, true)['errors']);
    }
    public function testRecipientRootWinsButAmbiguityAndInactiveEntriesAreRejected(): void
    {
        $row = fn($suffix, $active = true) => ['company' => ['number' => '987654321'], 'identifier' => '0225:987654321'.$suffix, 'is_active' => $active];
        self::assertSame('0225:987654321', EInvoiceRunner::recipientAddress('987654321', [$row('_A'), $row('')]));
        self::assertSame('0225:987654321_A', EInvoiceRunner::recipientAddress('987654321', [$row('_A'), $row('_B', false)]));
        $this->expectException(\DomainException::class);
        EInvoiceRunner::recipientAddress('987654321', [$row('_A'), $row('_B')]);
    }

    public function testRejectedPostAndStaleSubmittingStateAreNeverSentAgain(): void
    {
        $this->invoice(); $posts = 0;
        $runner = $this->runner(function ($method, $url, $options) use (&$posts) {
            if ($method === 'POST' && parse_url($url, PHP_URL_PATH) === '/v1.beta/invoices') { ++$posts; return new MockResponse('private invalid response', ['http_code' => 400]); }
            return $this->usual($method, $url, $options);
        });
        $result = $runner->run($this->connection, true); self::assertSame(1, $result['rejected']);
        self::assertSame(0, $runner->run($this->connection, true)['submitted']); self::assertSame(1, $posts);
        $stale = new EInvoiceTransmission($this->connection, $this->invoice()); $stale->prepared('<Invoice/>'); $stale->begin();
        $this->em->persist($stale); $this->em->flush();
        $result = $runner->run($this->connection, true); self::assertSame([], $result['errors']); self::assertSame(1, $result['uncertain']);
        self::assertSame('uncertain', $stale->getStatus()); self::assertSame(1, $posts); self::assertSame(0, $this->em->getRepository(EInvoiceUsage::class)->count([]));
    }

    public function testReceptionPaginationIsBoundedAndResumesAtPersistedCursor(): void
    {
        $this->connection->setAutomatic(false); $this->em->flush(); $pages = 0;
        $runner = $this->runner(function ($method, $url, $options) use (&$pages) {
            parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
            if (parse_url($url, PHP_URL_PATH) === '/v1.beta/invoices' && ($query['direction'] ?? '') === 'in') {
                ++$pages; $id = (int) ($query['starting_after_id'] ?? 0) + 1;
                return $this->json(['data' => [['id' => $id, 'company_id' => 77, 'direction' => 'in']], 'has_after' => true]);
            }
            return $this->usual($method, $url, $options);
        });
        $result = $runner->run($this->connection, true, 1);
        self::assertSame([], $result['errors']); self::assertSame(3, $pages); self::assertSame(3, $result['received']); self::assertSame('3', $this->connection->getIncomingCursor());
        $result = $runner->run($this->connection, true, 1);
        self::assertSame(6, $pages); self::assertSame(3, $result['received']); self::assertSame('6', $this->connection->getIncomingCursor());
        self::assertSame(6, $this->em->getRepository(EInvoiceReceived::class)->count([]));
    }

    public function testForeignIncomingInvoiceDoesNotPersistOrAdvanceCursor(): void
    {
        $this->connection->setAutomatic(false); $this->em->flush();
        $runner = $this->runner(function ($method, $url, $options) {
            parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
            if (parse_url($url, PHP_URL_PATH) === '/v1.beta/invoices' && ($query['direction'] ?? '') === 'in') {
                return $this->json(['data' => [['id' => 10, 'company_id' => 999, 'direction' => 'in']], 'has_after' => false]);
            }
            return $this->usual($method, $url, $options);
        });
        self::assertNotEmpty($runner->run($this->connection, true)['errors']);
        self::assertNull($this->connection->getIncomingCursor()); self::assertSame(0, $this->em->getRepository(EInvoiceReceived::class)->count([]));
    }

    public function testConsoleDefaultsToLocalSimulationAndValidatesBatchOptions(): void
    {
        $this->invoice();
        $command = new CommandTester(new SyncEInvoicesCommand($this->em, $this->runner(function () { self::fail('The default command must not connect.'); })));
        self::assertSame(2, $command->execute(['--limit' => '101']));
        self::assertSame(0, $command->execute([]));
        self::assertStringContainsString('aucun appel', $command->getDisplay());
        self::assertStringContainsString('1 nouvelle(s)', $command->getDisplay());
        self::assertSame(0, $this->em->getRepository(EInvoiceTransmission::class)->count([]));
    }

    public function testAnAmbientTransactionCannotHideTheDurableAttemptFromAnotherWorker(): void
    {
        $this->em->getConnection()->beginTransaction();
        try {
            $runner = $this->runner(function () { self::fail('No network inside an ambient transaction.'); });
            $result = $runner->run($this->connection, true);
            self::assertNotEmpty($result['errors']); self::assertStringContainsString('hors transaction', $result['errors'][0]);
        } finally { $this->em->getConnection()->rollBack(); }
    }
}
