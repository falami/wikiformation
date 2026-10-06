<?php
namespace App\Tests\Unit;
use App\Entity\{Session, SessionJour, Entite, Formateur, Utilisateur, Inscription, Emargement, SatisfactionAssignment, SatisfactionAttempt};
use App\Enum\{StatusSession, DemiJournee, TypeFinancement};
use App\Service\Session\TrainerSessionFollowUp;
use PHPUnit\Framework\TestCase;

final class TrainerSessionFollowUpTest extends TestCase
{
    public function testAllFortyEightSignaturesAreCountedWithStoredTrainerRole(): void
    {
        $tenant = new Entite();
        $users = [];
        foreach ([1, 2, 3, 4] as $id) {
            $user = new Utilisateur();
            (new \ReflectionProperty(Utilisateur::class, 'id'))->setValue($user, $id);
            $users[] = $user;
        }
        $trainer = (new Formateur())->setEntite($tenant)->setUtilisateur($users[0]);
        $session = (new Session())->setEntite($tenant)->setFormateur($trainer)->setStatus(StatusSession::DRAFT);
        foreach (array_slice($users, 1) as $learner) {
            $session->addInscription((new Inscription())->setEntite($tenant)->setStagiaire($learner));
        }
        foreach ([19, 20, 23, 24, 25, 26] as $day) {
            $date = sprintf('2026-03-%02d', $day);
            $session->addJour((new SessionJour())->setDateDebut(new \DateTimeImmutable($date.' 08:30'))->setDateFin(new \DateTimeImmutable($date.' 17:00')));
            foreach ($users as $index => $user) foreach ([DemiJournee::AM, DemiJournee::PM] as $period) {
                $signature = (new Emargement())->setUtilisateur($user)->setRole($index === 0 ? 'trainer' : 'stagiaire')
                    ->setDateJour(new \DateTimeImmutable($date))->setPeriode($period)->setSignedAt(new \DateTimeImmutable('2026-04-02'));
                $session->addEmargement($signature);
                if ($index === 0) $trainerSignature = $signature;
            }
        }
        $service = new TrainerSessionFollowUp();
        $today = new \DateTimeImmutable('2026-10-07');
        self::assertCount(48, $session->getEmargements());
        self::assertSame(0, $service->summarize($session, $trainer, $today)['attendance']);
        self::assertSame(3, $service->summarize($session, $trainer, $today)['satisfaction']);
        // A learner-role signature cannot fulfil the trainer's attendance obligation.
        $trainerSignature->setRole('stagiaire');
        self::assertSame(1, $service->summarize($session, $trainer, $today)['attendance']);
        $trainerSignature->setRole('formateur');
        self::assertSame(0, $service->summarize($session, $trainer, $today)['attendance']);
        $trainerSignature->setSignedAt(null);
        self::assertSame(1, $service->summarize($session, $trainer, $today)['attendance']);
    }

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
