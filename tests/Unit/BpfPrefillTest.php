<?php
namespace App\Tests\Unit;
use App\Entity\{Inscription, SessionJour};
use App\Service\Bpf\BpfPrefill;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
final class BpfPrefillTest extends TestCase
{
    public function testWarningLinksResolveCurrentFieldsAndComparisonSections(): void
    {
        $schema = \App\Service\Bpf\BpfSchema::class;
        $warning = 'Session · inscription #1 : public non classé dans F1.';
        self::assertSame(['field' => 'rule_inscription_1_public'], $schema::warningTarget($warning, ['rule_inscription_1_public' => []]));
        self::assertSame(['section' => 'generation'], $schema::warningTarget($warning, []));
        self::assertSame(['section' => 'F4', 'compare' => 'F1'], $schema::warningTarget('Les heures-stagiaires de F4 ne correspondent pas à F1.', []));
        self::assertSame(['inscription' => 8], $schema::warningTarget('Session · inscription #8 : 3 créneau(x) sans durée de présence vérifiable.', []));
        self::assertSame(['section' => 'F1'], $schema::warningTarget('Cadre F1 incomplet : valeurs partielles.', []));
    }

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
    public function testExternalConfirmationUsesNetHoursAndPreservesExplicitAbsence(): void
    {
        $service = new BpfPrefill($this->createMock(EntityManagerInterface::class));
        $jour = (new SessionJour())->setDateDebut(new \DateTimeImmutable('2025-10-01 09:00'))->setDateFin(new \DateTimeImmutable('2025-10-01 17:00'));
        $days = [[$jour, '2025-10-01', 420]];
        $i = new Inscription();
        self::assertSame([0, 1], $service->attendance($i, $days, []));
        self::assertSame([420, 0], $service->attendance($i, $days, [], true));
        $actor = new \App\Entity\Utilisateur();
        $i->enregistrerPresenceManuelle('2025-10-01:AM', 'absent', '', $actor);
        $i->enregistrerPresenceManuelle('2025-10-01:PM', 'absent', '', $actor);
        self::assertSame([0, 0], $service->attendance($i, $days, [], true));
    }

    public function testMissingFieldsAndGroupedReminders(): void
    {
        $data = [];
        foreach (\App\Service\Bpf\BpfSchema::fields() as $key => $spec) $data[$key] = 0;
        $data['h_name'] = '';
        $missing = \App\Service\Bpf\BpfSchema::missingFields($data);
        self::assertSame(['h_name'], array_keys($missing));
        self::assertSame('H', $missing['h_name']['section']);
        $result = \App\Service\Bpf\BpfSchema::groupedNotices([
            'Session A : les heures du cadre E sont proposées par créneau',
            'Session B : les heures du cadre E sont proposées par créneau',
            'Intervenant manquant pour E.',
        ]);
        self::assertCount(1, $result['information']);
        self::assertSame(['Intervenant manquant pour E.'], $result['warnings']);
    }

    public function testRefreshPreservesManualCorrectionsAndZero(): void
    {
        $current = ['c_1' => 123, 'c_9' => 0, 'g_count' => 4, 'a_name' => ''];
        $new = ['c_1' => 500, 'c_9' => 200, 'g_count' => 6, 'a_name' => 'OF'];
        self::assertSame(['c_1' => 123,'c_9' => 0,'g_count' => 6,'a_name' => 'OF'], BpfPrefill::merge($current, $new, ['c_1' => 100,'c_9' => 100,'g_count' => 4]));
    }
}
