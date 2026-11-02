<?php

namespace App\Service\Sequence;

use Doctrine\DBAL\Connection;

class SequenceNumberManager
{
    public function __construct(private Connection $conn) {}

    /**
     * Retourne [year, sequence] pour un type donné (avoir/facture/attestation)
     *
     * @return array{0:int,1:int} [year, seq]
     */
    public function next(string $type, int $entiteId, ?int $year = null): array
    {
        $y = $year ?? (int) (new \DateTimeImmutable())->format('Y');

        $table = match ($type) {
            'avoir'               => 'avoir_sequence',
            'facture'             => 'facture_sequence',
            'devis'               => 'devis_sequence',
            'attestation'         => 'attestation_sequence',
            'contrat_formateur'   => 'contrat_formateur_sequence',
            'convention_contrat'  => 'convention_contrat_sequence',
            'session'             => 'session_sequence',
            default => throw new \InvalidArgumentException(sprintf('Type de séquence inconnu "%s"', $type)),
        };

        if (!$this->conn->isTransactionActive()) {
            throw new \LogicException('La numérotation exige la transaction d’enregistrement du document.');
        }
        // The row lock and increment belong to the caller's document transaction.
        // A failed insert rolls back the counter too, including its first allocation.
        $sql = $this->conn->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform
            ? "INSERT INTO {$table} (entite_id, year, last) VALUES (:e, :y, 1) ON DUPLICATE KEY UPDATE last = last + 1"
            : "INSERT INTO {$table} (entite_id, year, last) VALUES (:e, :y, 1) ON CONFLICT (entite_id, year) DO UPDATE SET last = {$table}.last + 1";
        $this->conn->executeStatement($sql, ['e' => $entiteId, 'y' => $y]);
        $next = (int) $this->conn->fetchOne(
            "SELECT last FROM {$table} WHERE entite_id = :e AND year = :y",
            ['e' => $entiteId, 'y' => $y]
        );
        return [$y, $next];
    }
}
