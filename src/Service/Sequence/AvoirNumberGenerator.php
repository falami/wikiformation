<?php

namespace App\Service\Sequence;

class AvoirNumberGenerator
{
    public function __construct(private SequenceNumberManager $sequenceManager) {}

    /**
     * Retourne un numéro du type AV-E{entite}-{year}-{seq}
     * ex: AV-E3-2025-0001
     */
    public function nextForEntite(int $entiteId, ?int $year = null): string
    {
        [$y, $seq] = $this->sequenceManager->next('avoir', $entiteId, $year);

        return sprintf('AV-E%d-%d-%04d', $entiteId, $y, $seq);
    }
}
