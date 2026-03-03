<?php

namespace App\Enum;

enum QcmQuestionType: string
{
  case SINGLE = 'single';
  case MULTIPLE = 'multiple';

  public function label(): string
  {
    return match ($this) {
      self::SINGLE => 'Choix unique',
      self::MULTIPLE => 'Choix multiple',
    };
  }
}
