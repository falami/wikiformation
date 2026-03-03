<?php
// src/Enum/QuestionType.php
namespace App\Enum;

enum QuestionType: string
{
    case SINGLE   = 'single';   // radio
    case MULTIPLE = 'multiple'; // checkboxes
    case BOOLEAN  = 'boolean';  // oui/non
    case TEXT     = 'text';     // réponse libre
    public function label(): string
    {
        return match ($this) {
            self::SINGLE        => "Simple",
            self::MULTIPLE      => 'Multiple',
            self::BOOLEAN       => 'Vrai/Faux',
            self::TEXT          => 'Texte',
        };
    }
}
