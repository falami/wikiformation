<?php
declare(strict_types=1);
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'einvoice_received')]
#[ORM\UniqueConstraint(name: 'uniq_einvoice_received_provider', columns: ['connection_id', 'provider_id'])]
class EInvoiceReceived
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private EInvoiceConnection $connection;
    #[ORM\Column(length: 40)] private string $providerId;
    #[ORM\Column(type: 'json')] private array $summary;
    #[ORM\Column] private \DateTimeImmutable $receivedAt;
    public function __construct(EInvoiceConnection $connection, string $id, array $summary)
    { $this->connection = $connection; $this->providerId = $id; $this->summary = $summary; $this->receivedAt = new \DateTimeImmutable(); }
    public function getId(): ?int { return $this->id; }
    public function getConnection(): EInvoiceConnection { return $this->connection; }
    public function getProviderId(): string { return $this->providerId; }
    public function getSummary(): array { return $this->summary; }
    public function getReceivedAt(): \DateTimeImmutable { return $this->receivedAt; }
}
