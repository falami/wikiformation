<?php
declare(strict_types=1);
namespace App\Enum;

enum InvoiceVatCategory: string
{
    case STANDARD = 'S';
    case EXEMPT = 'E';
    case ZERO = 'Z';
    case REVERSE_CHARGE = 'AE';
    case OUTSIDE_SCOPE = 'O';

    public function label(): string
    {
        return match ($this) {
            self::STANDARD => 'TVA normale',
            self::EXEMPT => 'Exonération',
            self::ZERO => 'Taux zéro',
            self::REVERSE_CHARGE => 'Autoliquidation',
            self::OUTSIDE_SCOPE => 'Hors champ TVA',
        };
    }

    public static function choices(): array
    {
        $choices = [];
        foreach (self::cases() as $case) $choices[$case->label()] = $case->value;
        return $choices;
    }
}
