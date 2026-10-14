<?php

declare(strict_types=1);
namespace App\Service\Bpf;

/** Structure du Cerfa 10443*17 et de la notice 50199#17, vérifiée le 08/10/2026. */
final class BpfSchema
{
    public const FORM_URL = 'https://www.formulaires.service-public.fr/gf/cerfa_10443.do';
    public const NOTICE_URL = 'https://www.formulaires.service-public.fr/gf/getNotice.do?cerfaNotice=50199&cerfaFormulaire=10443';
    public const NSF_CODES = '100 110 111 112 113 114 115 116 117 118 120 121 122 123 124 125 126 127 128 130 131 132 133 134 135 136 200 201 210 211 212 213 214 220 221 222 223 224 225 226 227 230 231 232 233 234 240 241 242 243 250 251 252 253 254 255 300 310 311 312 313 314 315 320 321 322 323 324 325 326 330 331 332 333 334 335 336 341 342 343 344 345 346 410 411 412 413 414 415 421 422 423';

    public static function sections(): array
    {
        $sections = [
            'A' => ['title' => 'Identification de l’organisme', 'help' => 'Vérifiez les informations d’identification, y compris en l’absence d’activité.', 'fields' => [
                'a_name' => ['Dénomination / nom et prénom', 'text'], 'a_nda' => ['Numéro de déclaration d’activité', 'text'],
                'a_legal' => ['Forme juridique', 'text'], 'a_siret' => ['SIRET', 'text'], 'a_naf' => ['Code NAF', 'text'],
                'a_address' => ['Adresse complète', 'text'], 'a_public' => ['Accepter la publication de cette adresse', 'yesno'],
                'a_phone' => ['Téléphone', 'text'], 'a_email' => ['E-mail de contact', 'email'],
            ]],
            'B' => ['title' => 'Informations générales', 'help' => 'L’exercice comptable sélectionné en haut de page est commun aux données financières et pédagogiques.', 'fields' => [
                'b_remote' => ['Actions réalisées en tout ou partie à distance', 'yesno'],
            ]],
            'C' => ['title' => 'Origine des produits · hors taxes', 'help' => 'Saisissez les produits comptabilisés de l’exercice, en euros HT arrondis à l’euro. Les encaissements ne constituent pas automatiquement les produits de l’exercice.', 'fields' => [
                'c_1' => ['1 · Employeurs pour la formation de leurs salariés', 'money'],
                'c_a' => ['2a · Contrats d’apprentissage', 'money'], 'c_b' => ['2b · Contrats de professionnalisation', 'money'],
                'c_c' => ['2c · Promotion / reconversion par alternance', 'money'], 'c_d' => ['2d · Projets de transition professionnelle', 'money'],
                'c_e' => ['2e · Compte personnel de formation', 'money'], 'c_f' => ['2f · Dispositifs pour les personnes en recherche d’emploi', 'money'],
                'c_g' => ['2g · Dispositifs pour les travailleurs non-salariés', 'money'], 'c_h' => ['2h · Plan de développement des compétences / autres dispositifs', 'money'],
                'c_3' => ['3 · Pouvoirs publics pour leurs agents', 'money'], 'c_4' => ['4 · Instances européennes', 'money'],
                'c_5' => ['5 · État', 'money'], 'c_6' => ['6 · Conseils régionaux', 'money'], 'c_7' => ['7 · France Travail', 'money'],
                'c_8' => ['8 · Autres ressources publiques', 'money'], 'c_9' => ['9 · Particuliers à leurs propres frais', 'money'],
                'c_10' => ['10 · Autres organismes de formation, y compris CFA', 'money'], 'c_11' => ['11 · Autres produits de formation professionnelle', 'money'],
                'c_share' => ['Part du chiffre d’affaires global liée à la formation (%)', 'percent'],
            ]],
            'D' => ['title' => 'Charges de l’organisme · hors taxes', 'help' => 'À rapprocher de la comptabilité : salaires des formateurs (6411), achats de formation (604) et honoraires (6226). Les contrats prévisionnels ne remplacent pas les charges comptabilisées.', 'fields' => [
                'd_total' => ['Total des charges liées à la formation', 'money'], 'd_salary' => ['Dont salaires des formateurs', 'money'], 'd_purchase' => ['Dont achats et honoraires de formation', 'money'],
            ]],
            'E' => ['title' => 'Personnes dispensant la formation', 'help' => 'Distinguez les personnes de votre organisme des intervenants extérieurs en sous-traitance. Arrondissez les heures dispensées à l’entier le plus proche.', 'rows' => [
                'e_internal' => 'Personnes de votre organisme', 'e_external' => 'Personnes extérieures intervenant en sous-traitance',
            ]],
            'F1' => ['title' => 'Publics formés par l’organisme', 'help' => 'Comptez les participations par action, et les heures effectivement suivies. Les personnes distinctes du fichier clients ne sont pas le total BPF. Excluez ici les prestations réalisées pour un autre organisme (cadre G).', 'rows' => [
                'f1_a' => 'a · Salariés d’employeurs privés, hors apprentis', 'f1_b' => 'b · Apprentis', 'f1_c' => 'c · Personnes en recherche d’emploi',
                'f1_d' => 'd · Particuliers à leurs propres frais', 'f1_e' => 'e · Autres stagiaires',
            ]],
            'F2' => ['title' => 'Dont activité confiée à un autre organisme', 'help' => 'Sous-ensemble du cadre F1 : formations que votre organisme a confiées à un prestataire. Ne pas ajouter ce total une seconde fois à F1.', 'rows' => ['f2' => 'Actions confiées par votre organisme à un autre organisme']],
            'F3' => ['title' => 'Objectifs des prestations', 'help' => 'Le total doit correspondre à F1. Les niveaux RNCP sont des sous-rubriques de la ligne a, sans double comptage.', 'rows' => [
                'f3_a' => 'a · Diplôme, titre ou CQP enregistré au RNCP',
                'f3_a68' => 'Dont niveaux 6 à 8', 'f3_a5' => 'Dont niveau 5', 'f3_a4' => 'Dont niveau 4', 'f3_a3' => 'Dont niveau 3', 'f3_a2' => 'Dont niveau 2', 'f3_acqp' => 'Dont CQP sans niveau',
                'f3_b' => 'b · Certification ou habilitation au Répertoire spécifique', 'f3_c' => 'c · CQP non enregistré au RNCP ou au RS',
                'f3_d' => 'd · Autres formations professionnelles', 'f3_e' => 'e · Bilans de compétences', 'f3_f' => 'f · Accompagnement à la VAE',
            ]],
            'F4' => ['title' => 'Spécialités de formation', 'help' => 'Regroupez par code NSF : cinq spécialités principales au maximum, puis le reste dans « Autres ». Le total doit correspondre à F1 et F3.', 'rows' => []],
            'G' => ['title' => 'Formations réalisées pour un autre organisme', 'help' => 'Prestations reçues en sous-traitance, en lien avec la ligne C10. Ces participations et heures sont exclues des cadres F.', 'rows' => ['g' => 'Actions confiées à votre organisme par un autre organisme']],
            'H' => ['title' => 'Dirigeant et signataire', 'help' => 'Identifiez le dirigeant et la personne responsable de la déclaration. La signature et la transmission s’effectuent lors du dépôt officiel.', 'fields' => [
                'h_name' => ['Nom et prénom du dirigeant', 'text'], 'h_role' => ['Qualité du dirigeant', 'text'], 'h_place' => ['Lieu', 'text'],
                'h_date' => ['Date de préparation', 'date'], 'h_signer' => ['Nom et qualité du signataire', 'text'], 'h_email' => ['E-mail du signataire', 'email'], 'h_phone' => ['Téléphone du signataire', 'text'],
            ]],
        ];
        for ($i = 1; $i <= 5; ++$i) $sections['F4']['rows']['f4_'.$i] = 'Spécialité '.$i;
        $sections['F4']['rows']['f4_other'] = 'Autres spécialités';
        return $sections;
    }

    public static function fields(): array
    {
        $fields = [];
        foreach (self::sections() as $section => $config) {
            $fields += $config['fields'] ?? [];
            foreach ($config['rows'] ?? [] as $key => $label) {
                $fields[$key.'_count'] = [$label.' · Nombre', 'integer'];
                $fields[$key.'_hours'] = [$label.' · Heures', 'integer'];
                if ($section === 'F4' && $key !== 'f4_other') {
                    $fields[$key.'_code'] = [$label.' · Code NSF', 'nsf'];
                    $fields[$key.'_label'] = [$label.' · Libellé', 'optional_text'];
                }
            }
        }
        $fields['notes'] = ['Notes de rapprochement et références des justificatifs', 'notes'];
        return $fields;
    }

    public static function totals(array $data): array
    {
        $sum = static function (array $keys) use ($data): ?int {
            $total = 0;
            foreach ($keys as $key) { if (!isset($data[$key]) || $data[$key] === '') return null; $total += (int) $data[$key]; }
            return $total;
        };
        $totals = ['c_2' => $sum(array_map(fn($x) => 'c_'.$x, range('a', 'h')))];
        $totals['c_total'] = $sum(array_merge(['c_1'], array_map(fn($x) => 'c_'.$x, range('a', 'h')), array_map(fn($x) => 'c_'.$x, range(3, 11))));
        foreach (['f1' => ['a','b','c','d','e'], 'f3' => ['a','b','c','d','e','f'], 'f4' => ['1','2','3','4','5','other']] as $prefix => $suffixes) {
            foreach (['count','hours'] as $metric) $totals[$prefix.'_'.$metric] = $sum(array_map(fn($s) => $prefix.'_'.$s.'_'.$metric, $suffixes));
        }
        return $totals;
    }

    /** Required fields, preserving explicit zero and negative answers. */
    public static function missingFields(array $data): array
    {
        $missing = [];
        foreach (self::fields() as $key => [$label, $type]) {
            $required = !in_array($type, ['nsf', 'optional_text', 'notes'], true);
            if (preg_match('/^(f4_[1-5])_(code|label)$/', $key, $match)) {
                $required = ($data[$match[1].'_count'] ?? 0) > 0 || ($data[$match[1].'_hours'] ?? 0) > 0;
            }
            if ($required && (!isset($data[$key]) || $data[$key] === '')) {
                $missing[$key] = ['label' => $label, 'section' => strtoupper(explode('_', $key)[0])];
            }
        }
        return $missing;
    }

    public static function groupedNotices(array $issues): array
    {
        $warnings = []; $information = [];
        foreach ($issues as $issue) {
            if (str_contains($issue, 'les heures du cadre E sont proposées')) {
                $information['trainer'] = 'Cadre E : les heures des formateurs sont proposées à partir des créneaux et présences documentées. Vérifiez les heures réellement dispensées, notamment en cas de présence partielle ou de coanimation.';
            } elseif ($issue === 'Cet exercice n’est pas encore clos.' || str_starts_with($issue, 'F1 : confirmez') || str_starts_with($issue, 'Le code NAF,') || str_starts_with($issue, 'La part formation du chiffre')) {
                $information[$issue] = $issue;
            } else {
                $warnings[] = $issue;
            }
        }
        return ['warnings' => array_values(array_unique($warnings)), 'information' => array_values($information)];
    }

    public static function checks(array $data, \DateTimeImmutable $end): array
    {
        $issues = [];
        $missing = self::missingFields($data);
        if ($missing) $issues[] = count($missing).' champ(s) à renseigner : '.implode(' ; ', array_map(fn($field) => $field['section'].' · '.$field['label'], $missing)).'. Saisissez 0 uniquement pour les rubriques numériques sans activité.';
        if ($end->format('Y-m-d') >= (new \DateTimeImmutable('today'))->format('Y-m-d')) $issues[] = 'Cet exercice n’est pas encore clos.';
        $t = self::totals($data);
        foreach (['count' => 'effectifs', 'hours' => 'heures-stagiaires'] as $metric => $label) {
            foreach (['f3','f4'] as $prefix) {
                if ($t['f1_'.$metric] !== null && $t[$prefix.'_'.$metric] !== null && $t['f1_'.$metric] !== $t[$prefix.'_'.$metric]) $issues[] = 'Les '.$label.' de '.strtoupper($prefix).' ne correspondent pas à F1.';
            }
            if (isset($data['f2_'.$metric]) && $t['f1_'.$metric] !== null && $data['f2_'.$metric] > $t['f1_'.$metric]) $issues[] = 'F2 dépasse F1 pour les '.$label.'.';
            $keys = array_map(fn($s) => 'f3_a'.$s.'_'.$metric, ['68','5','4','3','2','cqp']);
            if (isset($data['f3_a_'.$metric]) && count(array_filter($keys, fn($k) => isset($data[$k]))) === count($keys) && array_sum(array_map(fn($k) => $data[$k], $keys)) !== (int) $data['f3_a_'.$metric]) $issues[] = 'La répartition des niveaux RNCP ne correspond pas à F3a pour les '.$label.'.';
        }
        if (isset($data['d_total'], $data['d_salary'], $data['d_purchase']) && $data['d_salary'] + $data['d_purchase'] > $data['d_total']) $issues[] = 'Les sous-rubriques de charges dépassent le total D.';
        $codes = [];
        for ($i = 1; $i <= 5; ++$i) {
            $key = 'f4_'.$i;
            if (($data[$key.'_count'] ?? 0) > 0 || ($data[$key.'_hours'] ?? 0) > 0) {
                if (empty($data[$key.'_code']) || empty($data[$key.'_label'])) $issues[] = 'F4 · Spécialité '.$i.' : code NSF et libellé requis pour une ligne active.';
                if (!empty($data[$key.'_code'])) { if (isset($codes[$data[$key.'_code']])) $issues[] = 'F4 : regroupez les lignes qui partagent le code NSF '.$data[$key.'_code'].'.'; $codes[$data[$key.'_code']] = true; }
            }
        }
        if (($t['c_total'] ?? 0) > 0 && isset($data['c_share']) && (int) $data['c_share'] === 0) $issues[] = 'Une activité de formation avec des produits doit représenter au moins 1 % dans la rubrique de chiffre d’affaires.';
        return $issues;
    }
}
