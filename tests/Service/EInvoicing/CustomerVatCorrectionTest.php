<?php
declare(strict_types=1);
namespace App\Tests\Service\EInvoicing;
use App\Entity\{Entite, Entreprise, Facture, EInvoiceConnection, EInvoiceTransmission};
use App\Service\Billing\InvoiceSnapshot;
use App\Service\EInvoicing\CustomerVatCorrection;
use PHPUnit\Framework\TestCase;
final class CustomerVatCorrectionTest extends TestCase
{
    private function fixture(): array
    {
        $entity = new Entite(); (new \ReflectionProperty($entity, 'id'))->setValue($entity, 1);
        $company = (new Entreprise())->setEntite($entity)->setSiret('73282932000074')->setNumeroTVA('73282932000074');
        (new \ReflectionProperty($company, 'id'))->setValue($company, 2);
        $invoice = (new Facture())->setEntite($entity)->setEntrepriseDestinataire($company)->setNumero('TEST')->setDateEmission(new \DateTimeImmutable('2026-11-01'));
        $invoice->setMontantHtCents(10000)->setMontantTvaCents(2000)->setMontantTtcCents(12000); InvoiceSnapshot::capture($invoice);
        (new \ReflectionProperty($invoice, 'id'))->setValue($invoice, 3);
        $company->setNumeroTVA('FR44732829320');
        $connection = new EInvoiceConnection($entity, 'production');
        $transmission = new EInvoiceTransmission($connection, $invoice);
        $transmission->block(['old error']);
        return [$invoice, $transmission];
    }
    public function testCorrectionIsNarrowAuditedAndIdempotent(): void
    {
        [$invoice, $t] = $this->fixture(); $service = new CustomerVatCorrection();
        $before = InvoiceSnapshot::read($invoice);
        self::assertTrue($service->apply($invoice, $t, '73282932000074', 'FR44732829320'));
        $after = InvoiceSnapshot::read($invoice);
        self::assertNotNull($after);
        self::assertSame($before, $invoice->getMeta()['invoice_snapshot_corrections'][0]['originalSnapshot']);
        $after['customer']['numeroTva'] = $before['customer']['numeroTva'];
        $after['invoice']['entrepriseDestinataire']['numeroTva'] = $before['invoice']['entrepriseDestinataire']['numeroTva'];
        $after['sha256'] = $before['sha256'];
        self::assertSame($before, $after);
        self::assertSame('queued', $t->getStatus()); self::assertNull($t->getPayload());
        self::assertFalse($service->apply($invoice, $t, '73282932000074', 'FR44732829320'));
        self::assertCount(1, $invoice->getMeta()['invoice_snapshot_corrections']);
    }
    public function testAttemptedTransmissionCannotBeCorrected(): void
    {
        [$invoice, $t] = $this->fixture(); $t->prepared('<xml/>'); $t->begin();
        $this->expectException(\DomainException::class);
        (new CustomerVatCorrection())->preview($invoice, $t);
    }
    public function testChangedExpectedValueCannotBeApplied(): void
    {
        [$invoice, $t] = $this->fixture();
        $this->expectException(\DomainException::class);
        (new CustomerVatCorrection())->apply($invoice, $t, 'wrong', 'FR44732829320');
    }
}
