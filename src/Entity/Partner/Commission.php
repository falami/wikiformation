<?php

declare (strict_types=1);
namespace App\Entity\Partner;

use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity, ORM\Table(name: 'affiliate_commission')]
class Commission
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)]
    public Referral $referral;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)]
    public PartnerTerms $terms;
    #[ORM\Column(length: 100, unique: true)]
    public string $invoiceId = '';
    #[ORM\Column(length: 100)]
    public string $subscriptionId = '';
    #[ORM\Column(length: 100, nullable: true)]
    public ?string $chargeId = null;
    #[ORM\Column(length: 100)]
    public string $planName = '';
    #[ORM\Column(length: 3)]
    public string $currency = 'eur';
    #[ORM\Column]
    public int $baseCents = 0;
    #[ORM\Column]
    public int $grossCents = 0;
    #[ORM\Column]
    public int $feeCents = 0;
    #[ORM\Column]
    public int $netCents = 0;
    #[ORM\Column]
    public int $refundedCents = 0;
    #[ORM\Column]
    public int $reversedCents = 0;
    #[ORM\Column(length: 30)]
    public string $status = 'pending';
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $issue = null;
    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $paidAt;
    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $availableAt;
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $transferAttemptAt = null;
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $payoutAttemptAt = null;
    #[ORM\Column(length: 100, nullable: true, unique: true)]
    public ?string $transferId = null;
    #[ORM\Column(length: 100, nullable: true, unique: true)]
    public ?string $payoutId = null;
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $arrivalAt = null;
    #[ORM\Column(type: 'json')]
    public array $evidence = [];
    public function __construct()
    {
        $this->paidAt = new \DateTimeImmutable();
        $this->availableAt = $this->paidAt->modify('+30 days');
    }
}
