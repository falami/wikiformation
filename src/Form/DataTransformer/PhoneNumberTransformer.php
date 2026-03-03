<?php
// src/Form/DataTransformer/PhoneNumberTransformer.php

namespace App\Form\DataTransformer;

use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;

final class PhoneNumberTransformer implements DataTransformerInterface
{
    public function transform(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        // On affiche tel quel (si tu stockes déjà +33...)
        return (string) $value;
    }

    public function reverseTransform(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        if ($value === null || $value === '') {
            return null;
        }

        // On garde uniquement chiffres + éventuel +
        $raw = preg_replace('/[^\d+]/', '', (string) $value);

        // 0033XXXXXXXXX -> +33XXXXXXXXX
        if (str_starts_with($raw, '00')) {
            $raw = '+' . substr($raw, 2);
        }

        // 06XXXXXXXX / 07XXXXXXXX -> +336XXXXXXXX / +337XXXXXXXX
        if (preg_match('/^0[1-9]\d{8}$/', $raw)) {
            $raw = '+33' . substr($raw, 1);
        }

        // 6XXXXXXXXX (sans 0) -> +336XXXXXXXXX (au cas où)
        if (preg_match('/^[1-9]\d{8}$/', $raw)) {
            $raw = '+33' . $raw;
        }

        // Validation E.164 (simple)
        if (!preg_match('/^\+\d{8,15}$/', $raw)) {
            throw new TransformationFailedException(
                "Numéro invalide. Format attendu : +33606060606 (ou saisie FR type 06 06 06 06 06)."
            );
        }

        return $raw;
    }
}
