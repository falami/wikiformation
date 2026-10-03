<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\{ConventionContrat, Entite, Facture, LigneFacture, Session, Site, Utilisateur};
use App\Entity\Automation\{TrainingWorkflow, WorkflowTask};
use App\Service\Automation\WorkflowBilling;
use App\Service\Email\MailerManager;
use App\Service\Pdf\PdfManager;
use App\Service\Sequence\FactureNumberGenerator;
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

final class WorkflowBillingDeliveryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private WorkflowBilling $billing;
    private MailerInterface $transport;
    private Facture $invoice;
    private array $workflows;
    private array $database;

    protected function setUp(): void
    {
        $this->database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $user = (new Utilisateur())->setEmail('admin-delivery@example.test')->setPassword('unused')->setNom('Test')->setPrenom('Admin');
        $entity = (new Entite())->setNom('Organisme test')->setEmail('of@example.test')->setCreateur($user)->setPublic(false);
        $site = (new Site())->setEntite($entity)->setCreateur($user)->setNom('Site test')->setSlug('site-delivery');
        $session = (new Session())->setEntite($entity)->setCreateur($user)->setSite($site)->setCode('SES-DELIVERY');
        $this->invoice = (new Facture())->setEntite($entity)->setCreateur($user)->setNumero('FAC-DELIVERY')->setDateEmission(new \DateTimeImmutable('-40 days'))
            ->setMontantHtCents(10000)->setMontantTvaCents(2000)->setMontantTtcCents(12000)
            ->addLigne((new LigneFacture())->setEntite($entity)->setCreateur($user)->setLabel('Formation')->setQte(1)->setPuHtCents(10000)->setTva(20));
        foreach ([$user, $entity, $site, $session, $this->invoice] as $row) $this->em->persist($row);
        $this->workflows = [];
        for ($i = 1; $i <= 2; ++$i) {
            $convention = (new ConventionContrat())->setEntite($entity)->setSession($session)->setCreateur($user)->setNumero('CONV-DELIVERY-' . $i);
            $workflow = (new TrainingWorkflow())->setEntite($entity)->setSession($session)->setConvention($convention)->setCreateur($user)->setContactEmail('client@example.test')->setFacture($this->invoice);
            $this->em->persist($convention); $this->em->persist($workflow); $this->workflows[] = $workflow;
        }
        $this->em->flush();
        $this->transport = $this->createMock(MailerInterface::class);
        $twig = $this->createMock(Environment::class); $twig->method('render')->willReturn('<p>Test</p>');
        $pdf = $this->createMock(PdfManager::class); $pdf->method('createPortraitBytes')->willReturn('%PDF-test');
        $this->billing = new WorkflowBilling($this->em, $this->createMock(FactureNumberGenerator::class),
            new MailerManager($this->transport, $twig, $this->createMock(UrlGeneratorInterface::class)), $pdf, $twig);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $n => $key) {
            if ($this->database[$n] === null) unset($GLOBALS['_' . $key]['DATABASE_URL']);
            else $GLOBALS['_' . $key]['DATABASE_URL'] = $this->database[$n];
        }
    }

    public function testDeliveryReservationIsCommittedBeforeMailAndSharedAcrossWorkflows(): void
    {
        $this->transport->expects(self::once())->method('send')->willReturnCallback(function () {
            self::assertFalse($this->em->getConnection()->isTransactionActive());
            $meta = $this->savedMeta();
            self::assertSame('processing', $meta['workflow_deliveries']['invoice_send']['status']);
            self::assertSame(12000, $meta['workflow_deliveries']['invoice_send']['snapshot']['totals']['ttcCents']);
        });
        self::assertSame('sent', $this->billing->handle($this->task(0, 'invoice_send'))['status']);
        self::assertSame('sent', $this->savedMeta()['workflow_deliveries']['invoice_send']['status']);
        self::assertSame('skipped', $this->billing->handle($this->task(1, 'invoice_send'))['status']);
    }

    public function testAmbiguousDeliveryPreventsOtherWorkflowFromSendingAgain(): void
    {
        $this->transport->expects(self::once())->method('send')->willThrowException(new \RuntimeException('Transport interrupted'));
        try {
            $this->billing->handle($this->task(0, 'invoice_send'));
            self::fail('The simulated transport must throw.');
        } catch (\RuntimeException $error) {
            self::assertSame('Transport interrupted', $error->getMessage());
        }
        self::assertSame('processing', $this->savedMeta()['workflow_deliveries']['invoice_send']['status']);
        self::assertSame('blocked', $this->billing->handle($this->task(1, 'invoice_send'))['status']);
    }

    public function testOneSharedReminderCanFollowInitialDeliveryFromAnotherWorkflow(): void
    {
        $this->transport->expects(self::exactly(2))->method('send');
        self::assertSame('sent', $this->billing->handle($this->task(0, 'invoice_send'))['status']);
        self::assertSame('sent', $this->billing->handle($this->task(1, 'reminder'))['status']);
        self::assertSame('skipped', $this->billing->handle($this->task(0, 'reminder'))['status']);
    }

    public function testHistoricalSentSnapshotPreventsDuplicateEvenWhenWorkflowLinkChanged(): void
    {
        $old = $this->task(0, 'invoice_send')->setStatus('sent')->setSnapshot(['invoiceId' => $this->invoice->getId()]);
        $old->getWorkflow()->setFacture(null); $this->em->flush();
        $this->transport->expects(self::never())->method('send');
        self::assertSame('skipped', $this->billing->handle($this->task(1, 'invoice_send'))['status']);
    }

    public function testHistoricalUnknownDeliveryBlocksOtherWorkflow(): void
    {
        $this->task(0, 'invoice_send')->setStatus('unknown'); $this->em->flush();
        $this->transport->expects(self::never())->method('send');
        self::assertSame('blocked', $this->billing->handle($this->task(1, 'invoice_send'))['status']);
    }

    public function testAuthorizedRetryCanOnlyBeClaimedByOriginalTask(): void
    {
        $owner = $this->task(0, 'invoice_send');
        $this->invoice->setMeta(['workflow_deliveries' => ['invoice_send' => ['status' => 'retry_authorized', 'taskId' => $owner->getId()]]]);
        $this->em->flush();
        $this->transport->expects(self::once())->method('send');
        self::assertSame('blocked', $this->billing->handle($this->task(1, 'invoice_send'))['status']);
        self::assertSame('sent', $this->billing->handle($owner)['status']);
        self::assertSame($owner->getId(), $this->savedMeta()['workflow_deliveries']['invoice_send']['taskId']);
    }

    private function task(int $workflow, string $action): WorkflowTask
    {
        $task = (new WorkflowTask())->setWorkflow($this->workflows[$workflow])->setAction($action)->setTaskKey($action . ':all')->setDueAt(new \DateTimeImmutable('-1 day'));
        $this->em->persist($task); $this->em->flush();
        return $task;
    }

    private function savedMeta(): array
    {
        return json_decode($this->em->getConnection()->fetchOne('SELECT meta FROM facture WHERE id = ?', [$this->invoice->getId()]), true, 512, JSON_THROW_ON_ERROR);
    }
}
