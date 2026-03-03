<?php

namespace App\Enum;

enum TypeFinancement: string
{
    case INDIVIDUEL   = 'individual';
    case CPF          = 'pta';
    case ENTREPRISE   = 'company';
    case OPCO         = 'opco';
    case OF           = 'training_organization';
    public function label(): string
    {
        return match ($this) {
            self::INDIVIDUEL      => 'Individuel',
            self::CPF             => 'CPF',
            self::ENTREPRISE      => 'Entreprise',
            self::OPCO            => 'OPCO',
            self::OF              => 'Organisme de formation',
        };
    }
}
