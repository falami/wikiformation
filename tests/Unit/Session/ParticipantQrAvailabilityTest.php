<?php
namespace App\Tests\Unit\Session;

use App\Entity\{Session, SessionJour};
use App\Service\Session\ParticipantQrAvailability;
use PHPUnit\Framework\TestCase;

final class ParticipantQrAvailabilityTest extends TestCase
{
    public function testOpeningAtFirstSlotAndRemainingOpenAfterwards(): void
    {
        $session = new Session();
        self::assertFalse(ParticipantQrAvailability::hasStarted($session));
        $start = new \DateTimeImmutable('2026-11-04 09:00:00', new \DateTimeZone('Europe/Paris'));
        $session->addJour((new SessionJour())->setDateDebut($start->modify('+1 day')));
        $session->addJour((new SessionJour())->setDateDebut($start));
        self::assertFalse(ParticipantQrAvailability::hasStarted($session, $start->modify('-1 second')));
        self::assertTrue(ParticipantQrAvailability::hasStarted($session, $start));
        self::assertTrue(ParticipantQrAvailability::hasStarted($session, $start->modify('+1 year')));
    }
}
