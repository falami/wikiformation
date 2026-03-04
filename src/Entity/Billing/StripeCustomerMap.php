<?php

namespace App\Entity\Billing;

use App\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'stripe_customer_map')]
#[ORM\UniqueConstraint(name: 'uniq_stripe_customer_map', columns: ['connected_account_id', 'utilisateur_id'])]
class StripeCustomerMap
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $connectedAccountId;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Utilisateur $utilisateur;

    #[ORM\Column(length: 255)]
    private string $stripeCustomerId;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getConnectedAccountId(): string { return $this->connectedAccountId; }
    public function setConnectedAccountId(string $v): self { $this->connectedAccountId = $v; return $this; }

    public function getUtilisateur(): Utilisateur { return $this->utilisateur; }
    public function setUtilisateur(Utilisateur $u): self { $this->utilisateur = $u; return $this; }

    public function getStripeCustomerId(): string { return $this->stripeCustomerId; }
    public function setStripeCustomerId(string $v): self { $this->stripeCustomerId = $v; return $this; }
}