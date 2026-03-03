<?php
// src/Enum/SuggestedLevel.php
declare(strict_types=1);

namespace App\Enum;

enum SuggestedLevel: string
{
  case INITIAL = 'INITIAL';
  case INTERMEDIAIRE = 'INTERMEDIAIRE';
  case AVANCE = 'AVANCE';
  case EXPERT = 'EXPERT';

  public function label(): string
  {
    return match ($this) {
      self::INITIAL => 'Initial',
      self::INTERMEDIAIRE => 'Intermédiaire',
      self::AVANCE => 'Avancé',
      self::EXPERT => 'Expert',
    };
  }
}
