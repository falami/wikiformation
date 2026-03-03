<?php

namespace App\Entity;

use App\Repository\AvoirRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AvoirRepository::class)]
class Avoir
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'avoirs')]
    private ?Facture $factureOrigine = null;

    // src/Entity/Avoir.php (extraits)
    #[ORM\Column(length: 40, unique: true, nullable: false)]
    private ?string $numero = null;

    #[ORM\Column]
    private \DateTimeImmutable $dateEmission;

    #[ORM\Column]
    private int $montantTtcCents;

    #[ORM\Column]
    private ?array $meta = [];

    #[ORM\ManyToOne(inversedBy: 'avoirs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Entite $entite = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'avoirCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    public function __construct()
    {
        $this->dateCreation = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFactureOrigine(): ?Facture
    {
        return $this->factureOrigine;
    }

    public function setFactureOrigine(?Facture $factureOrigine): static
    {
        $this->factureOrigine = $factureOrigine;

        return $this;
    }

    public function getNumero(): string
    {
        if ($this->numero === null) {
            throw new \LogicException("Le numéro d’avoir n’a pas encore été généré.");
        }
        return $this->numero;
    }

    public function getNumeroOrNull(): ?string
    {
        return $this->numero;
    }

    public function hasNumero(): bool
    {
        return (bool) $this->numero;
    }

    public function setNumero(string $numero): static
    {
        $this->numero = $numero;
        return $this;
    }

    public function getDateEmission(): \DateTimeImmutable
    {
        return $this->dateEmission;
    }

    public function setDateEmission(\DateTimeImmutable $dateEmission): static
    {
        $this->dateEmission = $dateEmission;

        return $this;
    }

    public function getMontantTtcCents(): int
    {
        return $this->montantTtcCents;
    }

    public function setMontantTtcCents(int $montantTtcCents): static
    {
        $this->montantTtcCents = $montantTtcCents;

        return $this;
    }

    public function getMeta(): ?array
    {
        return $this->meta;
    }

    public function setMeta(array $meta): static
    {
        $this->meta = $meta;

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
}
