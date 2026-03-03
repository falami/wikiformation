<?php

namespace App\Entity;

use App\Repository\AttestationRepository;
use Doctrine\ORM\Mapping as ORM;


#[ORM\Entity(repositoryClass: AttestationRepository::class)]
#[ORM\Table(name: 'attestation')]
#[ORM\Index(name: 'idx_attestation_num', columns: ['numero'])]
#[ORM\UniqueConstraint(name: 'uniq_entite_num', columns: ['entite_id', 'numero'])]
class Attestation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'attestation')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Inscription $inscription = null;

    #[ORM\Column(length: 50, nullable: false)]
    private ?string $numero = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $dureeHeures = 0;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $reussi;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $dateDelivrance;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $pdfPath = null;

    #[ORM\ManyToOne(inversedBy: 'attestations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Entite $entite = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'attestationCreateurs')]
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

    /** Accès public strict */
    public function getNumero(): string
    {
        if ($this->numero === null) {
            throw new \LogicException('Le numéro d’attestation n’a pas encore été généré.');
        }
        return $this->numero;
    }

    /** Accès interne sans exception (pour listener, templates prudents, etc.) */
    public function getNumeroOrNull(): ?string
    {
        return $this->numero;
    }

    /** Pratique pour les tests dans le listener */
    public function hasNumero(): bool
    {
        return $this->numero !== null && $this->numero !== '';
    }

    public function setNumero(string $numero): static
    {
        $this->numero = $numero;
        return $this;
    }


    public function getDureeHeures(): int
    {
        return $this->dureeHeures;
    }

    public function setDureeHeures(int $dureeHeures): static
    {
        $this->dureeHeures = $dureeHeures;

        return $this;
    }

    public function isReussi(): bool
    {
        return $this->reussi;
    }

    public function setReussi(bool $reussi): static
    {
        $this->reussi = $reussi;

        return $this;
    }

    public function getDateDelivrance(): \DateTimeImmutable
    {
        return $this->dateDelivrance;
    }

    public function setDateDelivrance(\DateTimeImmutable $dateDelivrance): static
    {
        $this->dateDelivrance = $dateDelivrance;

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
