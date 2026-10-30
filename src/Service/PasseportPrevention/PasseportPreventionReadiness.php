<?php

declare(strict_types=1);

namespace App\Service\PasseportPrevention;

use App\Entity\{Entite, Inscription, Session};
use App\Enum\{StatusInscription, StatusSession};

/** Technical selection only: SST eligibility and attendance must be confirmed by the declarant. */
final class PasseportPreventionReadiness
{
    /** @return list<Inscription> */
    public function participants(Session $session, Entite $entite): array
    {
        if (!$entite->getId() || $session->getEntite()?->getId() !== $entite->getId()) return [];
        return array_values(array_filter($session->getInscriptions()->toArray(), static fn ($inscription) =>
            $inscription instanceof Inscription
            && $inscription->getEntite()?->getId() === $entite->getId()
            && $inscription->getSession() === $session
            && $inscription->getStagiaire() !== null
            && in_array($inscription->getStatus(), [StatusInscription::CONFIRME, StatusInscription::EN_COURS, StatusInscription::TERMINE], true)
        ));
    }

    public function inspect(Session $session, Entite $entite, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
        $start = null; $end = null; $invalidSlot = false;
        foreach ($session->getJours() as $slot) {
            $from = $slot->getDateDebut(); $to = $slot->getDateFin();
            if (!$from || !$to || $to <= $from) $invalidSlot = true;
            if ($from && (!$start || $from < $start)) $start = $from;
            if ($to && (!$end || $to > $end)) $end = $to;
        }
        $participants = count($this->participants($session, $entite));
        $reason = match (true) {
            !$entite->getId(), $session->getEntite()?->getId() !== $entite->getId() => 'Session d’un autre organisme',
            $session->isSousTraitance() => 'Déclaration à effectuer par l’organisme donneur d’ordre',
            $session->getStatus() === StatusSession::CANCELED => 'Session annulée',
            $session->getStatus() === StatusSession::DRAFT => 'Session en brouillon',
            !$start || !$end || $invalidSlot => 'Planning à compléter ou à corriger',
            $end > $now => 'Session non achevée',
            $participants === 0 => 'Aucune inscription confirmée, en cours ou terminée',
            default => '',
        };
        return compact('session', 'start', 'end', 'participants', 'reason') + ['selectable' => $reason === ''];
    }
}
