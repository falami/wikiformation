<?php
declare(strict_types=1);
namespace App\Service\EInvoicing;

use Doctrine\DBAL\Connection;

/** Database-backed lease shared across hosts. Every external request has a shorter timeout. */
final class ConnectionLease
{
    public function __construct(private Connection $db) {}
    public function acquire(int $id): ?string
    {
        $token = bin2hex(random_bytes(24));
        $changed = $this->db->executeStatement('UPDATE einvoice_connection SET worker_token = ?, worker_until = ? WHERE id = ? AND (worker_until IS NULL OR worker_until < ?)', [$token, $this->date('+15 minutes'), $id, $this->date()]);
        return $changed === 1 ? $token : null;
    }
    public function renew(int $id, string $token): void
    {
        $now = $this->date();
        $changed = $this->db->executeStatement('UPDATE einvoice_connection SET worker_until = ? WHERE id = ? AND worker_token = ? AND worker_until >= ?', [$this->date('+15 minutes'), $id, $token, $now]);
        // MySQL reports zero changed rows when two renewals occur in the same second.
        if ($changed !== 1 && !$this->db->fetchOne('SELECT id FROM einvoice_connection WHERE id = ? AND worker_token = ? AND worker_until >= ?', [$id, $token, $now])) {
            throw new \RuntimeException('Traitement repris par un autre processus.');
        }
    }
    public function release(int $id, string $token): void
    { $this->db->executeStatement('UPDATE einvoice_connection SET worker_token = NULL, worker_until = NULL WHERE id = ? AND worker_token = ?', [$id, $token]); }
    private function date(string $modifier = 'now'): string { return (new \DateTimeImmutable($modifier, new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'); }
}
