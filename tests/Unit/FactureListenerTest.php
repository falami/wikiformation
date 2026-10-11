<?php
declare(strict_types=1);
namespace App\Tests\Unit;

use App\Doctrine\FactureListener;
use App\Entity\{Entite, Facture};
use App\Service\Sequence\FactureNumberGenerator;
use PHPUnit\Framework\TestCase;

final class FactureListenerTest extends TestCase
{
    public function testGeneratedSequenceUsesEmissionYearAndPreservesExistingNumbers(): void
    {
        $entite = new Entite();
        (new \ReflectionProperty($entite, 'id'))->setValue($entite, 12);
        $generator = $this->createMock(FactureNumberGenerator::class);
        $generator->expects(self::once())->method('nextForEntite')->with(12, 2024)->willReturn('FAC-E12-2024-0001');
        $listener = new FactureListener($generator);
        $invoice = (new Facture())->setEntite($entite)->setDateEmission(new \DateTimeImmutable('2024-12-31'));
        $listener->prePersist($invoice);
        self::assertSame('FAC-E12-2024-0001', $invoice->getNumero());
        $listener->prePersist($invoice);
        self::assertSame('FAC-E12-2024-0001', $invoice->getNumero());
    }
}
