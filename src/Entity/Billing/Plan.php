<?php

namespace App\Entity\Billing;

use App\Repository\Billing\PlanRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PlanRepository::class)]
#[ORM\Table(name: 'billing_plan')]
class Plan
{
    public const CODE_EQUIPE = 'equipe';
    public const CODE_ORGA   = 'orga';
    public const CODE_ORGAP  = 'orga_plus';
    public const CODE_LEADER = 'leader';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, unique: true)]
    private string $code;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $tagline = null;

    #[ORM\Column(options: ['unsigned' => true])]
    private int $maxApprenantsAn = 0;

    #[ORM\Column(options: ['unsigned' => true])]
    private int $maxUtilisateurs = 0;

    #[ORM\Column(options: ['unsigned' => true])]
    private int $maxFormateurs = 0;

    #[ORM\Column(options: ['unsigned' => true])]
    private int $maxEntreprises = 0;

    #[ORM\Column(options: ['unsigned' => true])]
    private int $maxProspects = 0;

    #[ORM\Column]
    private bool $supportPrioritaire = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeProductId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripePriceMonthlyId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripePriceYearlyId = null;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column(nullable: true, options: ['unsigned' => true])]
    private ?int $priceMonthlyCents = null;

    #[ORM\Column(nullable: true, options: ['unsigned' => true])]
    private ?int $priceYearlyCents = null;

    #[ORM\Column(length: 7, nullable: true)]
    private ?string $accentColor = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $badge = null; // ex: "Populaire", "Premium"

    /**
     * @var Collection<int, EntiteSubscription>
     */
    #[ORM\OneToMany(targetEntity: EntiteSubscription::class, mappedBy: 'plan')]
    private Collection $entiteSubscriptions;



    #[ORM\Column(nullable: true)]
    private ?int $ordre = null;

    #[ORM\Column(type: 'json')]
    private array $seatLimits = [];
    // ex: ["TENANT_STAGIAIRE"=>100,"TENANT_FORMATEUR"=>10,"TENANT_ENTREPRISE"=>20,...]



    public function __construct()
    {
        $this->entiteSubscriptions = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getTagline(): ?string
    {
        return $this->tagline;
    }

    public function setTagline(?string $tagline): static
    {
        $this->tagline = $tagline;

        return $this;
    }

    public function getMaxApprenantsAn(): int
    {
        return $this->maxApprenantsAn;
    }

    public function setMaxApprenantsAn(int $maxApprenantsAn): static
    {
        $this->maxApprenantsAn = $maxApprenantsAn;

        return $this;
    }

    public function getMaxUtilisateurs(): int
    {
        return $this->maxUtilisateurs;
    }

    public function setMaxUtilisateurs(int $maxUtilisateurs): static
    {
        $this->maxUtilisateurs = $maxUtilisateurs;

        return $this;
    }

    public function getMaxFormateurs(): int
    {
        return $this->maxFormateurs;
    }

    public function setMaxFormateurs(int $maxFormateurs): static
    {
        $this->maxFormateurs = $maxFormateurs;

        return $this;
    }


    public function getMaxEntreprises(): int
    {
        return $this->maxEntreprises;
    }

    public function setMaxEntreprises(int $maxEntreprises): static
    {
        $this->maxEntreprises = $maxEntreprises;

        return $this;
    }

    public function isSupportPrioritaire(): ?bool
    {
        return $this->supportPrioritaire;
    }

    public function setSupportPrioritaire(bool $supportPrioritaire): static
    {
        $this->supportPrioritaire = $supportPrioritaire;

        return $this;
    }

    public function getStripeProductId(): ?string
    {
        return $this->stripeProductId;
    }

    public function setStripeProductId(?string $stripeProductId): static
    {
        $this->stripeProductId = $stripeProductId;

        return $this;
    }

    public function getStripePriceMonthlyId(): ?string
    {
        return $this->stripePriceMonthlyId;
    }

    public function setStripePriceMonthlyId(?string $stripePriceMonthlyId): static
    {
        $this->stripePriceMonthlyId = $stripePriceMonthlyId;

        return $this;
    }

    public function getStripePriceYearlyId(): ?string
    {
        return $this->stripePriceYearlyId;
    }

    public function setStripePriceYearlyId(?string $stripePriceYearlyId): static
    {
        $this->stripePriceYearlyId = $stripePriceYearlyId;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    /**
     * @return Collection<int, EntiteSubscription>
     */
    public function getEntiteSubscriptions(): Collection
    {
        return $this->entiteSubscriptions;
    }

    public function addEntiteSubscription(EntiteSubscription $entiteSubscription): static
    {
        if (!$this->entiteSubscriptions->contains($entiteSubscription)) {
            $this->entiteSubscriptions->add($entiteSubscription);
            $entiteSubscription->setPlan($this);
        }

        return $this;
    }

    public function removeEntiteSubscription(EntiteSubscription $entiteSubscription): static
    {
        if ($this->entiteSubscriptions->removeElement($entiteSubscription)) {
            // set the owning side to null (unless already changed)
            if ($entiteSubscription->getPlan() === $this) {
                $entiteSubscription->setPlan(null);
            }
        }

        return $this;
    }


    public function getMaxApprenantsLabel(): string
    {
        return $this->maxApprenantsAn === 0 ? 'Apprenants illimités' : $this->maxApprenantsAn . ' apprenants/an';
    }

    public function getMaxUtilisateursLabel(): string
    {
        return $this->maxUtilisateurs === 0 ? 'Utilisateurs illimités' : $this->maxUtilisateurs . ' utilisateurs';
    }

    public function getMaxFormateursLabel(): string
    {
        return $this->maxFormateurs === 0 ? 'Formateurs illimités' : $this->maxFormateurs . ' formateurs';
    }

    public function getMaxProspects(): int
    {
        return $this->maxProspects;
    }

    public function setMaxProspects(int $maxProspects): static
    {
        $this->maxProspects = $maxProspects;

        return $this;
    }

    public function getOrdre(): ?int
    {
        return $this->ordre;
    }

    public function setOrdre(?int $ordre): static
    {
        $this->ordre = $ordre;

        return $this;
    }


    public function getSeatLimits(): array
    {
        return $this->seatLimits ?? [];
    }
    public function setSeatLimits(array $limits): self
    {
        $this->seatLimits = $limits;
        return $this;
    }

    public function getLimitFor(string $tenantRole): int
    {
        // 0 = illimité
        return (int)($this->seatLimits[$tenantRole] ?? 0);
    }


    public function getPriceMonthlyCents(): ?int
    {
        return $this->priceMonthlyCents;
    }
    public function setPriceMonthlyCents(?int $v): static
    {
        $this->priceMonthlyCents = $v;
        return $this;
    }

    public function getPriceYearlyCents(): ?int
    {
        return $this->priceYearlyCents;
    }
    public function setPriceYearlyCents(?int $v): static
    {
        $this->priceYearlyCents = $v;
        return $this;
    }

    public function getAccentColor(): ?string
    {
        return $this->accentColor;
    }
    public function setAccentColor(?string $v): static
    {
        $this->accentColor = $v;
        return $this;
    }

    public function getBadge(): ?string
    {
        return $this->badge;
    }
    public function setBadge(?string $v): static
    {
        $this->badge = $v;
        return $this;
    }

    public function getPriceFor(string $interval): ?int
    {
        return $interval === 'month' ? $this->priceMonthlyCents : $this->priceYearlyCents;
    }
}
