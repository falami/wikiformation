<?php

declare(strict_types=1);
namespace App\Service\Session;

use App\Entity\{Entite, Session};
use App\Enum\{StatusSession, StatusInscription};
use App\Service\Satisfaction\SatisfactionAssigner;
use App\Service\FormateurSatisfaction\FormateurSatisfactionAssigner;
use App\Service\Qcm\QcmAssignmentManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;

final class SessionLifecycle
{
    public function __construct(private EntityManagerInterface $em, private SatisfactionAssigner $learners, private FormateurSatisfactionAssigner $trainers, private QcmAssignmentManager $qcm) {}

    public function shouldStart(Session $session, \DateTimeImmutable $now): bool
    {
        return in_array($session->getStatus(), [StatusSession::PUBLISHED, StatusSession::FULL], true)
            && $session->getDateDebut() && $session->getDateFin()
            && $session->getDateDebut()->format('Y-m-d H:i:s') <= $now->format('Y-m-d H:i:s')
            && $session->getDateFin()->format('Y-m-d H:i:s') >= $now->format('Y-m-d H:i:s');
    }

    /** Called on authorized session/dashboard pages. Explicit waiting states remain untouched. */
    public function synchronize(Entite $entite): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
        $sessions = $this->em->getRepository(Session::class)->findBy(['entite' => $entite, 'status' => [StatusSession::PUBLISHED, StatusSession::FULL]]);
        foreach ($sessions as $session) {
            if (!$this->shouldStart($session, $now)) continue;
            $this->em->wrapInTransaction(function () use ($session, $now): void {
                $this->em->refresh($session, LockMode::PESSIMISTIC_WRITE);
                if (!$this->shouldStart($session, $now)) return;
                $session->setStatus(StatusSession::IN_PROGRESS);
                $this->assignQuestionnaires($session);
            });
        }
    }

    public function assignQuestionnaires(Session $session): void
    {
        if (!in_array($session->getStatus(), [StatusSession::FULL, StatusSession::IN_PROGRESS], true)) return;
        $creator = $session->getCreateur();
        $entite = $session->getEntite();
        if (!$creator || !$entite) return;
        $this->learners->assignForSession($session, $creator, $entite);
        $this->trainers->assignForSession($session, $creator, $entite);
        foreach ($session->getInscriptions() as $inscription) {
            if (in_array($inscription->getStatus(), [StatusInscription::ANNULE, StatusInscription::ABSENT], true)) continue;
            $this->qcm->ensurePreAndPostAssignments($inscription, $creator, $entite, flush: false);
        }
    }
}
