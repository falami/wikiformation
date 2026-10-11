<?php

declare(strict_types=1);

namespace App\Tests\Service\Document;

use App\Entity\{Attestation, Inscription, Session, SessionJour, Utilisateur};
use App\Enum\StatusInscription;
use App\Service\AssiduiteCalculator;
use App\Service\Document\RealisationCertificate;
use PHPUnit\Framework\TestCase;

final class RealisationCertificateTest extends TestCase
{
    private function service(): RealisationCertificate
    {
        $attendance = $this->createMock(AssiduiteCalculator::class);
        $attendance->method('online')->willReturn([]);
        return new RealisationCertificate($attendance);
    }
    private function inscription(string $start, string $end, ?int $pause = 0): Inscription
    {
        $session = (new Session())->addJour((new SessionJour())->setDateDebut(new \DateTimeImmutable($start))->setDateFin(new \DateTimeImmutable($end))->setPauseMinutes($pause));
        return (new Inscription())->setSession($session)->setStatus(StatusInscription::TERMINE);
    }
    public function testMissingEvidenceAndFutureTrainingNeverCertifyPlannedDuration(): void
    {
        $i = $this->inscription('2025-01-01 09:00', '2025-01-01 12:00');
        self::assertNull($this->service()->hours($i));
        $i->enregistrerPresenceManuelle('2025-01-01:AM', 'absent', '', new Utilisateur());
        self::assertSame(0.0, $this->service()->hours($i));
        self::assertFalse($this->service()->data($i)['ready']);
        $i = $this->inscription('2099-01-01 09:00', '2099-01-01 12:00');
        $i->enregistrerPresenceManuelle('2099-01-01:AM', 'paper', 'Feuille', new Utilisateur());
        self::assertNull($this->service()->hours($i));
    }
    public function testDifferentHalfDayDurationsAreNotMultipliedByAnUnweightedPercentage(): void
    {
        $i = $this->inscription('2025-01-01 09:00', '2025-01-01 12:00');
        $i->getSession()->addJour((new SessionJour())->setDateDebut(new \DateTimeImmutable('2025-01-01 14:00'))->setDateFin(new \DateTimeImmutable('2025-01-01 18:00'))->setPauseMinutes(0));
        $i->enregistrerPresenceManuelle('2025-01-01:AM', 'paper', 'Feuille', new Utilisateur());
        $i->enregistrerPresenceManuelle('2025-01-01:PM', 'absent', '', new Utilisateur());
        $i->setTauxAssiduite(50);
        self::assertSame(3.0, $this->service()->hours($i));
        $a = (new Attestation())->setDureeHeures(7)->setDateDelivrance(new \DateTimeImmutable('2025-01-01'));
        self::assertSame(3.0, $this->service()->data($i, $a)['hours']);
        self::assertTrue($this->service()->data($i, $a)['ready']);
        $a->setDateDelivrance(new \DateTimeImmutable('2024-12-31'));
        self::assertFalse($this->service()->data($i, $a)['ready']);
    }
    public function testAnUnallocatedBreakRequiresExactSlotsForPartialAttendance(): void
    {
        $i = $this->inscription('2025-01-01 08:30', '2025-01-01 17:00', 90);
        $i->enregistrerPresenceManuelle('2025-01-01:AM', 'paper', 'Feuille', new Utilisateur());
        $i->enregistrerPresenceManuelle('2025-01-01:PM', 'absent', '', new Utilisateur());
        self::assertNull($this->service()->hours($i));
        $i->enregistrerPresenceManuelle('2025-01-01:PM', 'paper', 'Feuille', new Utilisateur());
        self::assertSame(7.0, $this->service()->hours($i));
        $i->setStatus(StatusInscription::CONFIRME);
        self::assertFalse($this->service()->data($i)['ready']);
    }

    public function testOverlappingSlotsCannotDoubleCountTrainingHours(): void
    {
        $i = $this->inscription('2025-01-01 09:00', '2025-01-01 12:00');
        $i->enregistrerPresenceManuelle('2025-01-01:AM', 'paper', 'Feuille', new Utilisateur());
        $i->getSession()->addJour((new SessionJour())->setDateDebut(new \DateTimeImmutable('2025-01-01 11:00'))->setDateFin(new \DateTimeImmutable('2025-01-01 12:00'))->setPauseMinutes(0));
        self::assertNull($this->service()->hours($i));
        self::assertFalse($this->service()->data($i)['ready']);
    }
}
