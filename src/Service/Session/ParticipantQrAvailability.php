<?php

namespace App\Service\Session;

use App\Entity\Session;

final class ParticipantQrAvailability
{
    public static function hasStarted(Session $session, ?\DateTimeImmutable $now = null): bool
    {
        $start = null;
        foreach ($session->getJours() as $day) {
            $date = $day->getDateDebut();
            if ($date && (!$start || $date < $start)) $start = $date;
        }
        $start ??= $session->getDateDebut();
        if (!$start) return false;
        // Session schedule values are local wall-clock times in France.
        $start = new \DateTimeImmutable($start->format('Y-m-d H:i:s'), new \DateTimeZone('Europe/Paris'));
        return $start <= ($now ?? new \DateTimeImmutable());
    }
}
