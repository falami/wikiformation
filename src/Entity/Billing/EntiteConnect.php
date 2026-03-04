<?php

namespace App\Entity\Billing;

use App\Entity\Entite;
use App\Repository\Billing\EntiteConnectRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EntiteConnectRepository::class)]
#[ORM\Table(name: 'billing_entite_connect')]
#[ORM\UniqueConstraint(name: 'uniq_entite_connect', columns: ['entite_id'])]
class EntiteConnect
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'connect', targetEntity: Entite::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Entite $entite = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeAccountId = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $detailsSubmitted = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $chargesEnabled = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $payoutsEnabled = false;

    // Paramètres “produit”
    #[ORM\Column(options: ['default' => true])]
    private bool $onlinePaymentEnabled = true;

    // Frais de service (plateforme) répercutés au payeur
    // ex: 2.9% + 0.30€ => tu peux mettre 0 si tu ne veux pas
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $feeFixedCents = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $feePercentBp = 0; // basis points : 250 = 2.50%

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }


    public function getId(): ?int { return $this->id; }

    public function getEntite(): ?Entite { return $this->entite; }
    public function setEntite(?Entite $entite): static { $this->entite = $entite; return $this; }

    public function getStripeAccountId(): ?string { return $this->stripeAccountId; }
    public function setStripeAccountId(?string $id): static { $this->stripeAccountId = $id; return $this; }

    public function isDetailsSubmitted(): bool { return $this->detailsSubmitted; }
    public function setDetailsSubmitted(bool $v): static { $this->detailsSubmitted = $v; return $this; }

    public function isChargesEnabled(): bool { return $this->chargesEnabled; }
    public function setChargesEnabled(bool $v): static { $this->chargesEnabled = $v; return $this; }

    public function isPayoutsEnabled(): bool { return $this->payoutsEnabled; }
    public function setPayoutsEnabled(bool $v): static { $this->payoutsEnabled = $v; return $this; }

    public function isOnlinePaymentEnabled(): bool { return $this->onlinePaymentEnabled; }
    public function setOnlinePaymentEnabled(bool $v): static { $this->onlinePaymentEnabled = $v; return $this; }

    public function getFeeFixedCents(): int { return $this->feeFixedCents; }
    public function setFeeFixedCents(int $c): static { $this->feeFixedCents = max(0, $c); return $this; }

    public function getFeePercentBp(): int { return $this->feePercentBp; }
    public function setFeePercentBp(int $bp): static { $this->feePercentBp = max(0, $bp); return $this; }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function touch(): self 
    { 
        $this->updatedAt = new \DateTimeImmutable(); 
        return $this; 
    }


    /** Frais répercutés (service fee), calculés sur un montant TTC en cents */
    public function computeServiceFeeCents(int $amountCents): int
    {
        $amountCents = max(0, $amountCents);
        $fixed = max(0, $this->feeFixedCents);
        $pct = max(0, $this->feePercentBp);

        // amount * (bp/10000)
        $percentFee = (int) round($amountCents * ($pct / 10000));

        return max(0, $fixed + $percentFee);
    }

    public function isReadyForCheckout(): bool
    {
        return (bool)$this->stripeAccountId && $this->chargesEnabled && $this->detailsSubmitted;
    }
}