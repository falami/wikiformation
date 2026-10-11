<?php
declare(strict_types=1);
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'einvoice_transmission')]
#[ORM\UniqueConstraint(name: 'uniq_einvoice_connection_facture', columns: ['connection_id', 'facture_id'])]
#[ORM\UniqueConstraint(name: 'uniq_einvoice_external', columns: ['external_id'])]
class EInvoiceTransmission
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private EInvoiceConnection $connection;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private Facture $facture;
    #[ORM\Column(length: 36)] private string $externalId;
    #[ORM\Column(length: 24)] private string $status = 'queued';
    #[ORM\Column(length: 40, nullable: true)] private ?string $providerId = null;
    #[ORM\Column(type: 'json')] private array $issues = [];
    #[ORM\Column(type: 'json')] private array $events = [];
    #[ORM\Column(type: 'json', nullable: true)] private ?array $usageTerms = null;
    #[ORM\Column(type: 'text', nullable: true)] private ?string $payload = null;
    #[ORM\Column(length: 64, nullable: true)] private ?string $sha256 = null;
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $attemptedAt = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $acceptedAt = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $synchronizedAt = null;
    #[ORM\Version, ORM\Column(type: 'integer')] private int $version = 1;

    public function __construct(EInvoiceConnection $connection, Facture $facture)
    {
        if (!$facture->getId() || $connection->getEntite()->getId() !== $facture->getEntite()?->getId()) throw new \DomainException('Facture étrangère à cet organisme.');
        $this->connection = $connection; $this->facture = $facture; $this->createdAt = new \DateTimeImmutable();
        $this->externalId = 'wf-' . bin2hex(random_bytes(16));
    }
    public function getId(): ?int { return $this->id; }
    public function getConnection(): EInvoiceConnection { return $this->connection; }
    public function getFacture(): Facture { return $this->facture; }
    public function getExternalId(): string { return $this->externalId; }
    public function getStatus(): string { return $this->status; }
    public function getProviderId(): ?string { return $this->providerId; }
    public function getIssues(): array { return $this->issues; }
    public function getEvents(): array { return $this->events; }
    public function getUsageTerms(): ?array { return $this->usageTerms; }
    public function captureUsageTerms(array $terms): void
    {
        if ($this->attemptedAt) throw new \LogicException('Conditions tarifaires déjà figées.');
        $this->usageTerms = $terms;
    }
    public function getPayload(): ?string { return $this->payload; }
    public function getSha256(): ?string { return $this->sha256; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getAttemptedAt(): ?\DateTimeImmutable { return $this->attemptedAt; }
    public function getAcceptedAt(): ?\DateTimeImmutable { return $this->acceptedAt; }
    public function getSynchronizedAt(): ?\DateTimeImmutable { return $this->synchronizedAt; }
    public function block(array $issues): void { if ($this->attemptedAt) throw new \LogicException('Transmission déjà tentée.'); $this->status = 'blocked'; $this->issues = array_values($issues); $this->synchronizedAt = new \DateTimeImmutable(); }
    public function prepared(string $xml): void
    {
        if ($this->attemptedAt) throw new \LogicException('Transmission déjà tentée.');
        $this->payload = $xml; $this->sha256 = hash('sha256', $xml); $this->status = 'queued'; $this->issues = [];
    }
    public function begin(): void
    {
        if ($this->attemptedAt || !$this->payload || !hash_equals((string) $this->sha256, hash('sha256', $this->payload))) throw new \LogicException('Transmission absente, déjà tentée ou altérée.');
        $this->status = 'submitting'; $this->attemptedAt = new \DateTimeImmutable();
    }
    public function accepted(string $id): void
    {
        if (!$this->attemptedAt || !preg_match('/^[1-9]\d{0,18}$/D', $id)
            || ($this->providerId !== null && $this->providerId !== $id)) throw new \LogicException('Confirmation de transmission incohérente.');
        $this->providerId = $id; $this->status = 'submitted'; $this->issues = [];
        $this->acceptedAt ??= new \DateTimeImmutable();
    }
    public function uncertain(): void { $this->status = 'uncertain'; $this->issues = ['Résultat de transmission inconnu. Aucun nouvel envoi automatique ; rapprochement avec la plateforme nécessaire.']; }
    public function rejected(string $safeMessage): void { $this->status = 'rejected'; $this->issues = [$safeMessage]; }
    public function recordEvents(array $events): void
    {
        // Provider events are evidence, not an exclusive state machine or an accounting payment.
        $known = [];
        foreach (array_merge($this->events, $events) as $event) {
            if (!isset($event['id'], $event['invoice_id'], $event['status_code']) || (string) $event['invoice_id'] !== $this->providerId) continue;
            $known[(string) $event['id']] = ['id' => (string) $event['id'], 'invoice_id' => (string) $event['invoice_id'], 'status_code' => (string) $event['status_code'], 'created_at' => (string) ($event['created_at'] ?? '')];
        }
        $this->events = array_values($known); $this->synchronizedAt = new \DateTimeImmutable();
    }
}
