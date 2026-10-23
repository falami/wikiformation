<?php

declare(strict_types=1);
namespace App\Service\TrainerReminder;

use App\Entity\{Session, Formateur};
use App\Enum\{StatusSession, SessionPieceType};

/** Shared by notifications and manual reminders. */
final class PendingTrainerReport
{
    public static function isMissing(Session $session, Formateur $trainer, \DateTimeImmutable $now): bool
    {
        $tenantId = $session->getEntite()?->getId();
        if (!$tenantId || $trainer->getEntite()?->getId() !== $tenantId || !$session->hasFormateur($trainer)
            || $session->isSousTraitance() || !$session->getDateFin() || $session->getDateFin() >= $now
            || in_array($session->getStatus(), [StatusSession::DRAFT, StatusSession::CANCELED, StatusSession::DONE], true)) return false;
        foreach ($session->getRapportFormateurs() as $report) {
            if ($report->getEntite()?->getId() === $tenantId && $report->getFormateur()?->getId() === $trainer->getId()) return false;
        }
        foreach ($session->getPieces() as $piece) {
            if ($piece->getEntite()?->getId() === $tenantId && $piece->getType() === SessionPieceType::COMPTE_RENDU_FORMATEUR) return false;
        }
        return true;
    }
}
