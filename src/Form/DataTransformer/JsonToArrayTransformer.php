<?php
// src/Form/DataTransformer/JsonToArrayTransformer.php

namespace App\Form\DataTransformer;

use Symfony\Component\Form\DataTransformerInterface;

final class JsonToArrayTransformer implements DataTransformerInterface
{
  public function transform($value): string
  {
    if ($value === null) return '';
    if (!is_array($value)) return '';
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }

  public function reverseTransform($value): ?array
  {
    $s = trim((string)$value);
    if ($s === '') return [];

    $decoded = json_decode($s, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
      // on laisse Symfony remonter une erreur via exception
      throw new \UnexpectedValueException('JSON invalide pour addons.');
    }

    return $decoded;
  }
}
