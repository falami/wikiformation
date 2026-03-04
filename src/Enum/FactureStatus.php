<?php

namespace App\Enum;

enum FactureStatus: string
{
    case DUE            = 'due';
    case PARTIALLY_PAID = 'partially_paid';
    case PAID           = 'paid';
    case CANCELED       = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::DUE            => 'À payer',
            self::PARTIALLY_PAID => 'Partiellement payée',
            self::PAID           => 'Payée',
            self::CANCELED       => 'Annulée',
        };
    }
}