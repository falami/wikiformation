<?php
namespace App\Enum;

enum ModePaiement: string 
{ 
    case CB = 'cb'; 
    case VIREMENT = 'virement'; 
    case CHEQUE = 'cheque'; 
    case ESPECES = 'especes'; 
    case OPCO = 'opco';
    case PRELEVEMENT = 'prelevement';
    public function label(): string
    {
        return match ($this) {
            self::CB            => 'Carte Bancaire',
            self::VIREMENT      => 'Virement',
            self::CHEQUE        => 'Chèque',
            self::ESPECES       => 'Espèces',
            self::OPCO          => 'OPCO',
            self::PRELEVEMENT   => 'Prélèvement bancaire',
        };
    }
    public function invoiceLabel(): string
    {
        return $this === self::VIREMENT ? 'Virement bancaire' : $this->label();
    }

    /** Keep readable strings in existing invoice JSON and PDF snapshots. */
    public static function invoiceChoices(): array
    {
        $choices = [];
        foreach (self::cases() as $mode) $choices[$mode->invoiceLabel()] = $mode->invoiceLabel();
        return $choices;
    }

    public static function fromInvoiceValue(string $value): ?self
    {
        $value = mb_strtolower(trim($value));
        foreach (self::cases() as $mode) {
            if (in_array($value, [$mode->value, mb_strtolower($mode->label()), mb_strtolower($mode->invoiceLabel())], true)) return $mode;
        }
        return match ($value) {
            'carte bancaire', 'carte', 'carte bleue' => self::CB,
            'cheque' => self::CHEQUE,
            'especes' => self::ESPECES,
            'prélèvement', 'prelevement bancaire' => self::PRELEVEMENT,
            default => null,
        };
    }
}

