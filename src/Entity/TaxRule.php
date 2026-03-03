<?php

namespace App\Entity;

use App\Repository\TaxRuleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Enum\TaxBase;
use App\Enum\TaxKind;

#[ORM\Entity(repositoryClass: TaxRuleRepository::class)]
#[ORM\Table(name: 'tax_rule')]
#[ORM\UniqueConstraint(name: 'uniq_tax_rule_entite_code', columns: ['entite_id', 'code'])]
#[ORM\Index(name: 'idx_tax_rule_dates', columns: ['valid_from', 'valid_to'])]
class TaxRule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'taxRules')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Entite $entite = null;

    #[ORM\ManyToOne(inversedBy: 'taxRuleCreateurs')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Utilisateur $createur = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\Column(length: 120)]
    private ?string $code = null;

    #[ORM\Column(length: 255)]
    private ?string $label = null;

    #[ORM\Column]
    private ?float $rate = null;

    #[ORM\Column(nullable: true)]
    private ?int $flatCents = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $validFrom = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $validTo = null;

    #[ORM\Column(nullable: true)]
    private ?array $conditions = null;

    #[ORM\Column(nullable: true)]
    private ?array $meta = null;


    #[ORM\Column(enumType: TaxKind::class)]
    private TaxKind $kind = TaxKind::CONTRIBUTION;

    #[ORM\Column(enumType: TaxBase::class)]
    private TaxBase $base = TaxBase::CA_ENCAISSE_TTC;


    public function __construct()
    {
        $this->validFrom = new \DateTimeImmutable('first day of january 00:00:00');
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

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getRate(): ?float
    {
        return $this->rate;
    }

    public function setRate(float $rate): static
    {
        $this->rate = $rate;

        return $this;
    }

    public function getFlatCents(): ?int
    {
        return $this->flatCents;
    }

    public function setFlatCents(?int $flatCents): static
    {
        $this->flatCents = $flatCents;

        return $this;
    }

    public function getValidFrom(): ?\DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function setValidFrom(\DateTimeImmutable $validFrom): static
    {
        $this->validFrom = $validFrom;

        return $this;
    }

    public function getValidTo(): ?\DateTimeImmutable
    {
        return $this->validTo;
    }

    public function setValidTo(?\DateTimeImmutable $validTo): static
    {
        $this->validTo = $validTo;

        return $this;
    }

    public function getConditions(): ?array
    {
        return $this->conditions;
    }

    public function setConditions(?array $conditions): static
    {
        $this->conditions = $conditions;

        return $this;
    }

    public function getMeta(): ?array
    {
        return $this->meta;
    }

    public function setMeta(?array $meta): static
    {
        $this->meta = $meta;

        return $this;
    }

    public function getKind(): TaxKind
    {
        return $this->kind;
    }

    public function setKind(TaxKind $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    public function getBase(): TaxBase
    {
        return $this->base;
    }

    public function setBase(TaxBase $base): static
    {
        $this->base = $base;

        return $this;
    }
}
