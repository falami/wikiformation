<?php

namespace App\Enum;

enum QcmPhase: string
{
  case PRE  = 'pre';
  case POST = 'post';

  public function label(): string
  {
    return match ($this) {
      self::PRE => 'Début de formation',
      self::POST => 'Fin de formation',
    };
  }
}
