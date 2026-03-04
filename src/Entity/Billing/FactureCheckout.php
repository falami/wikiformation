<?php

namespace App\Entity\Billing;

use App\Entity\Entite;
use App\Entity\Facture;
use App\Entity\Utilisateur;
use App\Entity\Entreprise;
use App\Repository\Billing\FactureCheckoutRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FactureCheckoutRepository::class)]
#[ORM\Table(name: 'billing_facture_checkout')]
#[ORM\Index(name: 'idx_checkout_session', columns: ['stripe_checkout_session_id'])]
#[ORM\Index(name: 'idx_checkout_intent', columns: ['stripe_payment_intent_id'])]
class FactureCheckout
{
    public const STATUS_CREATED   = 'created';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_EXPIRED   = 'expired';
    public const STATUS_CANCELED  = 'canceled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Facture $facture = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Entite $entite = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeCheckoutSessionId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripePaymentIntentId = null;

    #[ORM\Column(length: 30)]
    private string $status = self::STATUS_CREATED;

    #[ORM\Column]
    private int $amountTotalCents = 0;

    #[ORM\Column]
    private int $serviceFeeCents = 0;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $payeurUtilisateur = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Entreprise $payeurEntreprise = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;


    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $checkoutUrl = null;

    #[ORM\Column]
    private int $factureAmountCents = 0;



    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getFacture(): ?Facture { return $this->facture; }
    public function setFacture(?Facture $f): static { $this->facture = $f; return $this; }

    public function getEntite(): ?Entite { return $this->entite; }
    public function setEntite(?Entite $e): static { $this->entite = $e; return $this; }

    public function getStripeCheckoutSessionId(): ?string 
    { 
        return $this->stripeCheckoutSessionId; 
    }

    public function setStripeCheckoutSessionId(?string $id): static 
    { 
        $this->stripeCheckoutSessionId = $id; 
        return $this; 
    }

    public function getStripePaymentIntentId(): ?string { return $this->stripePaymentIntentId; }
    public function setStripePaymentIntentId(?string $id): static { $this->stripePaymentIntentId = $id; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $s): static { $this->status = $s; return $this; }

    public function getAmountTotalCents(): int { return $this->amountTotalCents; }
    public function setAmountTotalCents(int $c): static { $this->amountTotalCents = $c; return $this; }

    public function getServiceFeeCents(): int { return $this->serviceFeeCents; }
    public function setServiceFeeCents(int $c): static { $this->serviceFeeCents = $c; return $this; }

    public function getPayeurUtilisateur(): ?Utilisateur { return $this->payeurUtilisateur; }
    public function setPayeurUtilisateur(?Utilisateur $u): static { $this->payeurUtilisateur = $u; return $this; }

    public function getPayeurEntreprise(): ?Entreprise { return $this->payeurEntreprise; }
    public function setPayeurEntreprise(?Entreprise $e): static { $this->payeurEntreprise = $e; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }


    public function getCheckoutUrl(): ?string { return $this->checkoutUrl; }
    public function setCheckoutUrl(?string $u): static { $this->checkoutUrl = $u; return $this; }

    public function getFactureAmountCents(): int { return $this->factureAmountCents; }
    public function setFactureAmountCents(int $c): static { $this->factureAmountCents = $c; return $this; }
}