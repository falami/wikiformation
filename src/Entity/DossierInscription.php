<?php

namespace App\Entity;

use App\Repository\DossierInscriptionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Enum\PieceType;
use App\Entity\Session;

#[ORM\Entity(repositoryClass: DossierInscriptionRepository::class)]
class DossierInscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'dossier', targetEntity: Inscription::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Inscription $inscription = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $employeur = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $opco = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $numDossierOpco = null;

    #[ORM\Column(nullable: true)]
    private ?bool $amenagementHandicap = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $detailsAmenagement = null;

    /**
     * @var Collection<int, PieceDossier>
     */
    #[ORM\OneToMany(mappedBy: 'dossier', targetEntity: PieceDossier::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $pieces;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'dossierInscriptionCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\ManyToOne(inversedBy: 'dossierInscriptionEntites')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Entite $entite = null;

    public function __construct()
    {
        $this->pieces = new ArrayCollection();
        $this->dateCreation = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInscription(): ?Inscription
    {
        return $this->inscription;
    }

    public function setInscription(Inscription $inscription): static
    {
        $this->inscription = $inscription;

        return $this;
    }

    public function getEmployeur(): ?string
    {
        return $this->employeur;
    }

    public function setEmployeur(?string $employeur): static
    {
        $this->employeur = $employeur;

        return $this;
    }

    public function getOpco(): ?string
    {
        return $this->opco;
    }

    public function setOpco(?string $opco): static
    {
        $this->opco = $opco;

        return $this;
    }

    public function getNumDossierOpco(): ?string
    {
        return $this->numDossierOpco;
    }

    public function setNumDossierOpco(?string $numDossierOpco): static
    {
        $this->numDossierOpco = $numDossierOpco;

        return $this;
    }

    public function isAmenagementHandicap(): ?bool
    {
        return $this->amenagementHandicap;
    }

    public function setAmenagementHandicap(?bool $amenagementHandicap): static
    {
        $this->amenagementHandicap = $amenagementHandicap;

        return $this;
    }

    public function getDetailsAmenagement(): ?string
    {
        return $this->detailsAmenagement;
    }

    public function setDetailsAmenagement(?string $detailsAmenagement): static
    {
        $this->detailsAmenagement = $detailsAmenagement;

        return $this;
    }

    /**
     * @return Collection<int, PieceDossier>
     */
    public function getPieces(): Collection
    {
        return $this->pieces;
    }

    public function addPiece(PieceDossier $piece): static
    {
        if (!$this->pieces->contains($piece)) {
            $this->pieces->add($piece);
            $piece->setDossier($this);
        }

        return $this;
    }

    public function removePiece(PieceDossier $piece): static
    {
        if ($this->pieces->removeElement($piece)) {
            // set the owning side to null (unless already changed)
            if ($piece->getDossier() === $this) {
                $piece->setDossier(null);
            }
        }

        return $this;
    }

    /**
     * @return string[] liste des valeurs d’énum des pièces valides présentes
     */
    public function getTypesPiecesValides(): array
    {
        $types = [];
        foreach ($this->pieces as $p) {
            if ($p->isValide() && $p->getType() instanceof PieceType) {
                $types[] = $p->getType()->value;
            }
        }
        return array_values(array_unique($types));
    }

    /**
     * @param Session $session
     * @return PieceType[] liste des pièces manquantes (obligatoires, mais non présentes & validées)
     */
    public function getPiecesManquantesPourSession(Session $session): array
    {
        $obligatoires = $session->getPiecesObligatoires();        // array<string>
        if (empty($obligatoires)) {
            return [];
        }

        $presentes = $this->getTypesPiecesValides();             // array<string>
        $manquantes = [];

        foreach ($obligatoires as $value) {
            if (!in_array($value, $presentes, true)) {
                $enum = PieceType::tryFrom($value);
                if ($enum) {
                    $manquantes[] = $enum;
                }
            }
        }

        return $manquantes;
    }

    /**
     * @param Session $session
     */
    public function isCompletPourSession(Session $session): bool
    {
        // Si aucune pièce obligatoire définie pour la session → on considère “complet”
        $obligatoires = $session->getPiecesObligatoires();
        if (empty($obligatoires)) {
            return true;
        }

        return count($this->getPiecesManquantesPourSession($session)) === 0;
    }


    public function countPiecesValides(): int
    {
        $n = 0;
        foreach ($this->pieces as $p) {
            if ($p->isValide()) {
                $n++;
            }
        }
        return $n;
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
}
