<?php

namespace App\Enum;

enum EnginType: string
{
    case CATAMARAN = 'Catamaran';
    case MONOCOQUE = 'Monocoque';
    case VOILIER = 'Voilier';
    case PELLE_HYDRAULIQUE = 'Pelle hydraulique';
    case MOTOBASCULEUR = 'Motobasculeur';
    case CHARGEUR = 'Chargeur';
    case COMPACTEUR = 'Compacteur';
    case BOUTEUR = 'Bouteur';
    case NACELLE = 'Nacelle';

    public function label(): string
    {
        return match ($this) {
            self::CATAMARAN         => 'Catamaran',
            self::MONOCOQUE         => 'Monocoque',
            self::VOILIER           => 'Voilier',
            self::PELLE_HYDRAULIQUE => 'Pelle hydraulique',
            self::MOTOBASCULEUR     => 'Motobasculeur',
            self::CHARGEUR         => 'Chargeur',
            self::COMPACTEUR        => 'Compacteur',
            self::BOUTEUR           => 'Bouteur',
            self::NACELLE           => 'Nacelle',
        };
    }
}
