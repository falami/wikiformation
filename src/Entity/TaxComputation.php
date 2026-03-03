<?php

namespace App\Entity;

use App\Repository\TaxComputationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TaxComputationRepository::class)]
#[ORM\Table(name: 'tax_computation')]
#[ORM\Index(name: 'idx_tc_entite_period', columns: ['entite_id', 'period_from', 'period_to'])]
class TaxComputation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'taxComputations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Entite $entite = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $periodFrom;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $periodTo;

    #[ORM\Column]
    private int $baseCents = 0;

    #[ORM\Column]
    private int $totalCents = 0;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $breakdown = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(inversedBy: 'taxComputationCreateurs')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Utilisateur $createur = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $profileSnapshot = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $rulesSnapshot = null;

    #[ORM\Column(length: 20)]
    private string $status = 'DRAFT';

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->dateCreation = new \DateTimeImmutable();
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

    public function getPeriodFrom(): \DateTimeImmutable
    {
        return $this->periodFrom;
    }

    public function setPeriodFrom(\DateTimeImmutable $periodFrom): static
    {
        $this->periodFrom = $periodFrom;

        return $this;
    }

    public function getPeriodTo(): \DateTimeImmutable
    {
        return $this->periodTo;
    }

    public function setPeriodTo(\DateTimeImmutable $periodTo): static
    {
        $this->periodTo = $periodTo;

        return $this;
    }

    public function getBaseCents(): int
    {
        return $this->baseCents;
    }

    public function setBaseCents(int $baseCents): static
    {
        $this->baseCents = $baseCents;

        return $this;
    }

    public function getTotalCents(): int
    {
        return $this->totalCents;
    }

    public function setTotalCents(int $totalCents): static
    {
        $this->totalCents = $totalCents;

        return $this;
    }

    public function getBreakdown(): ?array
    {
        return $this->breakdown;
    }

    public function setBreakdown(?array $breakdown): static
    {
        $this->breakdown = $breakdown;

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

    public function getCreateur(): ?Utilisateur
    {
        return $this->createur;
    }

    public function setCreateur(?Utilisateur $createur): static
    {
        $this->createur = $createur;

        return $this;
    }

    public function getDateCreation(): ?\DateTimeImmutable
    {
        return $this->dateCreation;
    }

    public function setDateCreation(\DateTimeImmutable $dateCreation): static
    {
        $this->dateCreation = $dateCreation;

        return $this;
    }

    public function getProfileSnapshot(): ?array
    {
        return $this->profileSnapshot;
    }

    public function setProfileSnapshot(?array $profileSnapshot): static
    {
        $this->profileSnapshot = $profileSnapshot;

        return $this;
    }

    public function getRulesSnapshot(): ?array
    {
        return $this->rulesSnapshot;
    }

    public function setRulesSnapshot(?array $rulesSnapshot): static
    {
        $this->rulesSnapshot = $rulesSnapshot;

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
}
