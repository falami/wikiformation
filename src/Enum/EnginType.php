<?php

namespace App\Enum;

enum EnginType: string
{
    case CATAMARAN = 'catamaran';
    case MONOCOQUE = 'monocoque';
    case VOILIER = 'voilier';
    case PELLE_HYDRAULIQUE = 'pelle hydraulique';
    case MOTOBASCULEUR = 'motobasculeur';
    case CHARGEUR = 'chargeur';
    case COMPACTEUR = 'Compacteur';
    case BOUTEUR = 'bouteur';
    case NACELLE = 'nacelle';

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
