<?php
namespace App\Enum;

enum NiveauFormation: string {
    case INITIAL            = 'initial';
    case INTERMEDIAIRE      = 'intermédiaire';
    case AVANCEE            = 'avancée';
    case PERFECTIONNEMENT   = 'perfectionnement';
    public function label(): string
    {
        return match ($this) {
            self::INITIAL               => 'Initial',
            self::INTERMEDIAIRE         => 'Intermédiaire',
            self::AVANCEE               => 'Avancée',
            self::PERFECTIONNEMENT      => 'Perfectionnement',
        };
    }
}
