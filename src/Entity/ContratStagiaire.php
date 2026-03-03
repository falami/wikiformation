<?php

namespace App\Entity;

use App\Repository\ContratStagiaireRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContratStagiaireRepository::class)]
class ContratStagiaire
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'contratStagiaire')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Inscription $inscription = null;


    #[ORM\ManyToOne(inversedBy: 'contratStagiaires')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Entite $entite = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dateSignatureStagiaire = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dateSignatureOf = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $pdfPath = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'contratStagiaireCreateurs')]
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

    public function getInscription(): ?Inscription
    {
        return $this->inscription;
    }

    public function setInscription(Inscription $inscription): static
    {
        $this->inscription = $inscription;

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

    public function getDateSignatureStagiaire(): ?\DateTimeImmutable
    {
        return $this->dateSignatureStagiaire;
    }

    public function setDateSignatureStagiaire(?\DateTimeImmutable $dateSignatureStagiaire): static
    {
        $this->dateSignatureStagiaire = $dateSignatureStagiaire;

        return $this;
    }

    public function getDateSignatureOf(): ?\DateTimeImmutable
    {
        return $this->dateSignatureOf;
    }

    public function setDateSignatureOf(?\DateTimeImmutable $dateSignatureOf): static
    {
        $this->dateSignatureOf = $dateSignatureOf;

        return $this;
    }

    public function getPdfPath(): ?string
    {
        return $this->pdfPath;
    }

    public function setPdfPath(?string $pdfPath): static
    {
        $this->pdfPath = $pdfPath;

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
