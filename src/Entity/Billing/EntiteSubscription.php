<?php

namespace App\Entity\Billing;

use App\Entity\Entite;
use App\Repository\Billing\EntiteSubscriptionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EntiteSubscriptionRepository::class)]
#[ORM\Table(name: 'billing_entite_subscription')]
class EntiteSubscription
{

    public const STATUS_INCOMPLETE = 'incomplete';
    public const STATUS_TRIALING   = 'trialing';
    public const STATUS_ACTIVE     = 'active';
    public const STATUS_PAST_DUE   = 'past_due';
    public const STATUS_CANCELED   = 'canceled';
    public const STATUS_UNPAID     = 'unpaid';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'entiteSubscriptions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Entite $entite = null;

    #[ORM\ManyToOne(inversedBy: 'entiteSubscriptions')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Plan $plan = null;

    #[ORM\Column(length: 30)]
    private string $status = self::STATUS_INCOMPLETE;

    #[ORM\Column(length: 10)]
    private string $intervale = 'month';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeCustomerId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeSubscriptionId = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $currentPeriodEnd = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $trialEndsAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $canceledAt = null;

    #[ORM\Column(nullable: true)]
    private ?array $addons = [];

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

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEntite(): ?Entite
    {
        return $this->entite;
    }

    public function setEntite(?Entite $entite): static
    {
        $this->entite = $entite;

        return $this;
    }

    public function getPlan(): ?Plan
    {
        return $this->plan;
    }

    public function setPlan(?Plan $plan): static
    {
        $this->plan = $plan;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getIntervale(): string
    {
        return $this->intervale;
    }

    public function setIntervale(string $intervale): static
    {
        $this->intervale = $intervale;

        return $this;
    }

    public function getStripeCustomerId(): ?string
    {
        return $this->stripeCustomerId;
    }

    public function setStripeCustomerId(?string $stripeCustomerId): static
    {
        $this->stripeCustomerId = $stripeCustomerId;

        return $this;
    }

    public function getStripeSubscriptionId(): ?string
    {
        return $this->stripeSubscriptionId;
    }

    public function setStripeSubscriptionId(?string $stripeSubscriptionId): static
    {
        $this->stripeSubscriptionId = $stripeSubscriptionId;

        return $this;
    }

    public function getCurrentPeriodEnd(): ?\DateTimeImmutable
    {
        return $this->currentPeriodEnd;
    }

    public function setCurrentPeriodEnd(?\DateTimeImmutable $currentPeriodEnd): static
    {
        $this->currentPeriodEnd = $currentPeriodEnd;

        return $this;
    }

    public function getTrialEndsAt(): ?\DateTimeImmutable
    {
        return $this->trialEndsAt;
    }

    public function setTrialEndsAt(?\DateTimeImmutable $trialEndsAt): static
    {
        $this->trialEndsAt = $trialEndsAt;

        return $this;
    }

    public function getCanceledAt(): ?\DateTimeImmutable
    {
        return $this->canceledAt;
    }

    public function setCanceledAt(?\DateTimeImmutable $canceledAt): static
    {
        $this->canceledAt = $canceledAt;

        return $this;
    }

    public function getAddons(): ?array
    {
        return $this->addons;
    }

    public function setAddons(?array $addons): static
    {
        $this->addons = $addons;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    // EntiteSubscription.php
    // EntiteSubscription.php
    public function isBlockingNewCheckout(): bool
    {
        $now = new \DateTimeImmutable();

        // Jamais bloquant si canceled
        if ($this->status === self::STATUS_CANCELED) {
            return false;
        }

        // Trialing : bloquant seulement si le trial n'est pas fini
        if ($this->status === self::STATUS_TRIALING) {
            return $this->trialEndsAt instanceof \DateTimeImmutable
                ? $this->trialEndsAt > $now
                : true; // si pas de date, on considère bloquant (cas Stripe trialing)
        }

        // Active / past_due / unpaid : bloquant si période en cours
        if (in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_PAST_DUE, self::STATUS_UNPAID], true)) {
            if ($this->canceledAt instanceof \DateTimeImmutable && $this->canceledAt <= $now) {
                return false;
            }

            // Si on a une fin de période, on s'appuie dessus
            if ($this->currentPeriodEnd instanceof \DateTimeImmutable) {
                return $this->currentPeriodEnd > $now;
            }

            // Si pas de date, on considère bloquant (abonnement Stripe en cours mais dates pas sync)
            return true;
        }

        // incomplete, etc.
        return false;
    }


    // src/Entity/Billing/EntiteSubscription.php

    public function getBlockingMessage(): string
    {
        $now = new \DateTimeImmutable();

        return match ($this->status) {
            self::STATUS_TRIALING => $this->trialEndsAt instanceof \DateTimeImmutable && $this->trialEndsAt <= $now
                ? "Votre période d’essai est terminée. Merci de souscrire à une offre pour continuer à utiliser WikiFormation."
                : "Votre période d’essai est en cours. Vous pouvez souscrire à une offre à tout moment.",

            self::STATUS_PAST_DUE => "Votre paiement a échoué. Merci de mettre à jour votre moyen de paiement pour réactiver WikiFormation.",

            self::STATUS_UNPAID => "Votre abonnement est impayé. Merci de régulariser votre situation pour réactiver WikiFormation.",

            self::STATUS_ACTIVE => $this->canceledAt instanceof \DateTimeImmutable && $this->canceledAt > $now
                ? "Votre abonnement est résilié et restera actif jusqu’au " . $this->formatDateFr($this->canceledAt) . "."
                : "Votre abonnement n’est plus actif. Merci de souscrire à une offre pour continuer à utiliser WikiFormation.",

            self::STATUS_CANCELED => "Votre abonnement a été annulé. Merci de souscrire à une offre pour continuer à utiliser WikiFormation.",

            self::STATUS_INCOMPLETE => "Votre souscription n’a pas été finalisée. Merci de relancer la souscription pour continuer à utiliser WikiFormation.",

            default => "Votre accès est limité. Merci de souscrire à une offre pour continuer à utiliser WikiFormation.",
        };
    }

    private function formatDateFr(\DateTimeImmutable $dt): string
    {
        return $dt->format('d/m/Y');
    }
}
