<?php

namespace App\Tests\Controller\Administrateur;

use App\Controller\Administrateur\DevisController;
use App\Entity\Devis;
use App\Entity\LigneDevis;
use PHPUnit\Framework\TestCase;

final class DevisControllerTest extends TestCase
{
    public function testTotalsAreRecalculatedFromLinesInsteadOfPostedAmounts(): void
    {
        $devis = (new Devis())
            ->setMontantHtCents(1)
            ->setMontantTvaCents(2)
            ->setMontantTtcCents(999999);
        $devis->addLigne((new LigneDevis())->setQte(2)->setPuHtCents(10000)->setTva(20));

        $this->recalculate($devis);

        self::assertSame(20000, $devis->getMontantHtCents());
        self::assertSame(4000, $devis->getMontantTvaCents());
        self::assertSame(24000, $devis->getMontantTtcCents());
    }

    public function testLineAndGlobalDiscountsApplyBeforeTaxAcrossRates(): void
    {
        $devis = (new Devis())->setRemiseGlobaleMontantCents(1800);
        $devis->addLigne((new LigneDevis())->setQte(1)->setPuHtCents(10000)->setRemisePourcent(10)->setTva(20));
        $devis->addLigne((new LigneDevis())->setQte(1)->setPuHtCents(9000)->setTva(10));

        $this->recalculate($devis);

        self::assertSame(16200, $devis->getMontantHtCents());
        self::assertSame(2430, $devis->getMontantTvaCents());
        self::assertSame(18630, $devis->getMontantTtcCents());
    }

    public function testPercentageDiscountAndRoundingPreserveCents(): void
    {
        $devis = (new Devis())->setRemiseGlobalePourcent(50);
        $devis->addLigne((new LigneDevis())->setQte(1)->setPuHtCents(101)->setTva(0));
        $devis->addLigne((new LigneDevis())->setQte(1)->setPuHtCents(102)->setTva(0));

        $this->recalculate($devis);

        self::assertSame(101, $devis->getMontantHtCents());
        self::assertSame(101, $devis->getMontantTtcCents());
    }

    public function testEmptyQuoteDoesNotKeepStaleAmounts(): void
    {
        $devis = (new Devis())->setMontantHtCents(1000)->setMontantTvaCents(200)->setMontantTtcCents(1200);

        $this->recalculate($devis);

        self::assertSame(0, $devis->getMontantHtCents());
        self::assertSame(0, $devis->getMontantTvaCents());
        self::assertSame(0, $devis->getMontantTtcCents());
    }

    private function recalculate(Devis $devis): void
    {
        $controller = (new \ReflectionClass(DevisController::class))->newInstanceWithoutConstructor();
        (new \ReflectionMethod(DevisController::class, 'recalcDevis'))->invoke($controller, $devis);
    }
}
