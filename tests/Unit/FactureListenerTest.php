<?php
declare(strict_types=1);
namespace App\Tests\Unit;

use App\Doctrine\FactureListener;
use App\Entity\{Entite, Facture};
use App\Service\Sequence\FactureNumberGenerator;
use App\Service\EInvoicing\EInvoiceReadiness;
use PHPUnit\Framework\TestCase;

final class FactureListenerTest extends TestCase
{
    public function testGeneratedSequenceUsesEmissionYearAndPreservesExistingNumbers(): void
    {
        $entite = new Entite();
        (new \ReflectionProperty($entite, 'id'))->setValue($entite, 12);
        $generator = $this->createMock(FactureNumberGenerator::class);
        $generator->expects(self::once())->method('nextForEntite')->with(12, 2024)->willReturn('FAC-E12-2024-0001');
        $readiness = $this->createMock(EInvoiceReadiness::class);
        $readiness->expects(self::exactly(2))->method('assertCanIssue')->with($entite);
        $listener = new FactureListener($generator, $readiness);
        $invoice = (new Facture())->setEntite($entite)->setDateEmission(new \DateTimeImmutable('2024-12-31'));
        $listener->prePersist($invoice);
        self::assertSame('FAC-E12-2024-0001', $invoice->getNumero());
        $listener->prePersist($invoice);
        self::assertSame('FAC-E12-2024-0001', $invoice->getNumero());
    }

    public function testPreassignedNumberCannotBypassBillingReadiness(): void
    {
        $entite = new Entite();
        (new \ReflectionProperty($entite, 'id'))->setValue($entite, 12);
        $invoice = (new Facture())->setEntite($entite)->setNumero('MANUAL-NUMBER');
        $generator = $this->createMock(FactureNumberGenerator::class);
        $generator->expects(self::never())->method('nextForEntite');
        $readiness = $this->createMock(EInvoiceReadiness::class);
        $readiness->expects(self::once())->method('assertCanIssue')->with($entite)
            ->willThrowException(new \DomainException('Configuration de facturation manquante.'));
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Configuration de facturation manquante.');
        (new FactureListener($generator, $readiness))->prePersist($invoice);
    }
}
