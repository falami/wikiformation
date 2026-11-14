<?php
declare(strict_types=1);
namespace App\Entity\Billing;
use App\Entity\Entite;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity, ORM\Table(name: 'billing_module_access')]
#[ORM\UniqueConstraint(name: 'module_tenant_unique', columns: ['entite_id', 'module_code'])]
class ModuleAccess
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] public ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] public Entite $entite;
    #[ORM\ManyToOne, ORM\JoinColumn(name: 'module_code', referencedColumnName: 'code', nullable: false)] public Module $module;
    #[ORM\Column(length: 40)] public string $status = 'inactive';
    #[ORM\Column(length: 255, nullable: true, unique: true)] public ?string $subscriptionId = null;
    #[ORM\Column(length: 255, nullable: true)] public ?string $customerId = null;
    #[ORM\Column(length: 255, nullable: true)] public ?string $checkoutId = null;
    #[ORM\Column(length: 255, nullable: true)] public ?string $priceId = null;
    #[ORM\Column(nullable: true)] public ?int $price = null;
    #[ORM\Column(nullable: true)] public ?\DateTimeImmutable $periodEnd = null;
    #[ORM\Column] public bool $cancelAtPeriodEnd = false;
    #[ORM\Column] public bool $trialUsed = false;
    #[ORM\Column] public bool $gift = false;
    #[ORM\Column(nullable: true)] public ?\DateTimeImmutable $giftEnd = null;
    #[ORM\Column(type: 'json')] public array $history = [];

    public function hasAccess(?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();
        return ($this->gift && ($this->giftEnd === null || $this->giftEnd > $now))
            || (in_array($this->status, ['active', 'trialing'], true) && $this->periodEnd !== null && $this->periodEnd > $now);
    }
    public function audit(string $action, ?int $actor = null): void
    {
        $this->history[] = ['at' => (new \DateTimeImmutable())->format(DATE_ATOM), 'action' => $action, 'actor' => $actor];
    }
}
