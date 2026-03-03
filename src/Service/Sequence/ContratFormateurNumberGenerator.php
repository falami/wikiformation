<?php
// src/Service/Sequence/ContratFormateurNumberGenerator.php

namespace App\Service\Sequence;

final class ContratFormateurNumberGenerator
{
  public function __construct(private SequenceNumberManager $sequenceManager) {}

  /**
   * Ex: CF-E3-2026-0001
   */
  public function nextForEntite(int $entiteId, ?int $year = null): string
  {
    [$y, $seq] = $this->sequenceManager->next('contrat_formateur', $entiteId, $year);

    return sprintf('CF-E%d-%d-%04d', $entiteId, $y, $seq);
  }
}
