<?php
namespace App\Form\DataTransformer;

use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;

class FrenchToDateTransformer implements DataTransformerInterface
{
    private const FORMAT = 'd/m/Y'; // jj/mm/aaaa

    /**
     * Du modèle (DateTimeImmutable|DateTime|null) -> string pour le champ texte
     */
    public function transform($value): string
    {
        if ($value === null) {
            return '';
        }
        if (!$value instanceof \DateTimeInterface) {
            throw new \LogicException(sprintf('Expected DateTimeInterface, got %s', get_debug_type($value)));
        }

        return $value->format(self::FORMAT);
    }

    /**
     * De la string saisie -> DateTimeImmutable pour l’entité
     */
    public function reverseTransform($value): ?\DateTimeImmutable
    {
        $value = is_string($value) ? trim($value) : $value;

        if ($value === null || $value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat(self::FORMAT, $value);
        $errors = \DateTimeImmutable::getLastErrors();

        if ($date === false || ($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0) {
            throw new TransformationFailedException("Le format de la date doit être JJ/MM/AAAA.");
        }

        // On normalise l'heure à minuit (utile si jamais un time picker arrive plus tard)
        return $date->setTime(0, 0, 0);
    }
}
