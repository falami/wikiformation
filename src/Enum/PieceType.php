<?php

namespace App\Enum;

enum PieceType: string implements LabelledEnum
{
    case CNI_RECTO                  = 'cni_recto';
    case CNI_VERSO                  = 'cni_verso';
    case CONVOCATION_SIGNEE         = 'convocation_signee';
    case REGLEMENT_INTERIEUR_SIGNE  = 'reglement_interieur_signee';
    case OPCO_PEC                   = 'opco_prise_en_charge';
    case JUSTIF_DOMICILE            = 'justificatif_domicile';
    case CERTIF_MEDICAL = 'certificat_medical';

    public function label(): string
    {
        return match ($this) {
            self::CNI_RECTO                 => "Carte nationale d'identité (recto)",
            self::CNI_VERSO                 => "Carte nationale d'identité (verso)",
            self::CONVOCATION_SIGNEE        => 'Convocation signée',
            self::REGLEMENT_INTERIEUR_SIGNE => 'Règlement intérieur signé',
            self::OPCO_PEC                  => 'Prise en charge OPCO',
            self::JUSTIF_DOMICILE           => 'Justificatif de domicile',
            self::CERTIF_MEDICAL            => 'Certificat médical',
        };
    }
}
