<?php
declare(strict_types=1);
namespace App\Service\Company;

/** Structural checks only: these do not certify registration or active VAT status. */
final class CompanyIdentifiers
{
    public static function normalize(?string $value): string
    {
        return mb_strtoupper(preg_replace('/[\s\p{Z}]+/u', '', $value ?? '') ?? '');
    }

    private static function luhn(string $value): bool
    {
        $sum = 0;
        foreach (str_split(strrev($value)) as $i => $digit) {
            $n = (int) $digit * ($i % 2 ? 2 : 1);
            $sum += $n > 9 ? $n - 9 : $n;
        }
        return $sum % 10 === 0;
    }

    public static function errors(?string $siret, ?string $vat): array
    {
        $siret = self::normalize($siret); $vat = self::normalize($vat); $errors = [];
        if ($siret !== '') {
            if (!preg_match('/^[0-9]{14}$/D', $siret)) {
                $errors['siret'] = 'Le SIRET doit contenir 14 chiffres. Saisissez le numéro de l’établissement, et non son SIREN (9 chiffres).';
            } elseif ($siret === str_repeat('0', 14) || !self::luhn(substr($siret, 0, 9)) || !(self::luhn($siret) || (str_starts_with($siret, '356000000') && array_sum(str_split($siret)) % 5 === 0))) {
                $errors['siret'] = 'Ce SIRET ne passe pas le contrôle de validité. Vérifiez les 14 chiffres sur un document officiel.';
            }
        }
        if ($vat !== '') {
            if (preg_match('/^[0-9]{14}$/D', $vat)) {
                $errors['numeroTVA'] = 'Vous avez saisi un SIRET dans le champ TVA. Renseignez le numéro de TVA avec son préfixe pays (FR pour la France), ou laissez ce champ vide si non applicable.';
            } elseif (!preg_match('/^[A-Z]{2}[A-Z0-9]{2,12}$/D', $vat) || !in_array(substr($vat, 0, 2), ['AT','BE','BG','CY','CZ','DE','DK','EE','EL','ES','FI','FR','HR','HU','IE','IT','LT','LU','LV','MT','NL','PL','PT','RO','SE','SI','SK','XI'], true)) {
                $errors['numeroTVA'] = 'Le numéro de TVA intracommunautaire doit commencer par un préfixe pays reconnu, suivi de son identifiant fiscal.';
            } elseif (str_starts_with($vat, 'FR')) {
                if (!preg_match('/^FR[A-HJ-NP-Z0-9]{2}[0-9]{9}$/D', $vat) || substr($vat, 4) === '000000000' || !self::luhn(substr($vat, 4))) {
                    $errors['numeroTVA'] = 'Format français invalide : FR, une clé de 2 caractères, puis le SIREN de 9 chiffres.';
                } elseif (ctype_digit(substr($vat, 2, 2)) && (int) substr($vat, 2, 2) !== (12 + 3 * ((int) substr($vat, 4) % 97)) % 97) {
                    $errors['numeroTVA'] = 'La clé de contrôle du numéro de TVA français est incorrecte. Vérifiez le numéro attribué par l’administration fiscale.';
                } elseif ($siret !== '' && !isset($errors['siret']) && substr($vat, 4) !== substr($siret, 0, 9)) {
                    $errors['numeroTVA'] = 'Le numéro de TVA et le SIRET désignent deux entreprises différentes. Vérifiez leur SIREN (9 chiffres communs).';
                }
            }
        }
        return $errors;
    }
}
