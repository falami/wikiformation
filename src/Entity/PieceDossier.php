<?php

namespace App\Entity;

use App\Repository\PieceDossierRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Enum\PieceType;

#[ORM\Entity(repositoryClass: PieceDossierRepository::class)]
class PieceDossier
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'pieces')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?DossierInscription $dossier = null;

    #[ORM\Column(enumType: PieceType::class)]
    private PieceType $type;
    // CNI, CONVOCATION_SIGNEE, REGLEMENT_INTERIEUR_SIGNE, ATTESTATION_EMPLOYEUR, OPCO_PEC, JUSTIF_DOMICILE, etc.

    #[ORM\Column(length: 255)]
    private string $filename;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $mimeType = null;

    #[ORM\Column]
    private \DateTimeImmutable $uploadedAt;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $valide = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $commentaireControle = null;

    #[ORM\ManyToOne(inversedBy: 'pieces')]
    private ?Inscription $inscription = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'pieceDossierCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\ManyToOne(inversedBy: 'pieceDossierEntites')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Entite $entite = null;


    public function __construct()
    {
        $this->dateCreation = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDossier(): ?DossierInscription
    {
        return $this->dossier;
    }

    public function setDossier(?DossierInscription $dossier): static
    {
        $this->dossier = $dossier;

        return $this;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function setFilename(string $filename): static
    {
        $this->filename = $filename;

        return $this;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function setMimeType(?string $mimeType): static
    {
        $this->mimeType = $mimeType;

        return $this;
    }

    public function getUploadedAt(): \DateTimeImmutable
    {
        return $this->uploadedAt;
    }

    public function setUploadedAt(\DateTimeImmutable $uploadedAt): static
    {
        $this->uploadedAt = $uploadedAt;

        return $this;
    }

    public function isValide(): bool
    {
        return $this->valide;
    }

    public function setValide(bool $valide): static
    {
        $this->valide = $valide;

        return $this;
    }

    public function getCommentaireControle(): ?string
    {
        return $this->commentaireControle;
    }

    public function setCommentaireControle(?string $commentaireControle): static
    {
        $this->commentaireControle = $commentaireControle;

        return $this;
    }

    public function getInscription(): ?Inscription
    {
        return $this->inscription;
    }

    public function setInscription(?Inscription $inscription): static
    {
        $this->inscription = $inscription;

        return $this;
    }

    public function getType(): PieceType
    {
        return $this->type;
    }

    public function setType(PieceType $type): static
    {
        $this->type = $type;

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
