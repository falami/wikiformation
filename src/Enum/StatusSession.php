<?php

namespace App\Enum;

enum StatusSession: string
{
    case CANCELED  = 'canceled';
    case DRAFT     = 'draft';
    case FULL      = 'full';
    case PUBLISHED = 'published';
    case IN_PROGRESS = 'in_progress';
    case ON_HOLD = 'on_hold';
    case MISSING_DOCUMENTS = 'missing_documents';
    case DONE      = 'done';
    public function label(): string
    {
        return match ($this) {
            self::CANCELED     => 'Annulée',
            self::DRAFT        => 'Brouillon',
            self::FULL         => 'Complet',
            self::PUBLISHED    => 'Publié',
            self::IN_PROGRESS => 'En cours',
            self::ON_HOLD => 'En attente',
            self::MISSING_DOCUMENTS => 'Documents manquants',
            self::DONE         => 'Terminée',
        };
    }
}
