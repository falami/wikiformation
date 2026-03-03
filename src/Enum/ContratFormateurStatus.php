<?php

namespace App\Enum;

enum ContratFormateurStatus: string
{
  case BROUILLON = 'BROUILLON';
  case ENVOYE = 'ENVOYE';
  case SIGNE = 'SIGNE';
  case RESILIE = 'RESILIE';
  case ARCHIVE = 'ARCHIVE';
  public function label(): string
  {
    return match ($this) {
      self::BROUILLON => 'Brouillon',
      self::ENVOYE    => 'Envoyé',
      self::SIGNE     => 'Signé',
      self::RESILIE   => 'Résilié',
      self::ARCHIVE   => 'Archive',
    };
  }
}
