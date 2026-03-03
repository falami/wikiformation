<?php

namespace App\Service\Sequence;

final class SessionNumberGenerator
{
  public function __construct(private SequenceNumberManager $sequenceManager) {}

  /**
   * ex: SES-E3-2026-0001
   */
  public function nextForEntite(int $entiteId, ?int $year = null): string
  {
    [$y, $seq] = $this->sequenceManager->next('session', $entiteId, $year);

    return sprintf('SES-E%d-%d-%04d', $entiteId, $y, $seq);
  }
}
