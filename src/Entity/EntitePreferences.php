<?php

namespace App\Entity;

use App\Repository\EntitePreferencesRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EntitePreferencesRepository::class)]
#[ORM\HasLifecycleCallbacks]
class EntitePreferences
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // =========================
    //   LIEN ENTITE + META
    // =========================

    #[ORM\OneToOne(inversedBy: 'preferences', cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Entite $entite = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\ManyToOne(inversedBy: 'entitePreferenceCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $updatedBy = null;

    // =========================
    //   CLAUSES PAR DEFAUT
    // =========================

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $contratFormateurConditionsGeneralesDefault = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $contratFormateurConditionsParticulieresDefault = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $contratFormateurClauseEngagementDefault = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $contratFormateurClauseObjetDefault = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $contratFormateurClauseEmploiDefault = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $contratFormateurClauseObligationsDefault = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $contratFormateurClauseNonConcurrenceDefault = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $contratFormateurClauseInexecutionDefault = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $contratFormateurClauseAssuranceDefault = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $contratFormateurClauseFinContratDefault = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $contratFormateurClauseProprieteIntellectuelleDefault = null;

    // =========================
    //   SIGNATURE ORGANISME (DEFAULT)
    // =========================

    // Chemin public (ex: /uploads/signatures/entite_12.png)
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $signatureOrganismePath = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $signatureOrganismeAt = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $signatureOrganismeIp = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $signatureOrganismeUserAgent = null;

    // identité affichéeen PDF
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $signatureOrganismeNom = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $signatureOrganismeFonction = null;

    // audit (qui a configuré la signature)
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $signatureOrganismePar = null;

    // option premium : empreinte fichier signature pour audit (si tu veux)
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $signatureOrganismeSha256 = null;

    public function __construct()
    {
        $this->dateCreation = new \DateTimeImmutable();
    }

    // =========================
    //   LIFECYCLE
    // =========================
    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        if (!$this->dateCreation) {
            $this->dateCreation = new \DateTimeImmutable();
        }
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    // =========================
    //   HELPERS
    // =========================
    public function hasSignatureOrganismeConfigured(): bool
    {
        return (bool) ($this->signatureOrganismePath);
    }

    public function clearSignatureOrganisme(): static
    {
        $this->signatureOrganismePath = null;
        $this->signatureOrganismeAt = null;
        $this->signatureOrganismeIp = null;
        $this->signatureOrganismeUserAgent = null;
        $this->signatureOrganismeNom = null;
        $this->signatureOrganismeFonction = null;
        $this->signatureOrganismePar = null;
        $this->signatureOrganismeSha256 = null;

        return $this;
    }

    // =========================
    //   GETTERS / SETTERS
    // =========================

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEntite(): ?Entite
    {
        return $this->entite;
    }
    public function setEntite(Entite $entite): static
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

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }
    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getCreateur(): ?Utilisateur
    {
        return $this->createur;
    }
    public function setCreateur(Utilisateur $createur): static
    {
        $this->createur = $createur;
        return $this;
    }

    public function getUpdatedBy(): ?Utilisateur
    {
        return $this->updatedBy;
    }
    public function setUpdatedBy(?Utilisateur $updatedBy): static
    {
        $this->updatedBy = $updatedBy;
        return $this;
    }

    public function getContratFormateurConditionsGeneralesDefault(): ?string
    {
        return $this->contratFormateurConditionsGeneralesDefault;
    }
    public function setContratFormateurConditionsGeneralesDefault(?string $v): static
    {
        $this->contratFormateurConditionsGeneralesDefault = $v;
        return $this;
    }

    public function getContratFormateurConditionsParticulieresDefault(): ?string
    {
        return $this->contratFormateurConditionsParticulieresDefault;
    }
    public function setContratFormateurConditionsParticulieresDefault(?string $v): static
    {
        $this->contratFormateurConditionsParticulieresDefault = $v;
        return $this;
    }

    public function getContratFormateurClauseEngagementDefault(): ?string
    {
        return $this->contratFormateurClauseEngagementDefault;
    }
    public function setContratFormateurClauseEngagementDefault(?string $v): static
    {
        $this->contratFormateurClauseEngagementDefault = $v;
        return $this;
    }

    public function getContratFormateurClauseObjetDefault(): ?string
    {
        return $this->contratFormateurClauseObjetDefault;
    }
    public function setContratFormateurClauseObjetDefault(?string $v): static
    {
        $this->contratFormateurClauseObjetDefault = $v;
        return $this;
    }

    public function getContratFormateurClauseEmploiDefault(): ?string
    {
        return $this->contratFormateurClauseEmploiDefault;
    }
    public function setContratFormateurClauseEmploiDefault(?string $v): static
    {
        $this->contratFormateurClauseEmploiDefault = $v;
        return $this;
    }

    public function getContratFormateurClauseObligationsDefault(): ?string
    {
        return $this->contratFormateurClauseObligationsDefault;
    }
    public function setContratFormateurClauseObligationsDefault(?string $v): static
    {
        $this->contratFormateurClauseObligationsDefault = $v;
        return $this;
    }

    public function getContratFormateurClauseNonConcurrenceDefault(): ?string
    {
        return $this->contratFormateurClauseNonConcurrenceDefault;
    }
    public function setContratFormateurClauseNonConcurrenceDefault(?string $v): static
    {
        $this->contratFormateurClauseNonConcurrenceDefault = $v;
        return $this;
    }

    public function getContratFormateurClauseInexecutionDefault(): ?string
    {
        return $this->contratFormateurClauseInexecutionDefault;
    }
    public function setContratFormateurClauseInexecutionDefault(?string $v): static
    {
        $this->contratFormateurClauseInexecutionDefault = $v;
        return $this;
    }

    public function getContratFormateurClauseAssuranceDefault(): ?string
    {
        return $this->contratFormateurClauseAssuranceDefault;
    }
    public function setContratFormateurClauseAssuranceDefault(?string $v): static
    {
        $this->contratFormateurClauseAssuranceDefault = $v;
        return $this;
    }

    public function getContratFormateurClauseFinContratDefault(): ?string
    {
        return $this->contratFormateurClauseFinContratDefault;
    }
    public function setContratFormateurClauseFinContratDefault(?string $v): static
    {
        $this->contratFormateurClauseFinContratDefault = $v;
        return $this;
    }

    public function getContratFormateurClauseProprieteIntellectuelleDefault(): ?string
    {
        return $this->contratFormateurClauseProprieteIntellectuelleDefault;
    }
    public function setContratFormateurClauseProprieteIntellectuelleDefault(?string $v): static
    {
        $this->contratFormateurClauseProprieteIntellectuelleDefault = $v;
        return $this;
    }

    public function getSignatureOrganismePath(): ?string
    {
        return $this->signatureOrganismePath;
    }
    public function setSignatureOrganismePath(?string $v): static
    {
        $this->signatureOrganismePath = $v;
        return $this;
    }

    public function getSignatureOrganismeAt(): ?\DateTimeImmutable
    {
        return $this->signatureOrganismeAt;
    }
    public function setSignatureOrganismeAt(?\DateTimeImmutable $v): static
    {
        $this->signatureOrganismeAt = $v;
        return $this;
    }

    public function getSignatureOrganismeIp(): ?string
    {
        return $this->signatureOrganismeIp;
    }
    public function setSignatureOrganismeIp(?string $v): static
    {
        $this->signatureOrganismeIp = $v;
        return $this;
    }

    public function getSignatureOrganismeUserAgent(): ?string
    {
        return $this->signatureOrganismeUserAgent;
    }
    public function setSignatureOrganismeUserAgent(?string $v): static
    {
        $this->signatureOrganismeUserAgent = $v;
        return $this;
    }

    public function getSignatureOrganismeNom(): ?string
    {
        return $this->signatureOrganismeNom;
    }
    public function setSignatureOrganismeNom(?string $v): static
    {
        $this->signatureOrganismeNom = $v;
        return $this;
    }

    public function getSignatureOrganismeFonction(): ?string
    {
        return $this->signatureOrganismeFonction;
    }
    public function setSignatureOrganismeFonction(?string $v): static
    {
        $this->signatureOrganismeFonction = $v;
        return $this;
    }

    public function getSignatureOrganismePar(): ?Utilisateur
    {
        return $this->signatureOrganismePar;
    }
    public function setSignatureOrganismePar(?Utilisateur $v): static
    {
        $this->signatureOrganismePar = $v;
        return $this;
    }

    public function getSignatureOrganismeSha256(): ?string
    {
        return $this->signatureOrganismeSha256;
    }
    public function setSignatureOrganismeSha256(?string $v): static
    {
        $this->signatureOrganismeSha256 = $v;
        return $this;
    }
}
