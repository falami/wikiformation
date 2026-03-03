<?php

namespace App\Enum;

enum DevisStatus: string
{
    case DRAFT    = 'draft';
    case SENT     = 'sent';
    case ACCEPTED = 'accepted';
    case INVOICED = 'invoiced';
    case CANCELED = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT    => 'brouillon',
            self::SENT     => 'envoyé',
            self::ACCEPTED => 'accepté',
            self::INVOICED => 'facturé',
            self::CANCELED => 'annulé',
        };
    }

    /** Règle métier : quand est-ce qu'on peut transformer en facture */
    public function canBeInvoiced(): bool
    {
        return match ($this) {
            self::SENT, self::ACCEPTED => true,
            default => false,
        };
    }
}
