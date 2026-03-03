<?php

namespace App\Entity;

use App\Repository\LigneDevisRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: LigneDevisRepository::class)]
class LigneDevis
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'lignes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Devis $devis = null;

    #[ORM\Column(type: 'text')]
    private string $label = '';

    #[ORM\Column]
    private int $qte = 1;

    #[ORM\Column]
    private int $puHtCents = 0;

    #[ORM\Column(type: 'float', options: ['default' => 20])]
    private float $tva = 20.0;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $remisePourcent = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $remiseMontantCents = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'ligneDevisCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\ManyToOne(inversedBy: 'ligneDevisEntites')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Entite $entite = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isDebours = false;


    public function __construct()
    {
        $this->dateCreation = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDevis(): ?Devis
    {
        return $this->devis;
    }

    public function setDevis(?Devis $devis): static
    {
        $this->devis = $devis;

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

    public function getQte(): ?int
    {
        return $this->qte;
    }

    public function setQte(int $qte): static
    {
        $this->qte = $qte;

        return $this;
    }

    public function getPuHtCents(): ?int
    {
        return $this->puHtCents;
    }

    public function setPuHtCents(int $puHtCents): static
    {
        $this->puHtCents = $puHtCents;

        return $this;
    }

    public function getTva(): ?float
    {
        return $this->tva;
    }

    public function setTva(float $tva): static
    {
        $this->tva = $tva;

        return $this;
    }

    public function getRemisePourcent(): ?float
    {
        return $this->remisePourcent;
    }

    public function setRemisePourcent(?float $v): static
    {
        if ($v === null || $v <= 0) {
            $this->remisePourcent = null;
            return $this;
        }

        // optionnel: borne à 100%
        $this->remisePourcent = min(100, $v);
        return $this;
    }

    public function getRemiseMontantCents(): ?int
    {
        return $this->remiseMontantCents;
    }

    public function setRemiseMontantCents(?int $v): static
    {
        if ($v === null || $v <= 0) {
            $this->remiseMontantCents = null;
            return $this;
        }

        $this->remiseMontantCents = $v;
        return $this;
    }

    // Helpers (facultatif mais pratique)
    public function getTotalHtBrutCents(): int
    {
        return (int) round($this->qte * $this->puHtCents);
    }

    public function getRemiseCents(): int
    {
        $base = $this->getTotalHtBrutCents();
        if ($this->remiseMontantCents > 0) return min($this->remiseMontantCents, $base);
        if ($this->remisePourcent > 0) return (int) round($base * ($this->remisePourcent / 100));
        return 0;
    }

    public function getTotalHtNetCents(): int
    {
        return max(0, $this->getTotalHtBrutCents() - $this->getRemiseCents());
    }


    #[Assert\Callback]
    public function validateRemise(ExecutionContextInterface $context): void
    {
        if ($this->remisePourcent > 0 && $this->remiseMontantCents > 0) {
            $context->buildViolation('Choisis soit une remise en %, soit une remise en € (pas les deux).')
                ->atPath('remisePourcent')
                ->addViolation();
        }
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

    public function getCreateur(): ?Utilisateur
    {
        return $this->createur;
    }

    public function setCreateur(?Utilisateur $createur): static
    {
        $this->createur = $createur;

        return $this;
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

    public function isDebours(): bool
    {
        return $this->isDebours;
    }

    public function setIsDebours(bool $isDebours): static
    {
        $this->isDebours = $isDebours;

        return $this;
    }
}
