<?php

declare(strict_types=1);

namespace App\Service\Evaluation;

/** Validated, self-contained pedagogical schema. No changes to the existing QCM schema. */
final class EvaluationSchema
{
    public function validate(array $schema): array
    {
        $this->keys($schema, ['theory', 'practice', 'theoryThreshold', 'practiceThreshold'], 'modèle');
        $theory = $this->items($schema['theory'] ?? null, 'théorie');
        $practice = $this->items($schema['practice'] ?? null, 'pratique');
        $result = [
            'theory' => [], 'practice' => [],
            'theoryThreshold' => $this->threshold($schema['theoryThreshold'] ?? null),
            'practiceThreshold' => $this->threshold($schema['practiceThreshold'] ?? null),
        ];
        $seen = [];
        foreach ($theory as $question) {
            if (!is_array($question)) throw new \InvalidArgumentException('Question théorique invalide.');
            $this->keys($question, ['id', 'label', 'type', 'points', 'options', 'correct'], 'question');
            $id = $this->id($question['id'] ?? null);
            if (isset($seen[$id])) throw new \InvalidArgumentException('Chaque question doit avoir un identifiant unique.');
            $seen[$id] = true;
            $type = $question['type'] ?? null;
            if (!in_array($type, ['single', 'multiple', 'order', 'text'], true)) throw new \InvalidArgumentException('Type de question invalide.');
            $q = ['id' => $id, 'label' => $this->text($question['label'] ?? null, 5000), 'type' => $type, 'points' => $this->points($question['points'] ?? null, 1000, false), 'options' => [], 'correct' => []];
            $options = $question['options'] ?? [];
            $correct = $question['correct'] ?? [];
            if (!is_array($options) || !array_is_list($options) || !is_array($correct) || !array_is_list($correct)) throw new \InvalidArgumentException('Options ou corrigé invalides.');
            if ($type === 'text') {
                if ($options !== [] || $correct !== []) throw new \InvalidArgumentException('Une réponse libre est corrigée manuellement, sans liste de choix.');
            } else {
                if (count($options) < 2 || count($options) > 100) throw new \InvalidArgumentException('Prévoyez de 2 à 100 choix par question.');
                $optionIds = [];
                foreach ($options as $option) {
                    if (!is_array($option)) throw new \InvalidArgumentException('Choix invalide.');
                    $this->keys($option, ['id', 'label'], 'choix');
                    $oid = $this->id($option['id'] ?? null);
                    if (isset($optionIds[$oid])) throw new \InvalidArgumentException('Les identifiants des choix doivent être uniques.');
                    $optionIds[$oid] = true;
                    $q['options'][] = ['id' => $oid, 'label' => $this->text($option['label'] ?? null, 3000)];
                }
                foreach ($correct as $value) {
                    $cid = $this->id($value);
                    if (!isset($optionIds[$cid]) || in_array($cid, $q['correct'], true)) throw new \InvalidArgumentException('Le corrigé contient un choix inconnu ou répété.');
                    $q['correct'][] = $cid;
                }
                if ($correct === [] || ($type === 'single' && count($correct) !== 1) || ($type === 'order' && count($correct) !== count($options))) throw new \InvalidArgumentException('Renseignez un corrigé complet adapté au type de question.');
            }
            $result['theory'][] = $q;
        }
        $seen = [];
        foreach ($practice as $criterion) {
            if (!is_array($criterion)) throw new \InvalidArgumentException('Critère pratique invalide.');
            $this->keys($criterion, ['id', 'label', 'points', 'critical', 'allowNa'], 'critère');
            $id = $this->id($criterion['id'] ?? null);
            if (isset($seen[$id])) throw new \InvalidArgumentException('Chaque critère doit avoir un identifiant unique.');
            $seen[$id] = true;
            $result['practice'][] = [
                'id' => $id, 'label' => $this->text($criterion['label'] ?? null, 5000),
                'points' => $this->points($criterion['points'] ?? null, 1000, false),
                'critical' => $this->boolean($criterion['critical'] ?? false),
                'allowNa' => $this->boolean($criterion['allowNa'] ?? false),
            ];
        }
        return $result;
    }

    public function points(mixed $value, float $max, bool $zero = true): float
    {
        if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value)) throw new \InvalidArgumentException('La note doit être un nombre.');
        $number = (float) $value;
        if (!is_finite($number) || $number < 0 || (!$zero && $number <= 0) || $number > $max || abs($number - round($number, 2)) > 0.0000001) throw new \InvalidArgumentException('Note hors barème ou précision supérieure à deux décimales.');
        return round($number, 2);
    }

    public function text(mixed $value, int $max, bool $empty = false): string
    {
        if (!is_string($value)) throw new \InvalidArgumentException('Un texte est attendu.');
        $value = trim($value);
        if ((!$empty && $value === '') || mb_strlen($value) > $max) throw new \InvalidArgumentException('Texte vide ou trop long.');
        return $value;
    }

    public function boolean(mixed $value): bool
    {
        if (is_bool($value)) return $value;
        if ($value === '1' || $value === 1) return true;
        if ($value === '0' || $value === 0) return false;
        throw new \InvalidArgumentException('Valeur de case à cocher invalide.');
    }

    public function keys(array $value, array $allowed, string $label): void
    {
        if (array_diff(array_keys($value), $allowed) !== []) throw new \InvalidArgumentException('Champ inconnu dans le '.$label.'.');
    }

    private function id(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/D', $value)) throw new \InvalidArgumentException('Identifiant de question ou de choix invalide.');
        return $value;
    }

    private function items(mixed $value, string $part): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) < 1 || count($value) > 200) throw new \InvalidArgumentException('Le volet '.$part.' doit contenir de 1 à 200 éléments.');
        return $value;
    }

    private function threshold(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : $this->points($value, 20);
    }
}
