<?php

namespace App\Enum;

enum StatusSession: string
{
    case CANCELED  = 'canceled';
    case DRAFT     = 'draft';
    case FULL      = 'full';
    case PUBLISHED = 'published';
    case DONE      = 'done';
    public function label(): string
    {
        return match ($this) {
            self::CANCELED     => 'Annulée',
            self::DRAFT        => 'Brouillon',
            self::FULL         => 'Complet',
            self::PUBLISHED    => 'Publié',
            self::DONE         => 'Terminée',
        };
    }
}
