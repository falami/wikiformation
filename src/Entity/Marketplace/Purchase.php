<?php
namespace App\Entity\Marketplace;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity]
#[ORM\Table(name: "marketplace_purchase")]
class Purchase
{
    public function platformRevenueHtCents(): int
    {
        if (!$this->platformSale || !$this->paidAt || $this->status === 'disputed') return 0;
        $net = array_sum(array_column($this->items, 'net'));
        return max(0, $net - (int) round($net * $this->refundedCents / max(1, $this->totalCents)));
    }

    #[ORM\Column(options: ['default'=>0])] public int $billingVersion = 0;
    #[ORM\Column(options: ['default'=>0])] public int $commissionTaxCents = 0;

    public function commissionGrossCents(): int { return $this->commissionCents + $this->commissionTaxCents; }

    // Frozen at checkout: later role changes must never reroute money.
    #[ORM\Column(options: ['default'=>false])] public bool $platformSale = false;

    #[ORM\Column(type: 'json')] public array $checkoutRequest = [];
    #[ORM\Column(length: 2048, nullable: true)] public ?string $checkoutUrl = null;

    #[ORM\Column(type: 'json')] public array $payoutHistory = [];
    #[ORM\Column] public int $transferredCents = 0;

    #[ORM\Id] #[ORM\Column(length: 40)]
    public string $id = '';
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    public ?\App\Entity\Utilisateur $buyer = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    public ?Seller $seller = null;
    #[ORM\Column(length: 30)]
    public string $status = 'pending';
    #[ORM\Column]
    public int $totalCents = 0;
    #[ORM\Column]
    public int $commissionCents = 0;
    #[ORM\Column]
    public int $commissionBps = 0;
    #[ORM\Column(length: 12)]
    public string $feesPayer = 'seller';
    #[ORM\Column]
    public int $stripeFeeCents = 0;
    #[ORM\Column]
    public int $sellerCents = 0;
    #[ORM\Column]
    public int $refundedCents = 0;
    #[ORM\Column(type: 'json')]
    public array $items = [];
    #[ORM\Column(type: 'json')]
    public array $consent = [];
    #[ORM\Column(length: 255, nullable: true, unique: true)]
    public ?string $checkoutId = null;
    #[ORM\Column(length: 255, nullable: true, unique: true)]
    public ?string $paymentId = null;
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $chargeId = null;
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $transferId = null;
    #[ORM\Column]
    public ?\DateTimeImmutable $createdAt = null;
    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $paidAt = null;
    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $confirmationSentAt = null;
}
