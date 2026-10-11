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

        if ($type === 'facture') {
            if (!$this->conn->isTransactionActive()) {
                throw new \LogicException('La numérotation d’une facture exige la transaction de son émission.');
            }
            // The upsert acquires the row lock even for the first invoice of an exercise.
            // Its increment is committed or rolled back together with the invoice.
            $sql = $this->conn->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform
                ? 'INSERT INTO facture_sequence (entite_id, year, last) VALUES (:e, :y, 1) ON DUPLICATE KEY UPDATE last = last + 1'
                : 'INSERT INTO facture_sequence (entite_id, year, last) VALUES (:e, :y, 1) ON CONFLICT (entite_id, year) DO UPDATE SET last = facture_sequence.last + 1';
            $this->conn->executeStatement($sql, ['e' => $entiteId, 'y' => $y]);
            $next = (int) $this->conn->fetchOne('SELECT last FROM facture_sequence WHERE entite_id = :e AND year = :y', ['e' => $entiteId, 'y' => $y]);
            return [$y, $next];
        }

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

        $this->conn->beginTransaction();
        try {
            // 1) Lock la ligne (si elle existe)
            $row = $this->conn->fetchAssociative(
                "SELECT id, last FROM {$table} WHERE entite_id = :e AND year = :y FOR UPDATE",
                ['e' => $entiteId, 'y' => $y]
            );

            if (!$row) {
                // 2) Crée la ligne, last = 0 (on va incrémenter juste après)
                $this->conn->executeStatement(
                    "INSERT INTO {$table} (entite_id, year, last) VALUES (:e, :y, 0)",
                    ['e' => $entiteId, 'y' => $y]
                );
                $last = 0;
            } else {
                $last = (int) $row['last'];
            }

            // 3) Incrémente
            $next = $last + 1;

            $this->conn->executeStatement(
                "UPDATE {$table} SET last = :next WHERE entite_id = :e AND year = :y",
                ['next' => $next, 'e' => $entiteId, 'y' => $y]
            );

            $this->conn->commit();
            return [$y, $next];
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            throw $e;
        }
    }
}
