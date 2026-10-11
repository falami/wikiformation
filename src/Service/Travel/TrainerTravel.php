<?php

declare(strict_types=1);
namespace App\Service\Travel;

use App\Entity\{EntitePreferences, Formateur, Session};

final class TrainerTravel
{
    public static function settings(?EntitePreferences $preferences, float $legacyRate): array
    {
        return array_replace(['rate' => $legacyRate, 'returnTrip' => true, 'frequency' => 'daily'], $preferences?->getTrainerTravel() ?? []);
    }

    public static function estimate(Session $session, Formateur $trainer, int $oneWayMeters, array $settings): ?array
    {
        $dates = [];
        foreach ($session->getJoursPourFormateur($trainer) as $slot) {
            $start = $slot->getDateDebut();
            $end = $slot->getDateFin();
            if (!$start || !$end || $end <= $start) continue;
            $zone = new \DateTimeZone('Europe/Paris');
            $day = $start->setTimezone($zone)->setTime(0, 0);
            $last = $end->setTimezone($zone)->modify('-1 second')->setTime(0, 0);
            for (; $day <= $last; $day = $day->modify('+1 day')) $dates[$day->format('Y-m-d')] = true;
        }
        if (!$dates || $oneWayMeters < 0) return null;
        $journeys = $settings['frequency'] === 'daily' ? count($dates) : 1;
        $legs = $journeys * ($settings['returnTrip'] ? 2 : 1);
        $km = $oneWayMeters * $legs / 1000;
        return ['days' => count($dates), 'journeys' => $journeys, 'legs' => $legs, 'kilometers' => $km, 'amount' => round($km * (float) $settings['rate'], 2)];
    }
}
