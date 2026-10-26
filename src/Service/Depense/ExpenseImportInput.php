<?php

declare(strict_types=1);
namespace App\Service\Depense;

use Symfony\Component\Intl\Currencies;

/** Validates edited preview data before any expense or file is written. */
final class ExpenseImportInput
{
    public static function date(string $value): ?\DateTimeImmutable
    {
        try { $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value); }
        catch (\ValueError) { return null; }
        return $date && $date->format('Y-m-d') === $value ? $date : null;
    }

    public function validate(array $row): array
    {
        foreach ($row as $value) {
            if (!is_scalar($value) && $value !== null) throw new \InvalidArgumentException('Valeur de ligne invalide.');
        }
        $date = self::date((string)($row['date'] ?? ''));
        if (!$date) throw new \InvalidArgumentException('Date invalide.');
        $label = trim((string)($row['libelle'] ?? ''));
        if ($label === '' || mb_strlen($label) > 2000) throw new \InvalidArgumentException('Le libellé doit contenir entre 1 et 2 000 caractères.');
        $currency = strtoupper(trim((string)($row['devise'] ?? 'EUR')));
        if (!Currencies::exists($currency)) throw new \InvalidArgumentException('Devise invalide.');
        foreach (['montantHtCents', 'montantTvaCents', 'montantTtcCents'] as $field) {
            $value = (string)($row[$field] ?? '');
            if (!preg_match('/^\d{1,10}$/D', $value) || (int)$value > 2000000000) throw new \InvalidArgumentException('Montant invalide ou trop élevé.');
            $row[$field] = (int)$value;
        }
        if ($row['montantTtcCents'] <= 0) throw new \InvalidArgumentException('Le montant TTC doit être supérieur à zéro.');
        if ($row['montantHtCents'] + $row['montantTvaCents'] !== $row['montantTtcCents']) {
            throw new \InvalidArgumentException('Les montants doivent vérifier HT + TVA = TTC.');
        }
        foreach (['tauxTva', 'tvaDeductiblePct'] as $field) {
            $value = str_replace(',', '.', trim((string)($row[$field] ?? '0')));
            if (!preg_match('/^\d{1,3}(?:\.\d{1,4})?$/D', $value) || (float)$value > 100) throw new \InvalidArgumentException('Le taux et le pourcentage doivent être compris entre 0 et 100.');
            $row[$field] = (float)$value;
        }
        foreach (['accept', 'tvaDeductible', 'allowDuplicate'] as $field) {
            $value = (string)($row[$field] ?? '0');
            if (!in_array($value, ['0','1'], true)) throw new \InvalidArgumentException('Choix de ligne invalide.');
            $row[$field] = $value === '1';
        }
        foreach (['categorieId','fournisseurId','payeurId'] as $field) {
            $value = (string)($row[$field] ?? '');
            if ($value !== '' && !ctype_digit($value)) throw new \InvalidArgumentException('Sélection invalide.');
            $row[$field] = $value === '' ? null : (int)$value;
        }
        $row['libelle'] = $label;
        $row['devise'] = $currency;
        $row['dateObject'] = $date;
        return $row;
    }
}
