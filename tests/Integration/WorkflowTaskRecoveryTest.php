<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\{ConventionContrat, Entite, Facture, Session, Site, Utilisateur, UtilisateurEntite};
use App\Entity\Automation\{TrainingWorkflow, WorkflowTask};
use App\Entity\Billing\{EntiteSubscription, Plan};
use App\Service\Automation\WorkflowTaskRecovery;
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\{KernelBrowser, Test\KernelTestCase};
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class WorkflowTaskRecoveryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private WorkflowTaskRecovery $recovery;
    private Entite $entity;
    private Utilisateur $admin;
    private TrainingWorkflow $workflow;
    private WorkflowTask $task;
    private array $database;

    protected function setUp(): void
    {
        $this->database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $transport = $this->createMock(MailerInterface::class); $transport->expects(self::never())->method('send');
        self::getContainer()->set(MailerInterface::class, $transport);
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->admin = (new Utilisateur())->setEmail('recovery-admin@example.test')->setPassword('unused')->setNom('Test')->setPrenom('Admin');
        $this->entity = (new Entite())->setNom('Organisme test')->setEmail('of@example.test')->setCreateur($this->admin)->setPublic(false);
        foreach ([$this->admin, $this->entity] as $row) $this->em->persist($row);
        $this->em->flush(); $this->admin->setEntite($this->entity);
        $this->em->persist((new UtilisateurEntite())->setUtilisateur($this->admin)->setEntite($this->entity)->setCreateur($this->admin)->setRoles([UtilisateurEntite::TENANT_ADMIN]));
        $plan = (new Plan())->setCode('RECOVERY_TEST')->setName('Test'); $this->em->persist($plan);
        $this->em->persist((new EntiteSubscription())->setEntite($this->entity)->setPlan($plan)->setStatus('active'));
        $site = (new Site())->setEntite($this->entity)->setCreateur($this->admin)->setNom('Site test')->setSlug('site-recovery');
        $session = (new Session())->setEntite($this->entity)->setCreateur($this->admin)->setSite($site)->setCode('SES-RECOVERY');
        $convention = (new ConventionContrat())->setEntite($this->entity)->setSession($session)->setCreateur($this->admin)->setNumero('CONV-RECOVERY');
        $this->workflow = (new TrainingWorkflow())->setEntite($this->entity)->setSession($session)->setConvention($convention)->setCreateur($this->admin)->setContactEmail('client@example.test');
        $this->task = (new WorkflowTask())->setWorkflow($this->workflow)->setAction('participants')->setTaskKey('participants:all')->setStatus('unknown')->setDueAt(new \DateTimeImmutable('-1 day'))->setProcessedAt(new \DateTimeImmutable('-1 hour'));
        foreach ([$site, $session, $convention, $this->workflow, $this->task] as $row) $this->em->persist($row);
        $this->em->flush();
        $authorization = $this->createMock(AuthorizationCheckerInterface::class); $authorization->method('isGranted')->willReturn(true);
        $this->recovery = new WorkflowTaskRecovery($this->em, $authorization);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $n => $key) {
            if ($this->database[$n] === null) unset($GLOBALS['_' . $key]['DATABASE_URL']);
            else $GLOBALS['_' . $key]['DATABASE_URL'] = $this->database[$n];
        }
    }

    public function testUnknownCanBeReplannedWithoutMailAndKeepsAudit(): void
    {
        $revision = WorkflowTaskRecovery::revision($this->task);
        $this->recover('retry');
        self::assertSame('pending', $this->task->getStatus());
        self::assertNull($this->task->getProcessedAt());
        $history = $this->task->getSnapshot()['recoveryHistory'];
        self::assertCount(1, $history);
        self::assertSame($this->admin->getId(), $history[0]['actorId']);
        self::assertSame('unknown', $history[0]['previousStatus']);
        self::assertSame('Journal vérifié : aucun envoi.', $history[0]['reason']);
        $this->expectException(\DomainException::class);
        $this->recovery->recover($this->entity, $this->task, $this->admin, 'retry', 'Journal vérifié : aucun envoi.', $revision);
    }

    public function testRecentProcessingCannotBeRecoveredButOldProcessingCan(): void
    {
        $this->task->setStatus('processing')->setProcessedAt(new \DateTimeImmutable('-10 minutes')); $this->em->flush();
        try { $this->recover('retry'); self::fail('Active processing must be protected.'); }
        catch (\DomainException $e) { self::assertStringContainsString('30 minutes', $e->getMessage()); }
        self::assertTrue($this->em->isOpen()); self::assertSame('processing', $this->task->getStatus());
        $this->task->setProcessedAt(new \DateTimeImmutable('-31 minutes')); $this->em->flush();
        $this->recover('confirmed'); self::assertSame('sent', $this->task->getStatus());
    }

    public function testWrongTenantAndMissingAdminPermissionAreDeniedByService(): void
    {
        $other = (new Entite())->setNom('Other')->setCreateur($this->admin)->setPublic(false); $this->em->persist($other); $this->em->flush();
        try { $this->recovery->recover($other, $this->task, $this->admin, 'retry', 'Journal vérifié : aucun envoi.', WorkflowTaskRecovery::revision($this->task)); self::fail('Wrong tenant must be denied.'); }
        catch (AccessDeniedException) { self::assertSame('unknown', $this->task->getStatus()); }
        $authorization = $this->createMock(AuthorizationCheckerInterface::class); $authorization->method('isGranted')->willReturn(false);
        $service = new WorkflowTaskRecovery($this->em, $authorization);
        $this->expectException(AccessDeniedException::class);
        $service->recover($this->entity, $this->task, $this->admin, 'retry', 'Journal vérifié : aucun envoi.', WorkflowTaskRecovery::revision($this->task));
    }

    public function testInvoiceOwnerCanAuthorizeRetryAndAuditIsSavedOnInvoice(): void
    {
        $invoice = $this->invoiceReservation();
        $this->recover('retry'); $this->em->refresh($invoice);
        self::assertSame('retry_authorized', $invoice->getMeta()['workflow_deliveries']['invoice_send']['status']);
        self::assertSame($this->task->getId(), $invoice->getMeta()['workflow_deliveries']['invoice_send']['taskId']);
        self::assertSame($this->task->getId(), $invoice->getMeta()['workflow_delivery_recovery_history'][0]['taskId']);
        self::assertSame($invoice->getId(), $this->task->getSnapshot()['invoiceId']);
        self::assertSame('pending', $this->task->getStatus());
    }

    public function testInvoiceConfirmedDeliveryCannotBeReissuedAndOtherOwnerCannotBeBypassed(): void
    {
        $invoice = $this->invoiceReservation();
        $meta = $invoice->getMeta(); $meta['workflow_deliveries']['invoice_send']['taskId'] = $this->task->getId() + 100;
        $invoice->setMeta($meta); $this->em->flush();
        try { $this->recover('retry'); self::fail('Another owner must not be bypassed.'); }
        catch (\DomainException $e) { self::assertStringContainsString('autre étape', $e->getMessage()); }
        $meta['workflow_deliveries']['invoice_send']['taskId'] = $this->task->getId();
        $meta['workflow_deliveries']['invoice_send']['status'] = 'sent'; $invoice->setMeta($meta); $this->em->flush();
        try { $this->recover('retry'); self::fail('Confirmed delivery must not be resent.'); }
        catch (\DomainException $e) { self::assertStringContainsString('déjà un envoi confirmé', $e->getMessage()); }
        $this->recover('confirmed');
        self::assertSame('sent', $this->task->getStatus());
        self::assertSame($this->admin->getId(), $invoice->getMeta()['workflow_deliveries']['invoice_send']['confirmedBy']);
    }

    public function testPostRejectsInvalidCsrfWithoutChangingTask(): void
    {
        $client = new KernelBrowser(self::$kernel); $client->disableReboot(); $client->loginUser($this->admin);
        $client->request('POST', '/fr/administrateur/' . $this->entity->getId() . '/dossiers-automatises/etape/' . $this->task->getId() . '/rapprocher', [
            '_token' => 'invalid', 'decision' => 'retry', 'reason' => 'Journal vérifié : aucun envoi.', 'verified' => '1', 'revision' => WorkflowTaskRecovery::revision($this->task),
        ]);
        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertSame('unknown', $this->task->getStatus());
    }

    public function testAdminCanSubmitRecoveryFormAndAuditRemainsVisible(): void
    {
        $client = new KernelBrowser(self::$kernel); $client->disableReboot(); $client->catchExceptions(false); $client->loginUser($this->admin);
        $url = '/fr/administrateur/' . $this->entity->getId() . '/dossiers-automatises/' . $this->workflow->getId();
        $crawler = $client->request('GET', $url);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $form = $crawler->filter('form[action$="/rapprocher"]')->form();
        $data = $form->getValues();
        $data['decision'] = 'retry'; $data['reason'] = 'Journal vérifié : aucun envoi.'; $data['verified'] = '1';
        $client->request('POST', $form->getUri(), $data);
        self::assertSame(303, $client->getResponse()->getStatusCode());
        $this->task = $this->em->find(WorkflowTask::class, $this->task->getId());
        self::assertSame('pending', $this->task->getStatus());
        $client->request('GET', $url);
        self::assertStringContainsString('Historique des vérifications (1)', $client->getResponse()->getContent());
        self::assertStringContainsString('Journal vérifié : aucun envoi.', $client->getResponse()->getContent());
    }

    private function recover(string $decision): void
    {
        $this->recovery->recover($this->entity, $this->task, $this->admin, $decision, 'Journal vérifié : aucun envoi.', WorkflowTaskRecovery::revision($this->task));
    }

    private function invoiceReservation(): Facture
    {
        $invoice = (new Facture())->setEntite($this->entity)->setCreateur($this->admin)->setNumero('FAC-RECOVERY')->setMontantHtCents(10000)->setMontantTvaCents(2000)->setMontantTtcCents(12000);
        $invoice->setMeta(['workflow_deliveries' => ['invoice_send' => ['status' => 'processing', 'taskId' => $this->task->getId(), 'claimedAt' => (new \DateTimeImmutable('-1 hour'))->format(DATE_ATOM), 'snapshot' => ['to' => 'client@example.test']]]]);
        $this->em->persist($invoice); $this->workflow->setFacture($invoice); $this->task->setAction('invoice_send'); $this->em->flush();
        return $invoice;
    }
}
