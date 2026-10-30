<?php

namespace App\Service\PasseportPrevention;

/** Local structural checks only: no NIR, identity, SIRET or certification lookup, no persistence. */
final class PasseportPreventionCsvValidator
{
    public const MAX_BYTES = 5 * 1024 * 1024; // Local upload limit; the State portal accepts up to 200 MB.
    private const MAX_ERRORS = 200;
    private const LENGTHS = ['ID_DECLARATION' => 255, 'REFERENCE_DECLARATION' => 280, 'ID_UNIQUE_PARTENAIRE' => 255, 'NOM_FORMATION' => 250, 'MODALITE_DISPENSE' => 20, 'COMPETENCE_TRANSFERABLE' => 70, 'QUALIFICATION_FORMATEUR' => 45, 'FORMATION_CERTIFIANTE' => 3, 'CERTIFICATION_VISEE' => 9, 'DOMAINE_FORMATION' => 30, 'SPECIALITE_FORMATION' => 15, 'NIR' => 13, 'NOM_TITULAIRE' => 30, 'PRESENCE_EMPLOYEUR' => 3, 'SIRET_EMPLOYEUR' => 14, 'REFERENCE_EMPLOYEUR' => 255, 'TYPE_JDR' => 50, 'NOM_JDR' => 255, 'NOM_OPTION_SPECIALITE' => 255, 'MODE_OBTENTION' => 20, 'PRESENCE_FORMATION' => 3, 'RESULTAT_OBTENU' => 1000, 'MENTION_OBTENUE' => 255, 'LIEN_PREUVE' => 255, 'IDENTIFIANT_PREUVE' => 255];

    /** @return list<string> Does not echo the potentially sensitive original filename. */
    public function validateFilename(string $filename): array
    {
        if (!mb_check_encoding($filename, 'UTF-8') || trim($filename) === '') {
            return ['Le nom du fichier CSV est vide ou son encodage est invalide.'];
        }
        $errors = [];
        if (mb_strlen($filename) > 200) {
            $errors[] = 'Le nom du fichier CSV ne doit pas dépasser 200 caractères, extension comprise.';
        }
        $forbidden = ['@', '<', '>', ':', '"', '“', '”', '/', '\\', '|', '?', '*', '%'];
        if (preg_match('/[\x00-\x1F\x7F]/u', $filename)
            || array_filter($forbidden, static fn (string $character): bool => str_contains($filename, $character))) {
            $errors[] = 'Le nom du fichier contient un caractère interdit par le format d’import. Utilisez un nom simple, avec lettres, chiffres, tirets ou espaces.';
        }
        if (mb_strlen($filename) <= 4 || !str_ends_with(strtolower($filename), '.csv')) {
            $errors[] = 'Le fichier doit porter un nom suivi de l’extension .csv.';
        }
        return $errors;
    }

    /** @return array{errors:list<string>,rows:int} Errors deliberately contain neither names nor identifiers. */
    public function validateCsv(string $content, string $type, ?\DateTimeImmutable $now = null): array
    {
        $headers = PasseportPreventionFormat::headers($type);
        if (strlen($content) > self::MAX_BYTES) {
            return ['errors' => ['Le contrôle local accepte des fichiers de 5 Mo maximum.'], 'rows' => 0];
        }
        if (!mb_check_encoding($content, 'UTF-8') || str_contains($content, "\0")) {
            return ['errors' => ['Le fichier doit être un CSV texte encodé en UTF-8.'], 'rows' => 0];
        }
        $content = str_starts_with($content, "\xEF\xBB\xBF") ? substr($content, 3) : $content;
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $content);
        rewind($stream);
        try {
            if (fgetcsv($stream, 0, '|', '"', '') !== $headers) {
                return ['errors' => ['L’en-tête ne correspond pas à la trame OF '.$type.' : respecter les noms, l’ordre des colonnes et le séparateur |.'], 'rows' => 0];
            }
            $errors = [];
            $rows = 0;
            $ids = [];
            $groups = [];
            while (($values = fgetcsv($stream, 0, '|', '"', '')) !== false) {
                if ($values === [null]) {
                    continue;
                }
                ++$rows;
                if (count($values) !== count($headers)) {
                    $errors[] = 'Ligne '.($rows + 1).' : nombre de colonnes incorrect.';
                } else {
                    $row = array_combine($headers, $values);
                    foreach ($this->validateRow($row, $type, $now) as $error) {
                        $errors[] = 'Ligne '.($rows + 1).' : '.$error;
                    }
                    $id = $row['ID_UNIQUE_PARTENAIRE'];
                    if ($id !== '' && isset($ids[$id])) {
                        $errors[] = 'Ligne '.($rows + 1).' : ID_UNIQUE_PARTENAIRE déjà utilisé dans ce fichier.';
                    }
                    $ids[$id] = true;
                    $declaration = $row['ID_DECLARATION'];
                    $common = array_diff_key($row, array_flip(PasseportPreventionFormat::TITULAIRE_FIELDS));
                    if (isset($groups[$declaration])) {
                        if ($groups[$declaration]['common'] !== $common) {
                            $errors[] = 'Ligne '.($rows + 1).' : les informations communes diffèrent pour le même ID_DECLARATION.';
                        }
                        if (++$groups[$declaration]['count'] > 500) {
                            $errors[] = 'Ligne '.($rows + 1).' : une déclaration ne peut pas dépasser 500 titulaires.';
                        }
                        if ($row['NIR'] !== '' && isset($groups[$declaration]['holders'][$row['NIR']])) {
                            $errors[] = 'Ligne '.($rows + 1).' : titulaire déjà présent dans cette déclaration.';
                        }
                    } else {
                        $groups[$declaration] = ['common' => $common, 'count' => 1, 'holders' => []];
                    }
                    $groups[$declaration]['holders'][$row['NIR']] = true;
                }
                if (count($errors) >= self::MAX_ERRORS) {
                    $errors = array_slice($errors, 0, self::MAX_ERRORS);
                    $errors[] = 'Contrôle arrêté après 200 anomalies ; corrigez le fichier puis relancez le contrôle.';
                    break;
                }
            }
            if ($rows === 0) {
                $errors[] = 'Le fichier ne contient aucun titulaire à déclarer.';
            }
            return ['errors' => $errors, 'rows' => $rows];
        } finally {
            fclose($stream);
        }
    }

    /** @param array<string,string> $row @return list<string> */
    public function validateRow(array $row, string $type, ?\DateTimeImmutable $now = null): array
    {
        $row += array_fill_keys(PasseportPreventionFormat::headers($type), '');
        $errors = [];
        $today = ($now ?? new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('Y-m-d');
        $required = ['ID_DECLARATION', 'REFERENCE_DECLARATION', 'ID_UNIQUE_PARTENAIRE', 'COMPETENCE_TRANSFERABLE', 'NIR', 'NOM_TITULAIRE', 'PRESENCE_EMPLOYEUR', 'DATE_DEBUT_VALIDITE'];
        $hasFormation = $type === 'ADF' || $row['PRESENCE_FORMATION'] === 'OUI';
        if ($hasFormation) {
            array_push($required, 'NOM_FORMATION', 'DATE_DEBUT_FORMATION', 'DATE_FIN_FORMATION', 'FORMATION_CERTIFIANTE');
        } else {
            foreach (PasseportPreventionFormat::FORMATION_FIELDS as $field) {
                if ($row[$field] !== '') {
                    $errors[] = $field.' doit rester vide sans formation préalable déclarée.';
                }
            }
        }
        if ($type === 'JDR') {
            array_push($required, 'TYPE_JDR', 'NOM_JDR');
        }
        if ($hasFormation && $row['FORMATION_CERTIFIANTE'] === 'OUI') {
            $required[] = 'CERTIFICATION_VISEE';
            foreach (['DOMAINE_FORMATION', 'SPECIALITE_FORMATION'] as $field) {
                if ($row[$field] !== '') {
                    $errors[] = $field.' doit rester vide pour une formation certifiante.';
                }
            }
        } elseif ($hasFormation && $row['FORMATION_CERTIFIANTE'] === 'NON') {
            array_push($required, 'DOMAINE_FORMATION', 'SPECIALITE_FORMATION');
            if ($row['CERTIFICATION_VISEE'] !== '') {
                $errors[] = 'CERTIFICATION_VISEE doit rester vide pour une formation non certifiante.';
            }
        }
        if ($row['PRESENCE_EMPLOYEUR'] === 'OUI') {
            $required[] = 'SIRET_EMPLOYEUR';
        } elseif ($row['PRESENCE_EMPLOYEUR'] === 'NON') {
            foreach (['SIRET_EMPLOYEUR', 'REFERENCE_EMPLOYEUR'] as $field) {
                if ($row[$field] !== '') {
                    $errors[] = $field.' doit rester vide sans convention employeur déclarée.';
                }
            }
        }
        foreach ($required as $field) {
            if (trim($row[$field]) === '') {
                $errors[] = $field.' est obligatoire.';
            }
        }
        foreach ($row as $field => $value) {
            if (isset(self::LENGTHS[$field]) && mb_strlen($value) > self::LENGTHS[$field]) {
                $errors[] = $field.' dépasse '.self::LENGTHS[$field].' caractères ; ne tronquez pas une identité.';
            }
            if (preg_match('/[\x00-\x1F\x7F|]/u', $value)) {
                $errors[] = $field.' contient un séparateur ou caractère de contrôle non pris en charge.';
            }
            if (preg_match('/^\s*[=+\-@]/u', $value)) {
                $errors[] = $field.' commence par un caractère de formule tableur ; vérifiez la valeur en texte.';
            }
        }
        $enums = ['MODALITE_DISPENSE' => PasseportPreventionFormat::MODALITIES, 'QUALIFICATION_FORMATEUR' => PasseportPreventionFormat::QUALIFICATIONS, 'FORMATION_CERTIFIANTE' => ['OUI', 'NON'], 'PRESENCE_EMPLOYEUR' => ['OUI', 'NON']];
        if ($type === 'JDR') {
            $enums += ['TYPE_JDR' => ['DIPLOME', 'TITRE', 'HABILITATION', 'CERTIFICAT'], 'MODE_OBTENTION' => ['PAR_ADMISSION', 'PAR_SCORING'], 'PRESENCE_FORMATION' => ['OUI', 'NON']];
        }
        foreach ($enums as $field => $allowed) {
            if ($row[$field] !== '' && !in_array($row[$field], $allowed, true)) {
                $errors[] = $field.' : valeur non prévue par la trame officielle.';
            }
        }
        foreach (['COMPETENCE_TRANSFERABLE' => [3, 10, '/^[0-9]+$/D'], 'DOMAINE_FORMATION' => [1, 5, '/^[0-9]{5}$/D'], 'SPECIALITE_FORMATION' => [1, 3, '/^[0-9]{3}[a-z]?$/Di']] as $field => [$min, $max, $pattern]) {
            if ($row[$field] === '') {
                continue;
            }
            $codes = explode('/', $row[$field]);
            if (count($codes) < $min || count($codes) > $max || count(array_unique($codes)) !== count($codes) || array_filter($codes, static fn (string $code): bool => !preg_match($pattern, $code))) {
                $errors[] = $field.' : renseignez '.$min.' à '.$max.' codes distincts du référentiel, séparés par /.';
            }
        }
        if ($row['CERTIFICATION_VISEE'] !== '' && !preg_match('/^RS[0-9]{1,7}$/D', $row['CERTIFICATION_VISEE'])) {
            $errors[] = 'CERTIFICATION_VISEE : un code du Répertoire spécifique (RS) est attendu.';
        }
        if ($row['NIR'] !== '' && !preg_match('/^[0-9]{6}[0-9AB][0-9]{6}$/D', $row['NIR'])) {
            $errors[] = 'NIR : renseignez les 13 caractères du numéro, sans la clé à 2 chiffres.';
        }
        if ($row['SIRET_EMPLOYEUR'] !== '' && !preg_match('/^[0-9]{14}$/D', $row['SIRET_EMPLOYEUR'])) {
            $errors[] = 'SIRET_EMPLOYEUR doit comporter 14 chiffres.';
        }
        $dates = [];
        foreach (['DATE_DEBUT_FORMATION', 'DATE_FIN_FORMATION', 'DATE_DEBUT_VALIDITE', 'DATE_FIN_VALIDITE'] as $field) {
            if ($row[$field] === '') {
                continue;
            }
            $date = \DateTimeImmutable::createFromFormat('!d/m/Y', $row[$field]);
            if (!$date || $date->format('d/m/Y') !== $row[$field]) {
                $errors[] = $field.' : date invalide, format jj/mm/aaaa attendu.';
            } else {
                $dates[$field] = $date->format('Y-m-d');
                if (str_ends_with($field, '_FORMATION') && $dates[$field] > $today) {
                    $errors[] = $field.' ne peut pas être dans le futur.';
                }
            }
        }
        foreach ([['DATE_DEBUT_FORMATION', 'DATE_FIN_FORMATION'], ['DATE_FIN_FORMATION', 'DATE_DEBUT_VALIDITE'], ['DATE_DEBUT_VALIDITE', 'DATE_FIN_VALIDITE']] as [$before, $after]) {
            if (isset($dates[$before], $dates[$after]) && $dates[$after] < $dates[$before]) {
                $errors[] = $after.' ne peut pas précéder '.$before.'.';
            }
        }
        if ($type === 'JDR' && $row['LIEN_PREUVE'] !== '' && (!filter_var($row['LIEN_PREUVE'], FILTER_VALIDATE_URL) || !in_array(strtolower((string) parse_url($row['LIEN_PREUVE'], PHP_URL_SCHEME)), ['http', 'https'], true))) {
            $errors[] = 'LIEN_PREUVE doit être une adresse HTTP ou HTTPS valide.';
        }
        return array_values(array_unique($errors));
    }
}
