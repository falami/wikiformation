<?php

namespace App\Form\DataTransformer;

use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;

/**
 * Transforme un int (centimes) <-> string euros "1 299,90"
 * - Normalise '.' et ',' comme séparateur décimal
 * - Tolère espaces fines, espaces insécables, etc.
 */
class EurosToCentsTransformer implements DataTransformerInterface
{
    public function transform($value): string
    {
        if ($value === null) {
            return '';
        }
        if (!is_int($value)) {
            throw new TransformationFailedException('Le montant centimes attendu doit être un int.');
        }
        // 123456 -> "1 234,56"
        $euros = $value / 100;
        // format FR (virgule)
        return number_format($euros, 2, ',', ' ');
    }

    public function reverseTransform($value): ?int
    {
        if ($value === null) {
            return null;
        }
        $str = trim((string)$value);

        if ($str === '') {
            return null; // champ facultatif
        }

        // Nettoyage : supprime espaces (classiques, insécables) et remplace virgule -> point
        $str = preg_replace('/[\x{00A0}\s]/u', '', $str); // supprime tous les espaces (y compris insécables)
        $str = str_replace(',', '.', $str);

        if (!is_numeric($str)) {
            throw new TransformationFailedException('Montant invalide. Exemple attendu : 1299,90');
        }

        // On limite à 2 décimales (bancaire)
        $float = round((float)$str, 2);
        $cents = (int) round($float * 100);

        if ($cents < 0) {
            throw new TransformationFailedException('Le montant doit être positif.');
        }
        return $cents;
    }
}
