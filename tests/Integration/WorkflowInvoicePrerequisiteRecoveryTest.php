<?php
declare(strict_types=1);
namespace App\Tests\Integration;

use App\Command\RunTrainingWorkflowsCommand;
use App\Entity\{ConventionContrat, Devis, Entite, EntitePreferences, Entreprise, LigneDevis, Session, SessionJour, Site, Utilisateur};
use App\Entity\Automation\{TrainingWorkflow, WorkflowTask};
use App\Exception\InvoicePrerequisiteUnavailable;
use App\Service\Automation\{WorkflowActionHandler, WorkflowBilling, WorkflowPlanner, WorkflowRunner};
use App\Service\Billing\InvoiceIssuer;
use App\Service\EInvoicing\EInvoiceReadiness;
use App\Service\Email\MailerManager;
use App\Service\Pdf\PdfManager;
use App\Service\Sequence\{FactureNumberGenerator, SequenceNumberManager};
use Doctrine\ORM\{EntityManager, EntityManagerInterface, Tools\SchemaTool};
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mailer\MailerInterface;
use Twig\Environment;

final class WorkflowInvoicePrerequisiteRecoveryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private EInvoiceReadiness $readiness;
    private WorkflowTask $task;
    private array $database;

    protected function setUp(): void
    {
        $this->database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->readiness = $this->createMock(EInvoiceReadiness::class);
        self::getContainer()->set(EInvoiceReadiness::class, $this->readiness);
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');
        self::getContainer()->set(MailerInterface::class, $mailer);
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $user = (new Utilisateur())->setEmail('invoice-recovery@example.test')->setPassword('unused')->setNom('Admin')->setPrenom('Test');
        $entity = (new Entite())->setNom('Organisme')->setCreateur($user)->setPublic(false)
            ->setSiret('12345678900012')->setNumeroTva('FR12345678901')->setAdresse('1 rue Test')->setCodePostal('75001')->setVille('Paris')->setPays('France');
        $customer = (new Entreprise())->setEntite($entity)->setCreateur($user)->setRaisonSociale('Client')
            ->setSiret('98765432100012')->setAdresse('2 rue Client')->setCodePostal('69001')->setVille('Lyon')->setPays('France');
        $preferences = (new EntitePreferences())->setEntite($entity)->setCreateur($user)->setInvoicePreferences([
            'confirmed' => true, 'dueDays' => 30, 'paymentMeans' => 'Virement', 'earlyPaymentDiscount' => 'Aucun',
            'latePaymentPenalty' => 'Taux contractuel vérifié', 'recoveryCompensationCents' => 4000,
            'legalIdentity' => 'Identité juridique test', 'vatCategory' => 'S', 'vatOnDebits' => false,
        ]);
        $entity->setPreferences($preferences);
        foreach ([$user, $entity, $customer, $preferences] as $row) $this->em->persist($row);
        $this->em->flush();
        $site = (new Site())->setEntite($entity)->setCreateur($user)->setNom('Site')->setSlug('recovery-site');
        $session = (new Session())->setEntite($entity)->setCreateur($user)->setSite($site)->setCode('RECOVERY-SESSION');
        $day = (new SessionJour())->setSession($session)->setEntite($entity)->setCreateur($user)
            ->setDateDebut(new \DateTimeImmutable('-3 days 09:00'))->setDateFin(new \DateTimeImmutable('-3 days 17:00'));
        $session->addJour($day);
        $quote = (new Devis())->setEntite($entity)->setCreateur($user)->setEntrepriseDestinataire($customer)
            ->setNumero('RECOVERY-QUOTE')->setMontantHtCents(10000)->setMontantTvaCents(2000)->setMontantTtcCents(12000)
            ->setMeta(['invoice_terms' => ['serviceDate' => '2026-10-07']]);
        $quote->addLigne((new LigneDevis())->setEntite($entity)->setCreateur($user)->setLabel('Formation')->setQte(1)->setPuHtCents(10000)->setTva(20));
        $convention = (new ConventionContrat())->setEntite($entity)->setCreateur($user)->setSession($session)->setNumero('RECOVERY-CONVENTION')
            ->setDevis($quote)->setDateSignatureEntreprise(new \DateTimeImmutable('-4 days'));
        $workflow = (new TrainingWorkflow())->setEntite($entity)->setCreateur($user)->setSession($session)->setConvention($convention)->setEnabled(true);
        $this->task = (new WorkflowTask())->setWorkflow($workflow)->setTaskKey('invoice:all')->setAction('invoice')->setDueAt(new \DateTimeImmutable('-1 day'));
        foreach ([$site, $session, $day, $quote, $convention, $workflow, $this->task] as $row) $this->em->persist($row);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $index => $key) {
            if ($this->database[$index] === null) unset($GLOBALS['_' . $key]['DATABASE_URL']);
            else $GLOBALS['_' . $key]['DATABASE_URL'] = $this->database[$index];
        }
    }

    public static function guardedBoundaries(): iterable
    {
        yield 'issuer inside outer transaction' => [2];
        yield 'Doctrine listener after number allocation' => [3];
    }

    #[DataProvider('guardedBoundaries')]
    public function testInnerRefusalRollsBackAndLeavesADurableTaskForAutomaticRetry(int $failAt): void
    {
        $checks = 0;
        $this->readiness->method('assertCanIssue')->willReturnCallback(function () use (&$checks, $failAt): void {
            if (++$checks === $failAt) throw new InvoicePrerequisiteUnavailable('Le paiement Stripe ne peut plus être vérifié.');
        });
        $now = new \DateTimeImmutable();
        self::assertSame('blocked', $this->runner($this->em)->run($this->task, $now));
        self::assertFalse($this->em->isOpen(), 'This exercises a real Doctrine rollback and closed manager.');
        $connection = $this->em->getConnection();
        self::assertFalse($connection->isTransactionActive());
        self::assertSame('blocked', $connection->fetchOne('SELECT status FROM workflow_task WHERE id = ?', [$this->task->getId()]));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM facture'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM facture_sequence'));
        self::assertNull($connection->fetchOne('SELECT facture_id FROM training_workflow WHERE id = ?', [$this->task->getWorkflow()->getId()]));

        // A subsequent worker uses a new manager, as the next cron invocation will.
        $fresh = new EntityManager($connection, $this->em->getConfiguration(), $this->em->getEventManager());
        $retry = $fresh->find(WorkflowTask::class, $this->task->getId());
        self::assertSame('done', $this->runner($fresh)->run($retry, $now->modify('+1 minute')));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM facture'));
        self::assertSame(1, (int) $connection->fetchOne('SELECT last FROM facture_sequence'));
        self::assertSame('done', $connection->fetchOne('SELECT status FROM workflow_task WHERE id = ?', [$retry->getId()]));
        self::assertSame($failAt + 3, $checks, 'All three issuance boundaries are checked again on the fresh attempt.');
        self::assertSame('unchanged', $this->runner($fresh)->run($retry, $now->modify('+2 minutes')));
    }

    public function testCommandStopsCleanlyAfterDurableRecoveryAndLeavesDeliveryPending(): void
    {
        $checks = 0;
        $this->readiness->method('assertCanIssue')->willReturnCallback(function () use (&$checks): void {
            if (++$checks === 3) throw new InvoicePrerequisiteUnavailable('Vérification Stripe momentanément indisponible.');
        });
        $planner = self::getContainer()->get(WorkflowPlanner::class);
        foreach ($planner->synchronize($this->task->getWorkflow()) as $task) {
            if (!in_array($task->getAction(), ['invoice', 'invoice_send'], true)) $task->setStatus('skipped');
        }
        $this->em->flush();
        $command = new CommandTester(new RunTrainingWorkflowsCommand($this->em, $planner, $this->runner($this->em)));
        self::assertSame(Command::FAILURE, $command->execute(['--execute' => true]));
        self::assertStringContainsString('prochaine exécution', $command->getDisplay());
        $connection = $this->em->getConnection();
        self::assertSame('blocked', $connection->fetchOne("SELECT status FROM workflow_task WHERE action = 'invoice'"));
        self::assertSame('pending', $connection->fetchOne("SELECT status FROM workflow_task WHERE action = 'invoice_send'"));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM facture'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM facture_sequence'));
    }

    private function runner(EntityManagerInterface $em): WorkflowRunner
    {
        $issuer = new InvoiceIssuer($em, new FactureNumberGenerator(new SequenceNumberManager($em->getConnection())), $this->readiness);
        $billing = new WorkflowBilling($em, $issuer, self::getContainer()->get(MailerManager::class), self::getContainer()->get(PdfManager::class), self::getContainer()->get(Environment::class));
        return new WorkflowRunner($em, $billing, self::getContainer()->get(WorkflowActionHandler::class), new NullLogger());
    }
}
