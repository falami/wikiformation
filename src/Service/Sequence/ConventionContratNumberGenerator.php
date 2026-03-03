<?php

// src/Service/ConventionContratNumberGenerator.php
namespace App\Service\Sequence;

final class ConventionContratNumberGenerator
{
  public function __construct(private SequenceNumberManager $sequenceManager) {}

  /**
   * Retourne un numéro du type CC-E{entite}-{year}-{seq}
   * ex: CC-E3-2026-0001
   */
  public function nextForEntite(int $entiteId, ?int $year = null): string
  {
    [$y, $seq] = $this->sequenceManager->next('convention_contrat', $entiteId, $year);

    return sprintf('CONV-E%d-%d-%04d', $entiteId, $y, $seq);
  }
}
