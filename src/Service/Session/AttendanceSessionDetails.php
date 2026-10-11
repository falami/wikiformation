<?php

declare(strict_types=1);

namespace App\Service\Session;

use App\Entity\Session;

final class AttendanceSessionDetails
{
    public static function forDay(Session $session, \DateTimeInterface $date): array
    {
        $site = $session->getSite();
        $hours = [];
        foreach ($session->getJours() as $day) {
            if ($day->getDateDebut()?->format('Y-m-d') === $date->format('Y-m-d') && $day->getDateFin()) {
                $hours[] = $day->getDateDebut()->format('H:i').' – '.$day->getDateFin()->format('H:i');
            }
        }
        return [
            'title' => $session->getFormation()?->getTitre() ?: ($session->getFormationIntituleLibre() ?: 'Formation'),
            'site' => $site?->getNom() ?: 'Lieu non renseigné',
            'address' => implode(', ', array_filter([$site?->getAdresse(), trim(($site?->getCodePostal() ?? '').' '.($site?->getVille() ?? '')), $site?->getPays()])),
            'hours' => implode(' / ', $hours) ?: 'Aucun créneau ce jour',
        ];
    }
}
