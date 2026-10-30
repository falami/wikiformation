<?php

namespace App\Service\PasseportPrevention;

/** Official OF templates checked on 30/10/2026; never substitute the employer templates. */
final class PasseportPreventionFormat
{
    public const SOURCE = 'https://passeport-prevention.travail-emploi.gouv.fr/organismes/guide';
    public const VERSION = 'Guides OF : ADF du 27/02/2026 et JDR version 1.0.0 ; trames vérifiées le 30/10/2026';
    public const ADF_HEADERS = ['ID_DECLARATION', 'REFERENCE_DECLARATION', 'ID_UNIQUE_PARTENAIRE', 'NOM_FORMATION', 'DATE_DEBUT_FORMATION', 'DATE_FIN_FORMATION', 'MODALITE_DISPENSE', 'COMPETENCE_TRANSFERABLE', 'QUALIFICATION_FORMATEUR', 'FORMATION_CERTIFIANTE', 'CERTIFICATION_VISEE', 'DOMAINE_FORMATION', 'SPECIALITE_FORMATION', 'NIR', 'NOM_TITULAIRE', 'PRESENCE_EMPLOYEUR', 'SIRET_EMPLOYEUR', 'REFERENCE_EMPLOYEUR', 'DATE_DEBUT_VALIDITE', 'DATE_FIN_VALIDITE'];
    public const JDR_HEADERS = ['ID_DECLARATION', 'REFERENCE_DECLARATION', 'ID_UNIQUE_PARTENAIRE', 'TYPE_JDR', 'NOM_JDR', 'NOM_OPTION_SPECIALITE', 'MODE_OBTENTION', 'COMPETENCE_TRANSFERABLE', 'PRESENCE_FORMATION', 'NOM_FORMATION', 'DATE_DEBUT_FORMATION', 'DATE_FIN_FORMATION', 'MODALITE_DISPENSE', 'QUALIFICATION_FORMATEUR', 'FORMATION_CERTIFIANTE', 'CERTIFICATION_VISEE', 'DOMAINE_FORMATION', 'SPECIALITE_FORMATION', 'NIR', 'NOM_TITULAIRE', 'PRESENCE_EMPLOYEUR', 'SIRET_EMPLOYEUR', 'REFERENCE_EMPLOYEUR', 'DATE_DEBUT_VALIDITE', 'DATE_FIN_VALIDITE', 'RESULTAT_OBTENU', 'MENTION_OBTENUE', 'LIEN_PREUVE', 'IDENTIFIANT_PREUVE'];
    public const MODALITIES = ['A_DISTANCE', 'PRESENTIEL', 'MIXTE'];
    public const QUALIFICATIONS = ['ENSEIGNANT', 'FORMATEUR_D_ADULTES_FORMATION_SPECIALISEE', 'INGENIEUR', 'PREVENTEUR', 'PSYCHOLOGUE', 'RESPONSABLE_QHSE', 'ANCIEN_PROFESSIONNEL'];
    public const FORMATION_FIELDS = ['NOM_FORMATION', 'DATE_DEBUT_FORMATION', 'DATE_FIN_FORMATION', 'MODALITE_DISPENSE', 'QUALIFICATION_FORMATEUR', 'FORMATION_CERTIFIANTE', 'CERTIFICATION_VISEE', 'DOMAINE_FORMATION', 'SPECIALITE_FORMATION'];
    public const TITULAIRE_FIELDS = ['ID_UNIQUE_PARTENAIRE', 'NIR', 'NOM_TITULAIRE', 'PRESENCE_EMPLOYEUR', 'SIRET_EMPLOYEUR', 'REFERENCE_EMPLOYEUR', 'DATE_DEBUT_VALIDITE', 'DATE_FIN_VALIDITE', 'RESULTAT_OBTENU', 'MENTION_OBTENUE', 'LIEN_PREUVE', 'IDENTIFIANT_PREUVE'];

    public static function headers(string $type): array
    {
        return match ($type) {
            'ADF' => self::ADF_HEADERS,
            'JDR' => self::JDR_HEADERS,
            default => throw new \InvalidArgumentException('Choisissez ADF ou JDR.'),
        };
    }

    public static function templateUrl(string $type): string
    {
        self::headers($type);
        return 'https://passeport-prevention.travail-emploi.gouv.fr/espace-public/sites/pp/files/2026-02/'.($type === 'ADF' ? 'Trame%20Attestation%20De%20Formation.csv' : 'Trame%20Justificatif%20De%20Reussite.csv');
    }

    public static function guideUrl(string $type): string
    {
        self::headers($type);
        return 'https://passeport-prevention.travail-emploi.gouv.fr/espace-public/sites/pp/files/2026-02/Guide%20import%20en%20masse_OF_'.$type.'.pdf';
    }
}
