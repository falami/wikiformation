<?php

namespace App\Enum;

enum FactureStatus: string
{
    case DUE      = 'due';
    case PAID     = 'paid';
    case CANCELED = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::DUE      => 'À payer',
            self::PAID     => 'Payée',
            self::CANCELED => 'Annulée',
        };
    }
}
