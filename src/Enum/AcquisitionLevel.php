<?php

namespace App\Enum;

enum AcquisitionLevel: string
{
  case ACQUIRED = 'acquired';
  case PARTIAL = 'partial';
  case NOT_ACQUIRED = 'not_acquired';

  public function label(): string
  {
    return match ($this) {
      self::ACQUIRED => 'Acquis',
      self::PARTIAL => 'Partiellement acquis',
      self::NOT_ACQUIRED => 'Non acquis',
    };
  }
}
