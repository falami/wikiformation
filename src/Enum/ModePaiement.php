<?php
namespace App\Enum;

enum ModePaiement: string 
{ 
    case CB = 'cb'; 
    case VIREMENT = 'virement'; 
    case CHEQUE = 'cheque'; 
    case ESPECES = 'especes'; 
    case OPCO = 'opco'; 
    public function label(): string
    {
        return match ($this) {
            self::CB            => 'Carte Bancaire',
            self::VIREMENT      => 'Virement',
            self::CHEQUE        => 'Chèque',
            self::ESPECES       => 'Espèces',
            self::OPCO          => 'OPCO',
        };
    }
}

