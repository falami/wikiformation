<?php

namespace App\Service\Planning;

use App\Entity\{Session, SessionJour};

final class SessionPlanningValidator
{
    /** @return list<string> Erreurs avant tout enregistrement des créneaux. */
    public function errors(Session $session): array
    {
        $errors = [];
        $slots = $session->getJours()->toArray();
        if (!$slots) {
            return ['Ajoutez au moins une journée à la session.'];
        }
        usort($slots, static fn(SessionJour $a, SessionJour $b) => $a->getDateDebut() <=> $b->getDateDebut());
        $previousEnd = null;
        foreach ($slots as $slot) {
            $start = $slot->getDateDebut();
            $end = $slot->getDateFin();
            if (!$start || !$end || $end <= $start) {
                $errors[] = 'Chaque créneau doit avoir une date de fin postérieure à sa date de début.';
                continue;
            }
            if ($slot->getPauseMinutes() !== null && ($slot->getPauseMinutes() < 0 || $slot->getPauseMinutes() >= $slot->getDureeBruteMinutes())) {
                $errors[] = 'La pause doit être positive ou nulle et plus courte que son créneau de formation.';
            }
            if ($previousEnd && $start < $previousEnd) $errors[] = 'Les créneaux de la session se chevauchent. Séparez la matinée et l’après-midi avec leurs horaires respectifs.';
            $previousEnd = max($previousEnd ?? $end, $end);
            $trainer = $slot->getFormateur() ?? $session->getFormateur();
            if (!$trainer) continue;
            if ($trainer->getEntite() !== $session->getEntite()
                && (!$trainer->getEntite()?->getId() || $trainer->getEntite()->getId() !== $session->getEntite()?->getId())) {
                $errors[] = 'Tous les formateurs doivent appartenir à l’organisme de la session.';
                continue;
            }

        }
        return array_values(array_unique($errors));
    }
}
