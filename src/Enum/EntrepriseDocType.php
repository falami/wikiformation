<?php

declare(strict_types=1);

namespace App\Enum;

enum EntrepriseDocType: string
{
  case ATTESTATION_EMPLOYEUR      = 'attestation_employeur';
  case OPCO_PEC                   = 'opco_pec';
  case REGLEMENT_INTERIEUR_SIGNE  = 'reglement_interieur_signe';
  case CONVOCATION_SIGNEE         = 'convocation_signee';
  case Kbis                       = 'KBIS';
  case RIB                        = 'RIB';
  case AUTRE                      = 'autre';

  public function label(): string
  {
    return match ($this) {
      self::ATTESTATION_EMPLOYEUR     => 'Attestation employeur',
      self::OPCO_PEC                  => 'Accord de prise en charge (OPCO)',
      self::REGLEMENT_INTERIEUR_SIGNE => 'Règlement intérieur signé',
      self::CONVOCATION_SIGNEE        => 'Convocation signée',
      self::Kbis                      => 'KBIS',
      self::RIB                       => 'RIB',
      self::AUTRE                     => 'Autre document',
    };
  }
}
