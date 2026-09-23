<?php

namespace App\Enum;

enum TypeFinancement: string
{
    case NON         = 'non';
    case OUI           = 'oui';
    public function label(): string
    {
        return match ($this) {
            self::NON             => 'NON',
            self::OUI             => 'OUI',
        };
    }
}
