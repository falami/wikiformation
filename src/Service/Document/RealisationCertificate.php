<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Entity\{Attestation, Inscription};
use App\Enum\StatusInscription;
use App\Service\AssiduiteCalculator;

/** La présence par demi-journée ne permet pas de déduire une durée à partir d'un simple pourcentage. */
final class RealisationCertificate
{
    public function __construct(private readonly AssiduiteCalculator $attendance) {}

    public function data(Inscription $inscription, ?Attestation $attestation = null): array
    {
        $session = $inscription->getSession();
        $hours = $this->hours($inscription);
        $start = $end = null;
        foreach ($session?->getJours() ?? [] as $day) {
            if ($day->getDateDebut() && (!$start || $day->getDateDebut() < $start)) $start = $day->getDateDebut();
            if ($day->getDateFin() && (!$end || $day->getDateFin() > $end)) $end = $day->getDateFin();
        }
        $issues = [];
        if ($inscription->getStatus() !== StatusInscription::TERMINE) $issues[] = 'L’inscription doit être clôturée.';
        if ($hours === null) $issues[] = 'Complétez les présences et les créneaux pour justifier la durée réellement suivie. Pour une présence partielle, détaillez les horaires du matin et de l’après-midi hors pauses.';
        elseif ($hours <= 0) $issues[] = 'Aucune heure de formation effectivement suivie n’est justifiée.';
        if (!$start || !$end) $issues[] = 'La période de réalisation est manquante.';
        $issuedAt = $attestation?->getDateDelivrance();
        if ($issuedAt && ($issuedAt->format('Y-m-d') > (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris')))->format('Y-m-d')
            || ($end && $issuedAt->format('Y-m-d') < $end->format('Y-m-d')))) $issues[] = 'La date de délivrance doit être comprise entre la fin de formation et aujourd’hui.';
        return ['hours' => $hours, 'start' => $start, 'end' => $end, 'issues' => $issues, 'ready' => $issues === []];
    }

    public function hours(Inscription $inscription): ?float
    {
        $session = $inscription->getSession();
        if (!$session || $session->isSousTraitance()) return null;
        $manual = $inscription->getPresencesManuelles();
        $online = $inscription->getId() ? $this->attendance->online($inscription) : [];
        $minutes = 0; $slots = 0; $intervals = [];
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
        foreach ($session->getJours() as $day) {
            $start = $day->getDateDebut(); $end = $day->getDateFin();
            if (!$start || !$end || $end <= $start || $end > $now || $start->format('Y-m-d') !== $end->format('Y-m-d')) return null;
            foreach ($intervals as [$otherStart, $otherEnd]) {
                if ($start < $otherEnd && $end > $otherStart) return null;
            }
            $intervals[] = [$start, $end];
            ++$slots;
            $date = $start->format('Y-m-d'); $split = $start->setTime(13, 0);
            $periods = [];
            if ($start < $split) $periods['AM'] = (min($end, $split)->getTimestamp() - $start->getTimestamp()) / 60;
            if ($end > $split) $periods['PM'] = ($end->getTimestamp() - max($start, $split)->getTimestamp()) / 60;
            $present = [];
            foreach ($periods as $period => $duration) {
                $key = $date . ':' . $period;
                $status = $manual[$key]['status'] ?? 'unknown';
                if (!isset($online[$key]) && !in_array($status, ['paper', 'absent'], true)) return null;
                $present[$period] = isset($online[$key]) || $status === 'paper';
            }
            $count = count(array_filter($present));
            if ($count === count($present)) $minutes += $day->getDureeFormationMinutes();
            elseif ($count > 0) {
                // Une pause globale sans horaire ne peut pas être imputée arbitrairement à la période absente.
                if ($day->getPauseEffectiveMinutes() > 0) return null;
                foreach ($periods as $period => $duration) if ($present[$period]) $minutes += $duration;
            }
        }
        return $slots ? round($minutes / 60, 2) : null;
    }
}
