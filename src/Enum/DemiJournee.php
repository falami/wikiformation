<?php

// src/Enum/DemiJournee.php
namespace App\Enum;

enum DemiJournee: string {
    case AM = 'AM'; // matin
    case PM = 'PM'; // après-midi
    public function label(): string
    {
        return match ($this) {
            self::AM         => 'Matin',
            self::PM         => 'Après-midi',
        };
    }
}
