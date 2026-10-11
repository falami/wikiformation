<?php
declare(strict_types=1);
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Durable, frozen Stripe invoice-item request; never re-created after an ambiguous response. */
#[ORM\Entity]
#[ORM\Table(name: 'einvoice_usage_batch')]
#[ORM\Index(name: 'idx_einvoice_usage_batch_connection', columns: ['connection_id'])]
#[ORM\UniqueConstraint(name: 'uniq_einvoice_usage_batch_key', columns: ['request_key'])]
class EInvoiceUsageBatch
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private EInvoiceConnection $connection;
    #[ORM\Column(length: 80)] private string $requestKey;
    #[ORM\Column(length: 7)] private string $period;
    #[ORM\Column(type: 'json')] private array $payload;
    #[ORM\Column] private int $quantity;
    #[ORM\Column] private int $amountCents;
    #[ORM\Column(length: 20)] private string $status = 'prepared';
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $attemptedAt = null;
    #[ORM\Column(length: 255, nullable: true)] private ?string $stripeInvoiceItemId = null;
    #[ORM\Version, ORM\Column(type: 'integer')] private int $version = 1;

    /** @param non-empty-list<EInvoiceUsage> $usages */
    public function __construct(array $usages)
    {
        if (!$usages) throw new \InvalidArgumentException('Consommation absente.');
        usort($usages, static fn ($a, $b) => $a->getId() <=> $b->getId());
        $first = $usages[0]; $this->connection = $first->getConnection(); $this->period = $first->getPeriod();
        $ids = []; $amount = 0;
        foreach ($usages as $usage) {
            if (!$usage->getId() || $usage->getBatch() || $usage->getConnection() !== $this->connection || $usage->getPeriod() !== $this->period
                || $usage->getStripeCustomerId() !== $first->getStripeCustomerId() || $usage->getStripeSubscriptionId() !== $first->getStripeSubscriptionId()
                || $usage->getUnitPriceCents() !== $first->getUnitPriceCents()) throw new \DomainException('Regroupement de consommations incompatible.');
            $ids[] = $usage->getId(); $amount += $usage->getUnitPriceCents();
        }
        $this->quantity = count($ids); $this->amountCents = $amount;
        $this->requestKey = 'wf-einvoice-'.hash('sha256', $this->connection->getId().':'.implode(',', $ids));
        $start = new \DateTimeImmutable($this->period.'-01 00:00:00', new \DateTimeZone('Europe/Paris'));
        $this->payload = ['customer' => $first->getStripeCustomerId(), 'subscription' => $first->getStripeSubscriptionId(),
            'currency' => 'eur', 'unit_amount_decimal' => (string) $first->getUnitPriceCents(), 'quantity' => $this->quantity,
            'tax_behavior' => 'exclusive', 'discountable' => false,
            'description' => 'Émissions électroniques '.$this->period.' : '.$this->quantity.' × '.number_format($first->getUnitPriceCents()/100, 2, ',', '').' € HT',
            'period' => ['start' => $start->getTimestamp(), 'end' => $start->modify('+1 month')->getTimestamp()-1],
            'metadata' => ['wf_einvoice_batch' => $this->requestKey, 'entite_id' => (string) $this->connection->getEntite()->getId(), 'usage_period' => $this->period]];
        foreach ($usages as $usage) $usage->assign($this);
    }
    public function getId(): ?int { return $this->id; }
    public function getConnection(): EInvoiceConnection { return $this->connection; }
    public function getRequestKey(): string { return $this->requestKey; }
    public function getPeriod(): string { return $this->period; }
    public function getPayload(): array { return $this->payload; }
    public function getQuantity(): int { return $this->quantity; }
    public function getAmountCents(): int { return $this->amountCents; }
    public function getStatus(): string { return $this->status; }
    public function getAttemptedAt(): ?\DateTimeImmutable { return $this->attemptedAt; }
    public function getStripeInvoiceItemId(): ?string { return $this->stripeInvoiceItemId; }
    public function begin(): void
    {
        if ($this->status !== 'prepared' || $this->attemptedAt) throw new \LogicException('Lot déjà tenté. Rapprochement requis.');
        $this->attemptedAt = new \DateTimeImmutable(); $this->status = 'submitting';
    }
    public function recorded(string $id): void
    {
        if (!$this->attemptedAt || !preg_match('/^ii_[A-Za-z0-9]+$/D', $id) || ($this->stripeInvoiceItemId && $this->stripeInvoiceItemId !== $id)) throw new \LogicException('Ligne Stripe incompatible.');
        $this->stripeInvoiceItemId = $id; $this->status = 'recorded';
    }
    public function uncertain(): void { if ($this->status === 'submitting') $this->status = 'uncertain'; }
}
