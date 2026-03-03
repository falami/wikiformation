<?php

namespace App\Enum;

enum SatisfactionType: string
{
    case A_CHAUD = 'a_chaud';
    case A_FROID = 'a_froid';
    public function label(): string
    {
        return match ($this) {
            self::A_CHAUD        => "A Chaud",
            self::A_FROID        => 'A Froid',
        };
    }
}
