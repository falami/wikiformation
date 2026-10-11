<?php

declare(strict_types=1);
namespace App\Tests\Unit;
use App\Entity\{EntitePreferences, Formateur, Session, SessionJour};
use App\Service\Travel\TrainerTravel;
use PHPUnit\Framework\TestCase;

final class TrainerTravelTest extends TestCase
{
    public function testDaysAreDeduplicatedAndOtherTrainersExcluded(): void
    {
        $trainer = new Formateur();
        $session = (new Session())->setFormateur($trainer);
        foreach ([['2026-10-08 08:00', '2026-10-08 12:00', null], ['2026-10-08 13:00', '2026-10-08 17:00', null], ['2026-10-09 08:00', '2026-10-09 17:00', null], ['2026-10-10 08:00', '2026-10-10 17:00', new Formateur()]] as [$start, $end, $other]) {
            $session->addJour((new SessionJour())->setDateDebut(new \DateTimeImmutable($start))->setDateFin(new \DateTimeImmutable($end))->setFormateur($other));
        }
        $settings = TrainerTravel::settings(null, .4);
        $estimate = TrainerTravel::estimate($session, $trainer, 25125, $settings);
        self::assertSame(2, $estimate['days']);
        self::assertSame(4, $estimate['legs']);
        self::assertSame(40.2, $estimate['amount']);
        $settings['frequency'] = 'once';
        self::assertSame(20.1, TrainerTravel::estimate($session, $trainer, 25125, $settings)['amount']);
        $settings['returnTrip'] = false;
        self::assertSame(10.05, TrainerTravel::estimate($session, $trainer, 25125, $settings)['amount']);
        self::assertSame(0.0, TrainerTravel::estimate($session, $trainer, 0, $settings)['amount']);
    }

    public function testSlotEndingAtMidnightDoesNotAddAnExtraTravelDay(): void
    {
        $trainer = new Formateur();
        $session = (new Session())->setFormateur($trainer);
        $session->addJour((new SessionJour())->setDateDebut(new \DateTimeImmutable('2026-10-08 08:00 Europe/Paris'))->setDateFin(new \DateTimeImmutable('2026-10-10 00:00 Europe/Paris')));
        self::assertSame(2, TrainerTravel::estimate($session, $trainer, 10000, TrainerTravel::settings(null, .4))['days']);
    }

    public function testUnknownDatesAreNotReportedAsZeroCost(): void
    {
        self::assertNull(TrainerTravel::estimate(new Session(), new Formateur(), 12000, TrainerTravel::settings(null, .4)));
    }

    public function testPreferencesRemainIndependentAndLegacyRateIsFallback(): void
    {
        $first = (new EntitePreferences())->setTrainerTravel(['rate' => .65, 'frequency' => 'once', 'returnTrip' => false]);
        self::assertSame(.65, TrainerTravel::settings($first, .4)['rate']);
        self::assertSame(.4, TrainerTravel::settings(new EntitePreferences(), .4)['rate']);
        self::assertFalse(TrainerTravel::settings($first, .4)['returnTrip']);
    }
}
