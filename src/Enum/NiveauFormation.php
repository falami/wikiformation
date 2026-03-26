<?php
namespace App\Enum;

enum NiveauFormation: string {
    case INITIAL            = 'initial';
    case INTERMEDIAIRE      = 'intermédiaire';
    case AVANCEE            = 'avancée';
    case PERFECTIONNEMENT   = 'perfectionnement';
    case NIVEAU1   = 'niveau 1';
    case NIVEAU2   = 'niveau 2';
    case NIVEAU3   = 'niveau 3';
    case NIVEAU4   = 'niveau 4';
    case NIVEAU5   = 'niveau 5';
    case NIVEAU6   = 'niveau 6';
    case NIVEAU7   = 'niveau 7';

    public function label(): string
    {
        return match ($this) {
            self::INITIAL               => 'Initial',
            self::INTERMEDIAIRE         => 'Intermédiaire',
            self::AVANCEE               => 'Avancée',
            self::PERFECTIONNEMENT      => 'Perfectionnement',
            self::NIVEAU1      => 'Niveau 1',
            self::NIVEAU2      => 'Niveau 2',
            self::NIVEAU3      => 'Niveau 3',
            self::NIVEAU4      => 'Niveau 4',
            self::NIVEAU5      => 'Niveau 5',
            self::NIVEAU6      => 'Niveau 6',
            self::NIVEAU7      => 'Niveau 7',
        };
    }
}
