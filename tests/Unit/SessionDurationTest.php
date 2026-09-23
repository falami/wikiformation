<?php

namespace App\Tests\Unit;

use App\Entity\{Formateur, Session, SessionJour};
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class SessionDurationTest extends TestCase
{
    public function testThreeFullDaysCountTwentyOneTeachingHours(): void
    {
        $trainer = new Formateur();
        $session = (new Session())->setFormateur($trainer);
        foreach (['2026-10-07', '2026-10-08', '2026-10-09'] as $date) {
            $day = $this->slot($date . ' 08:30', $date . ' 17:00');
            $session->addJour($day);
            self::assertSame(90, $day->getPauseEffectiveMinutes());
            self::assertSame(420, $day->getDureeFormationMinutes());
        }
        self::assertSame(21.0, $session->getDureeFormationHeures());
        self::assertSame(21.0, $session->getNombreHeuresPourFormateur($trainer));
        self::assertSame(3.0, $session->getNombreJoursPourFormateur($trainer));
    }

    public function testSeparateHalfDaysDoNotDeductLunchTwice(): void
    {
        $first = new Formateur();
        $second = new Formateur();
        $session = (new Session())->setFormateur($first);
        $morning = $this->slot('2026-10-07 08:30', '2026-10-07 12:00');
        $afternoon = $this->slot('2026-10-07 13:30', '2026-10-07 17:00')->setFormateur($second);
        $session->addJour($morning)->addJour($afternoon);
        self::assertSame(0, $morning->getPauseEffectiveMinutes());
        self::assertSame(0, $afternoon->getPauseEffectiveMinutes());
        self::assertSame(7.0, $session->getDureeFormationHeures());
        self::assertSame(3.5, $session->getNombreHeuresPourFormateur($first));
        self::assertSame(3.5, $session->getNombreHeuresPourFormateur($second));
        self::assertSame(0.5, $session->getNombreJoursPourFormateur($first));
    }

    public function testAutomaticLunchIsAlwaysNinetyMinutesAndNeverCapsTeachingHours(): void
    {
        foreach ([['08:30', '17:00'], ['08:00', '16:30'], ['09:00', '17:30']] as [$start, $end]) {
            $day = $this->slot('2026-10-07 ' . $start, '2026-10-07 ' . $end);
            self::assertSame(90, $day->getPauseEffectiveMinutes());
            self::assertSame(7.0, $day->getDureeFormationHeures());
        }
        $longDay = $this->slot('2026-10-07 08:00', '2026-10-07 17:30');
        self::assertSame(90, $longDay->getPauseEffectiveMinutes());
        self::assertSame(8.0, $longDay->getDureeFormationHeures());
        self::assertSame(90, $this->slot('2026-10-07 09:00', '2026-10-07 17:00')->getPauseEffectiveMinutes());
        self::assertSame(4.5, $this->slot('2026-10-07 08:00', '2026-10-07 12:30')->getDureeFormationHeures());
        self::assertSame(4.5, $this->slot('2026-10-07 09:00', '2026-10-07 15:00')->getDureeFormationHeures());
    }

    public function testShortOrEveningSlotsAndNightShiftsDoNotSubtractLunch(): void
    {
        foreach ([['11:00', '14:30'], ['13:30', '21:30'], ['06:00', '12:00']] as [$start, $end]) {
            $day = $this->slot('2026-10-07 ' . $start, '2026-10-07 ' . $end);
            self::assertSame(0, $day->getPauseEffectiveMinutes());
            self::assertSame($day->getDureeBruteMinutes(), $day->getDureeFormationMinutes());
        }
        $night = $this->slot('2026-10-07 22:00', '2026-10-08 06:00');
        self::assertSame(['2026-10-07' => 120, '2026-10-08' => 360], $night->getDureeFormationMinutesParDate());
        self::assertSame(0, $night->getPauseEffectiveMinutes());
    }

    public function testExplicitPauseAndZeroOverrideAutomaticCalculation(): void
    {
        $day = $this->slot('2026-10-07 08:30', '2026-10-07 17:00');
        self::assertSame(7.5, $day->setPauseMinutes(60)->getDureeFormationHeures());
        self::assertSame(8.5, $day->setPauseMinutes(0)->getDureeFormationHeures());
        self::assertSame(7.0, $day->setPauseMinutes(null)->getDureeFormationHeures());
        self::assertSame(3.25, $this->slot('2026-10-07 09:00', '2026-10-07 12:30')->setPauseMinutes(15)->getDureeFormationHeures());
    }

    public function testHistoricalMultiDateSlotUsesEachDateAndPreservesExplicitTotal(): void
    {
        $day = $this->slot('2026-10-07 08:30', '2026-10-09 17:00');
        self::assertSame(['2026-10-07' => 420, '2026-10-08' => 420, '2026-10-09' => 420], $day->getDureeFormationMinutesParDate());
        self::assertSame(21.0, $day->getDureeFormationHeures());
        self::assertSame(3 * 510, $day->getDureeBruteMinutes());
        self::assertSame(3 * 90, $day->getPauseEffectiveMinutes());
        $day->setPauseMinutes(90);
        self::assertSame($day->getDureeBruteMinutes() - 90, $day->getDureeFormationMinutes());
        self::assertSame(90, $day->getPauseEffectiveMinutes());
    }

    public function testInvalidIntervalsRemainZeroAndInvalidPausesAreRejected(): void
    {
        self::assertSame(0, (new SessionJour())->getDureeFormationMinutes());
        self::assertSame(0, $this->slot('2026-10-07 17:00', '2026-10-07 08:30')->getDureeFormationMinutes());
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        foreach ([-1, 210, 211] as $pause) {
            $day = $this->slot('2026-10-07 08:30', '2026-10-07 12:00')->setPauseMinutes($pause);
            $errors = $validator->validate($day);
            self::assertGreaterThan(0, count($errors));
            self::assertSame('pauseMinutes', $errors[0]->getPropertyPath());
        }
    }

    private function slot(string $start, string $end): SessionJour
    {
        return (new SessionJour())->setDateDebut(new \DateTimeImmutable($start))->setDateFin(new \DateTimeImmutable($end));
    }
}
