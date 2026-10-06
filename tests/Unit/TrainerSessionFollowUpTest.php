<?php
namespace App\Tests\Unit;
use App\Entity\{Session, SessionJour, Entite, Formateur, Utilisateur, Inscription, Emargement, SatisfactionAssignment, SatisfactionAttempt};
use App\Enum\{StatusSession, DemiJournee, TypeFinancement};
use App\Service\Session\TrainerSessionFollowUp;
use PHPUnit\Framework\TestCase;

final class TrainerSessionFollowUpTest extends TestCase
{
    public function testPastSessionRemainsUntilCompleteButNotClosedOrUnassigned(): void
    {
        $tenant = new Entite();
        $teacher = new Utilisateur(); $learner = new Utilisateur();
        (new \ReflectionProperty(Utilisateur::class, 'id'))->setValue($teacher, 1);
        (new \ReflectionProperty(Utilisateur::class, 'id'))->setValue($learner, 2);
        $trainer = (new Formateur())->setEntite($tenant)->setUtilisateur($teacher);
        $session = (new Session())->setEntite($tenant)->setFormateur($trainer)->setStatus(StatusSession::PUBLISHED);
        $session->addJour((new SessionJour())->setDateDebut(new \DateTimeImmutable('2026-10-05 08:30'))->setDateFin(new \DateTimeImmutable('2026-10-05 17:00')));
        $session->addInscription((new Inscription())->setEntite($tenant)->setStagiaire($learner));
        $service = new TrainerSessionFollowUp(); $today = new \DateTimeImmutable('2026-10-06');
        $pending = $service->summarize($session, $trainer, $today);
        self::assertSame(4, $pending['attendance']);
        self::assertSame(1, $pending['satisfaction']);
        self::assertNull($service->summarize($session, $trainer, new \DateTimeImmutable('2026-10-05')));
        self::assertNull($service->summarize($session, new Formateur(), $today));
        $session->setStatus(StatusSession::DONE);
        self::assertNull($service->summarize($session, $trainer, $today));
        $session->setStatus(StatusSession::PUBLISHED)->setTypeFinancement(TypeFinancement::OUI);
        self::assertSame(0, $service->summarize($session, $trainer, $today)['attendance']);
        $session->setTypeFinancement(TypeFinancement::NON);
        foreach ([$teacher, $learner] as $u) foreach ([DemiJournee::AM, DemiJournee::PM] as $period) {
            $session->addEmargement((new Emargement())->setUtilisateur($u)->setRole($u === $teacher ? 'formateur' : 'stagiaire')->setDateJour(new \DateTimeImmutable('2026-10-05'))->setPeriode($period)->setSignedAt(new \DateTimeImmutable()));
        }
        self::assertSame(0, $service->summarize($session, $trainer, $today)['attendance']);
        $assignment = (new SatisfactionAssignment())->setStagiaire($learner);
        $attempt = (new SatisfactionAttempt())->setSubmittedAt(new \DateTimeImmutable());
        $assignment->setAttempt($attempt); $session->addSatisfactionAssignment($assignment);
        self::assertNull($service->summarize($session, $trainer, $today));
    }
}
