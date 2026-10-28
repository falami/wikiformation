<?php
declare(strict_types=1);
namespace App\Entity\Billing;

use App\Entity\Entite;
use Doctrine\ORM\Mapping as ORM;

/** Durable reservation; never changes the organisation's plan or access rights. */
#[ORM\Entity]
#[ORM\Table(name: 'billing_internal_usage_setup')]
#[ORM\UniqueConstraint(name: 'uniq_internal_usage_entite', columns: ['entite_id'])]
class InternalUsageSetup
{
    #[ORM\Id] #[ORM\Column(length: 32)] public string $id;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] public Entite $entite;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] public EntiteSubscription $subscription;
    #[ORM\Column(type: 'json')] public array $request = [];
    #[ORM\Column(type: 'json')] public array $acceptance = [];
    #[ORM\Column(length: 255, nullable: true)] public ?string $customerId = null;
    #[ORM\Column(length: 255, nullable: true, unique: true)] public ?string $sessionId = null;
    #[ORM\Column(length: 2048, nullable: true)] public ?string $checkoutUrl = null;
    #[ORM\Column] public \DateTimeImmutable $createdAt;
    #[ORM\Column(nullable: true)] public ?\DateTimeImmutable $attemptedAt = null;

    public function __construct()
    {
        $this->id = bin2hex(random_bytes(16));
        $this->createdAt = new \DateTimeImmutable();
    }
}
