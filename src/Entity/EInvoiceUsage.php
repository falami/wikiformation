<?php
declare(strict_types=1);
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Immutable charge evidence. A provider acknowledgement is not delivery or tax validation. */
#[ORM\Entity]
#[ORM\Table(name: 'einvoice_usage')]
#[ORM\Index(name: 'idx_einvoice_usage_connection', columns: ['connection_id'])]
#[ORM\Index(name: 'idx_einvoice_usage_batch', columns: ['batch_id'])]
#[ORM\UniqueConstraint(name: 'uniq_einvoice_usage_transmission', columns: ['transmission_id'])]
#[ORM\UniqueConstraint(name: 'uniq_einvoice_usage_provider', columns: ['connection_id', 'provider_id'])]
class EInvoiceUsage
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private EInvoiceConnection $connection;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private EInvoiceTransmission $transmission;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')] private ?EInvoiceUsageBatch $batch = null;
    #[ORM\Column(length: 40)] private string $providerId;
    #[ORM\Column] private int $unitPriceCents;
    #[ORM\Column(length: 255)] private string $stripeCustomerId;
    #[ORM\Column(length: 255)] private string $stripeSubscriptionId;
    #[ORM\Column] private \DateTimeImmutable $consentedAt;
    #[ORM\Column] private \DateTimeImmutable $acceptedAt;
    #[ORM\Column(length: 7)] private string $period;

    public function __construct(EInvoiceTransmission $transmission)
    {
        $terms = $transmission->getUsageTerms();
        if ($transmission->getConnection()->getEnvironment() !== 'production' || !$transmission->getProviderId() || !$transmission->getAcceptedAt() || !$transmission->getAttemptedAt()
            || !$terms || !in_array($terms['unitPriceCents'] ?? null, [1, 2, 3], true) || empty($terms['customer']) || empty($terms['subscription']) || empty($terms['consentedAt'])) {
            throw new \DomainException('Émission confirmée et conditions tarifaires acceptées requises.');
        }
        $this->connection = $transmission->getConnection(); $this->transmission = $transmission;
        $this->providerId = $transmission->getProviderId(); $this->unitPriceCents = $terms['unitPriceCents'];
        $this->stripeCustomerId = $terms['customer']; $this->stripeSubscriptionId = $terms['subscription'];
        $this->consentedAt = new \DateTimeImmutable($terms['consentedAt']); $this->acceptedAt = $transmission->getAcceptedAt();
        $this->period = $this->acceptedAt->setTimezone(new \DateTimeZone('Europe/Paris'))->format('Y-m');
    }
    public function getId(): ?int { return $this->id; }
    public function getConnection(): EInvoiceConnection { return $this->connection; }
    public function getTransmission(): EInvoiceTransmission { return $this->transmission; }
    public function getProviderId(): string { return $this->providerId; }
    public function getUnitPriceCents(): int { return $this->unitPriceCents; }
    /** Null for historical emissions whose allocation was not captured. */
    public function getInternalAllocation(): ?array
    {
        return $this->transmission->getUsageTerms()['internalAllocation'] ?? null;
    }
    public function getStripeCustomerId(): string { return $this->stripeCustomerId; }
    public function getStripeSubscriptionId(): string { return $this->stripeSubscriptionId; }
    public function getAcceptedAt(): \DateTimeImmutable { return $this->acceptedAt; }
    public function getPeriod(): string { return $this->period; }
    public function getBatch(): ?EInvoiceUsageBatch { return $this->batch; }
    public function assign(EInvoiceUsageBatch $batch): void
    {
        if ($this->batch || $batch->getConnection() !== $this->connection || !$this->id) throw new \LogicException('Consommation déjà regroupée ou étrangère.');
        $this->batch = $batch;
    }
}
