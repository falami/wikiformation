<?php

namespace App\Service\Sequence;

final class DevisNumberGenerator
{
  public function __construct(private SequenceNumberManager $sequenceManager) {}

  /**
   * Retourne un numéro du type DEV-E{entite}-{year}-{seq}
   * ex: DEV-E3-2025-0001
   */
  public function nextForEntite(int $entiteId, ?int $year = null): string
  {
    [$y, $seq] = $this->sequenceManager->next('devis', $entiteId, $year);

    return sprintf('DEV-E%d-%d-%04d', $entiteId, $y, $seq);
  }
}
