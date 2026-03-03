<?php
// src/Form/DataTransformer/FrenchToDateTimeTransformer.php

namespace App\Form\DataTransformer;

use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;

final class FrenchToDateTimeTransformer implements DataTransformerInterface
{
    private const FORMAT = 'd/m/Y H:i'; // JJ/MM/AAAA HH:mm

    public function transform(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (!$value instanceof \DateTimeInterface) {
            throw new \LogicException(sprintf(
                'Expected DateTimeInterface, got "%s".',
                get_debug_type($value)
            ));
        }

        return $value->format(self::FORMAT);
    }

    public function reverseTransform(mixed $value): ?\DateTimeImmutable
    {
        $value = is_string($value) ? trim($value) : $value;

        if ($value === null || $value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat(self::FORMAT, (string) $value);
        $errors = \DateTimeImmutable::getLastErrors();

        if (
            $date === false ||
            ($errors['warning_count'] ?? 0) > 0 ||
            ($errors['error_count'] ?? 0) > 0
        ) {
            throw new TransformationFailedException(
                sprintf("Format attendu : JJ/MM/AAAA HH:mm (valeur reçue : '%s').", (string) $value)
            );
        }

        return $date;
    }
}
