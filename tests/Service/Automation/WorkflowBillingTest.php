<?php

declare(strict_types=1);

namespace App\Tests\Service\Automation;

use App\Entity\{ConventionContrat, Devis, Entite, Facture, LigneDevis, Utilisateur};
use App\Entity\Automation\{TrainingWorkflow, WorkflowTask};
use App\Service\Automation\WorkflowBilling;
use App\Service\Billing\InvoiceTotals;
use App\Service\Email\MailerManager;
use App\Service\Pdf\PdfManager;
use App\Service\Sequence\FactureNumberGenerator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

final class WorkflowBillingTest extends TestCase
{
    private EntityManagerInterface $em;
    private WorkflowBilling $billing;
    private TrainingWorkflow $workflow;
    private Devis $quote;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->method('wrapInTransaction')->willReturnCallback(static fn(callable $f) => $f());
        $numbers = $this->createMock(FactureNumberGenerator::class);
        $numbers->method('nextForEntite')->willReturn('FAC-TEST-1');
        $transport = $this->createMock(MailerInterface::class);
        $transport->expects(self::never())->method('send');
        $twig = $this->createMock(Environment::class);
        $mailer = new MailerManager($transport, $twig, $this->createMock(UrlGeneratorInterface::class));
        $this->billing = new WorkflowBilling($this->em, $numbers, $mailer, $this->createMock(PdfManager::class), $twig);

        $entity = (new Entite())->setNom('Organisme test');
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, 1);
        $creator = (new Utilisateur())->setEmail('admin@example.test');
        $this->quote = (new Devis())->setEntite($entity)->setDevise('EUR')->setRemiseGlobalePourcent(10)
            ->setMontantHtCents(9000)->setMontantTvaCents(1800)->setMontantTtcCents(10800)
            ->addLigne((new LigneDevis())->setLabel('Formation')->setQte(1)->setPuHtCents(10000)->setTva(20));
        $convention = (new ConventionContrat())->setEntite($entity)->setDevis($this->quote)->setDateSignatureEntreprise(new \DateTimeImmutable());
        $this->workflow = (new TrainingWorkflow())->setEntite($entity)->setCreateur($creator)->setConvention($convention);
    }

    public function testInvoicePersistsExactTotalsAndAuditableSnapshotWithoutSending(): void
    {
        $this->em->expects(self::once())->method('persist')->with(self::isInstanceOf(Facture::class));
        $result = $this->billing->handle($this->task('invoice'));
        self::assertSame('done', $result['status']);
        $invoice = $this->workflow->getFacture();
        self::assertSame(9000, $invoice->getMontantHtCents());
        self::assertSame(1800, $invoice->getMontantTvaCents());
        self::assertSame(10800, $invoice->getMontantTtcCents());
        self::assertSame('EUR', $invoice->getMeta()['workflow_billing']['currency']);
        self::assertSame(InvoiceTotals::calculate($invoice), $invoice->getMeta()['workflow_billing']['createdTotals']);
        self::assertSame($invoice, $this->quote->getFactureCreee());
    }

    public function testInconsistentQuoteIsBlockedBeforeAnyInvoiceIsCreated(): void
    {
        $this->quote->setMontantTtcCents(11000);
        $this->em->expects(self::never())->method('persist');
        self::assertSame('blocked', $this->billing->handle($this->task('invoice'))['status']);
        self::assertNull($this->workflow->getFacture());
    }

    public function testNonEuroQuoteCannotBeIssuedAsAnEuroInvoice(): void
    {
        $this->quote->setDevise('USD');
        $this->em->expects(self::never())->method('persist');
        self::assertSame('blocked', $this->billing->handle($this->task('invoice'))['status']);
    }

    public function testNonEuroInvoiceIsNotSentWithAnEuroPdf(): void
    {
        $this->workflow->setFacture((new Facture())->setEntite($this->workflow->getEntite())->setDevise('USD'));
        self::assertSame('blocked', $this->billing->handle($this->task('invoice_send'))['status']);
    }

    private function task(string $action): WorkflowTask
    {
        return (new WorkflowTask())->setWorkflow($this->workflow)->setAction($action);
    }
}
