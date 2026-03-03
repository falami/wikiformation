<?php
namespace App\Enum;

enum StatusInscription: string { 
    case PREINSCRIT='preinscrit'; 
    case CONFIRME='confirme'; 
    case EN_COURS='en_cours'; 
    case TERMINE='termine'; 
    case ANNULE='annule'; 
    case ABSENT='absent'; 
    public function label(): string
    {
        return match ($this) {
            self::PREINSCRIT        => 'Préinscrit',
            self::CONFIRME          => 'Confirmé',
            self::EN_COURS          => 'En cours',
            self::TERMINE           => 'Terminé',
            self::ANNULE            => 'Annulé',
            self::ABSENT            => 'Absent',
        };
    }
}

