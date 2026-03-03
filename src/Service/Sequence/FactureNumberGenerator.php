<?php

namespace App\Service\Sequence;

class FactureNumberGenerator
{
    public function __construct(private SequenceNumberManager $sequenceManager) {}

    /**
     * Retourne un numéro du type FAC-E{entite}-{year}-{seq}
     * ex: FAC-E3-2025-0001
     */
    public function nextForEntite(int $entiteId, ?int $year = null): string
    {
        [$y, $seq] = $this->sequenceManager->next('facture', $entiteId, $year);

        return sprintf('FAC-E%d-%d-%04d', $entiteId, $y, $seq);
    }
}
