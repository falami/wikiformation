<?php
declare(strict_types=1);
namespace App\Enum;

enum InvoiceVatExigibility: string
{
    case RECEIPTS = 'receipts';
    case DEBITS = 'debits';

    public function label(): string
    {
        return $this === self::DEBITS ? 'D’après les débits' : 'Sur les encaissements';
    }

    /** Preserve the boolean representation in existing invoice terms. */
    public static function choices(): array
    {
        $choices = [];
        foreach (self::cases() as $case) $choices[$case->label()] = $case === self::DEBITS;
        return $choices;
    }
}
