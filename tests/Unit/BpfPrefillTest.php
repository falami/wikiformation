<?php
namespace App\Tests\Unit;
use App\Entity\{Inscription, SessionJour};
use App\Service\Bpf\BpfPrefill;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
final class BpfPrefillTest extends TestCase
{
    public function testRemoteModeUsesSessionRateNotAssessmentText(): void
    {
        $session = new \App\Entity\Session();
        self::assertNull(BpfPrefill::remoteDefault($session));
        $session->setFormation((new \App\Entity\Formation())->setModalitesEvaluation('Présentiel'));
        self::assertNull(BpfPrefill::remoteDefault($session));
        $session->setTauxDistanciel(0);
        self::assertSame('no', BpfPrefill::remoteDefault($session));
        $session->setTauxDistanciel(50);
        self::assertSame('yes', BpfPrefill::remoteDefault($session));
        $session->setTauxDistanciel(100);
        self::assertSame('yes', BpfPrefill::remoteDefault($session));
    }

    public function testAttendanceDoesNotInventMissingOrSplitBreakHours(): void
    {
        $service = new BpfPrefill($this->createMock(EntityManagerInterface::class));
        $jour = (new SessionJour())->setDateDebut(new \DateTimeImmutable('2025-10-01 09:00'))->setDateFin(new \DateTimeImmutable('2025-10-01 17:00'));
        $i = new Inscription();
        $actor = new \App\Entity\Utilisateur();
        $i->enregistrerPresenceManuelle('2025-10-01:AM', 'paper', '', $actor);
        $i->enregistrerPresenceManuelle('2025-10-01:PM', 'absent', '', $actor);
        self::assertSame([0,1], $service->attendance($i, [[$jour,'2025-10-01',390]], []));
        self::assertSame([240,0], $service->attendance($i, [[$jour,'2025-10-01',480]], []));
        self::assertSame([390,0], $service->attendance($i, [[$jour,'2025-10-01',390]], ['2025-10-01:PM' => true]));
        $i = new Inscription();
        self::assertSame([0,1], $service->attendance($i, [[$jour,'2025-10-01',390]], []));
    }
    public function testRefreshPreservesManualCorrectionsAndZero(): void
    {
        $current = ['c_1' => 123, 'c_9' => 0, 'g_count' => 4, 'a_name' => ''];
        $new = ['c_1' => 500, 'c_9' => 200, 'g_count' => 6, 'a_name' => 'OF'];
        self::assertSame(['c_1' => 123,'c_9' => 0,'g_count' => 6,'a_name' => 'OF'], BpfPrefill::merge($current, $new, ['c_1' => 100,'c_9' => 100,'g_count' => 4]));
    }
}
