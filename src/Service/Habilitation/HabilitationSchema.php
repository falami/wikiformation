<?php

declare(strict_types=1);

namespace App\Service\Habilitation;

final class HabilitationSchema
{
    public function example(): array
    {
        $groups = [
            'Travaux d’ordre non électrique' => [
                ['Exécutant', ['B0', 'H0', 'H0V', 'BF-HF']],
                ['Chargé de chantier', ['B0', 'H0', 'H0V', 'BF-HF']],
                ['Opération BT élémentaire sur chaîne photovoltaïque', ['BP']],
            ],
            'Interventions BT' => [
                ['Chargé d’intervention élémentaire', ['BS']],
                ['Chargé d’intervention générale', ['BR']],
            ],
            'Opérations d’ordre électrique' => [
                ['Exécutant', ['B1', 'B1V', 'H1', 'H1V']],
                ['Chargé de travaux', ['B2', 'B2V', 'B2V Essai', 'H2', 'H2V', 'H2V Essai']],
                ['Chargé de consignation', ['BC', 'HC']],
                ['Chargé d’opérations spécifiques', ['BE manœuvres', 'BE essais', 'BE mesures', 'HE manœuvres', 'HE essais', 'HE mesures']],
            ],
        ];
        $sections = []; $i = 0;
        foreach ($groups as $title => $rows) {
            $section = ['id' => 'section_'.count($sections), 'title' => $title, 'rows' => []];
            foreach ($rows as [$label, $symbols]) {
                $section['rows'][] = ['id' => 'row_'.$i++, 'title' => $label, 'fields' => [
                    ['id' => 'symbols', 'label' => 'Symbole d’habilitation électrique', 'type' => 'checkbox', 'options' => $symbols],
                    ['id' => 'voltage', 'label' => 'Domaine de tension / tensions concernées', 'type' => 'checkbox', 'options' => in_array($symbols[0], ['BP', 'BS', 'BR'], true) ? ['TBT', 'BT'] : ['TBT', 'BT', 'HTA', 'HTB']],
                    ['id' => 'works', 'label' => 'Ouvrages ou installations concernés', 'type' => 'text', 'options' => []],
                    ['id' => 'details', 'label' => 'Indications supplémentaires', 'type' => 'text', 'options' => []],
                ]];
            }
            $sections[] = $section;
        }
        return ['sections' => $sections];
    }

    public function validate(array $schema): array
    {
        $sections = $schema['sections'] ?? null;
        if (!is_array($sections) || count($sections) < 1 || count($sections) > 20) throw new \InvalidArgumentException('Prévoyez entre 1 et 20 sections.');
        $clean = []; $ids = [];
        foreach ($sections as $section) {
            if (!is_array($section)) throw new \InvalidArgumentException('Section invalide.');
            $id = $this->id($section['id'] ?? '', $ids);
            $rows = $section['rows'] ?? [];
            if (!is_array($rows) || count($rows) < 1 || count($rows) > 25) throw new \InvalidArgumentException('Chaque section doit contenir entre 1 et 25 sous-sections.');
            $item = ['id' => $id, 'title' => $this->label($section['title'] ?? ''), 'rows' => []];
            foreach ($rows as $row) {
                if (!is_array($row)) throw new \InvalidArgumentException('Sous-section invalide.');
                $rowId = $this->id($row['id'] ?? '', $ids);
                $fields = $row['fields'] ?? [];
                if (!is_array($fields) || count($fields) < 1 || count($fields) > 6) throw new \InvalidArgumentException('Une sous-section comporte de 1 à 6 colonnes.');
                $fieldIds = []; $newFields = [];
                foreach ($fields as $field) {
                    if (!is_array($field)) throw new \InvalidArgumentException('Champ invalide.');
                    $fieldId = $this->id($field['id'] ?? '', $fieldIds);
                    $type = $field['type'] ?? '';
                    if (!in_array($type, ['checkbox', 'text'], true)) throw new \InvalidArgumentException('Type de champ inconnu.');
                    $options = [];
                    if ($type === 'checkbox') {
                        $values = $field['options'] ?? [];
                        if (!is_array($values) || count($values) < 1 || count($values) > 40) throw new \InvalidArgumentException('Prévoyez entre 1 et 40 choix par colonne.');
                        foreach ($values as $value) $options[] = $this->label($value);
                        $options = array_values(array_unique($options));
                    }
                    $newFields[] = ['id' => $fieldId, 'label' => $this->label($field['label'] ?? ''), 'type' => $type, 'options' => $options];
                }
                $item['rows'][] = ['id' => $rowId, 'title' => $this->label($row['title'] ?? ''), 'fields' => $newFields];
            }
            $clean[] = $item;
        }
        return ['sections' => $clean];
    }

    private function label(mixed $text): string
    {
        if (!is_string($text) || trim($text) === '' || mb_strlen($text) > 180) throw new \InvalidArgumentException('Les intitulés doivent comporter entre 1 et 180 caractères.');
        return trim($text);
    }

    private function id(mixed $id, array &$used): string
    {
        if (!is_string($id) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,60}$/D', $id) || isset($used[$id])) throw new \InvalidArgumentException('Identifiant de champ invalide ou dupliqué.');
        $used[$id] = true;
        return $id;
    }

    public function answers(array $schema, array $input, bool $final, ?array $trainer = null): array
    {
        $out = ['rows' => []];
        foreach (['function', 'assignment', 'observations', 'restrictions', 'additionalDocument', 'signerFunction', 'place'] as $key) {
            $value = $input[$key] ?? '';
            if (!is_string($value) || mb_strlen($value) > 4000) throw new \InvalidArgumentException('Texte trop long ou invalide.');
            $out[$key] = trim($value);
        }
        foreach (['issuedAt', 'validUntil'] as $key) {
            $value = $input[$key] ?? '';
            if (!is_string($value)) throw new \InvalidArgumentException('Date invalide.');
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if ($value !== '' && (!$date || $date->format('Y-m-d') !== $value)) throw new \InvalidArgumentException('Date invalide.');
            $out[$key] = $value;
        }
        if ($out['validUntil'] && (!$out['issuedAt'] || $out['validUntil'] < $out['issuedAt'])) throw new \InvalidArgumentException('La fin de validité doit être postérieure ou égale à la délivrance.');
        if ($final && (!$out['issuedAt'] || !$out['signerFunction'])) throw new \InvalidArgumentException('Renseignez la date de délivrance et votre fonction avant de signer.');
        if ($final && $trainer !== null && (!$out['validUntil'] || !$out['function'] || !$out['assignment'])) throw new \InvalidArgumentException('Renseignez la validité, la fonction du salarié et son affectation avant de délivrer le titre.');
        $selected = 0; $evaluated = 0;
        foreach ($schema['sections'] as $section) foreach ($section['rows'] as $row) {
            $data = $input['rows'][$row['id']] ?? [];
            if (!is_array($data)) throw new \InvalidArgumentException('Réponses invalides.');
            $verdict = $data['verdict'] ?? 'not_evaluated';
            if (!in_array($verdict, ['not_evaluated', 'favorable', 'unfavorable'], true)) throw new \InvalidArgumentException('Avis invalide.');
            if ($trainer !== null) $verdict = $trainer['rows'][$row['id']]['verdict'] ?? 'not_evaluated';
            if ($verdict !== 'not_evaluated') ++$evaluated;
            $new = ['verdict' => $verdict];
            foreach ($row['fields'] as $field) {
                $value = $data[$field['id']] ?? ($field['type'] === 'checkbox' ? [] : '');
                if ($field['type'] === 'checkbox') {
                    if (!is_array($value) || count($value) > 40) throw new \InvalidArgumentException('Choix invalides.');
                    foreach ($value as $choice) {
                        if (!is_string($choice) || !in_array($choice, $field['options'], true)) throw new \InvalidArgumentException('Un choix ne fait pas partie du modèle.');
                        if ($trainer !== null && ($verdict !== 'favorable' || !in_array($choice, $trainer['rows'][$row['id']][$field['id']] ?? [], true))) throw new \InvalidArgumentException('Le titre doit rester dans les limites de l’avis favorable du formateur.');
                    }
                    $value = array_values(array_unique($value));
                    $selected += count($value);
                } elseif (!is_string($value) || mb_strlen($value) > 600) throw new \InvalidArgumentException('Les champs de la grille sont limités à 600 caractères. Utilisez les observations pour les précisions longues.');
                $new[$field['id']] = $value;
            }
            $checkboxes = array_filter($row['fields'], static fn ($f) => $f['type'] === 'checkbox');
            if ($final && $trainer === null && $verdict !== 'not_evaluated') foreach ($checkboxes as $field) {
                if (!$new[$field['id']]) throw new \InvalidArgumentException('Précisez les choix concernés pour chaque sous-section évaluée.');
            }
            if ($final && $trainer !== null && array_filter($checkboxes, static fn ($field) => !empty($new[$field['id']]))) {
                foreach ($checkboxes as $field) if (!$new[$field['id']]) throw new \InvalidArgumentException('Complétez les choix de chaque colonne pour les habilitations délivrées.');
            }
            $out['rows'][$row['id']] = $new;
        }
        if ($final && $trainer === null && !$evaluated) throw new \InvalidArgumentException('Évaluez au moins une sous-section.');
        if ($final && $trainer !== null && !$selected) throw new \InvalidArgumentException('Sélectionnez au moins une habilitation avant de délivrer le titre.');
        return $out;
    }
}
