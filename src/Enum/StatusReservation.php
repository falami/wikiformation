<?php

namespace App\Enum;

enum StatusReservation: string
{
    case PENDING      = 'pending';
    case CONFIRMED    = 'confirmed';
    case CANCELED     = 'canceled';
    case REFUNDED     = 'refunded';
    case WAITING_LIST = 'waiting_list';
    case PAID = 'paid';
    public function label(): string
    {
        return match ($this) {
            self::PENDING      => 'En attente',
            self::CONFIRMED    => 'Confirmé',
            self::CANCELED     => 'annulé',
            self::REFUNDED     => 'Remboursé',
            self::WAITING_LIST => 'Liste d\'attente',
            self::PAID         => 'Payé',
        };
    }
}
