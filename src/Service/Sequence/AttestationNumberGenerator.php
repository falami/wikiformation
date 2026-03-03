<?php

namespace App\Service\Sequence;

class AttestationNumberGenerator
{
    public function __construct(private SequenceNumberManager $sequenceManager) {}

    /**
     * Retourne un numéro du type ATT-E{entite}-{year}-{seq}
     * ex: ATT-E3-2025-0001
     */
    public function nextForEntite(int $entiteId, ?int $year = null): string
    {
        [$y, $seq] = $this->sequenceManager->next('attestation', $entiteId, $year);

        return sprintf('ATT-E%d-%d-%04d', $entiteId, $y, $seq);
    }
}
