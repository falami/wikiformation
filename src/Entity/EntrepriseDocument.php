<?php

namespace App\Entity;

use App\Repository\EntrepriseDocumentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Enum\EntrepriseDocType;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: EntrepriseDocumentRepository::class)]
#[ORM\Index(columns: ['entite_id', 'entreprise_id'], name: 'idx_ed_entite_entreprise')]
#[ORM\Index(columns: ['session_id'], name: 'idx_ed_session')]
class EntrepriseDocument
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'entrepriseDocuments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Entite $entite = null;

    #[ORM\ManyToOne(inversedBy: 'entrepriseDocuments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Entreprise $entreprise = null;

    #[ORM\ManyToOne(inversedBy: 'entrepriseDocuments')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Session $session = null;

    #[ORM\ManyToOne(inversedBy: 'entrepriseDocuments')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Inscription $inscription = null;

    #[ORM\Column(enumType: EntrepriseDocType::class)]
    private EntrepriseDocType $type = EntrepriseDocType::AUTRE;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    private string $titre = '';

    #[ORM\Column(length: 255)]
    private string $filename = '';

    #[ORM\Column(length: 255)]
    private string $originalName = '';

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $mimeType = null;

    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    private int $size = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $signatureDataUrlEntreprise = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $signedAtEntreprise = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $valide = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $commentaireControle = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $uploadedAt;

    #[ORM\ManyToOne(inversedBy: 'entrepriseDocuments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $uploadedBy = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'entrepriseDocumentCreateurs')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Utilisateur $createur = null;


    public function __construct()
    {
        $this->uploadedAt = new \DateTimeImmutable();
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

    public function getEntreprise(): ?Entreprise
    {
        return $this->entreprise;
    }

    public function setEntreprise(?Entreprise $entreprise): static
    {
        $this->entreprise = $entreprise;

        return $this;
    }

    public function getSession(): ?Session
    {
        return $this->session;
    }

    public function setSession(?Session $session): static
    {
        $this->session = $session;

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


    public function getType(): EntrepriseDocType
    {
        return $this->type;
    }
    public function setType(EntrepriseDocType $t): self
    {
        $this->type = $t;
        return $this;
    }


    public function getTitre(): string
    {
        return $this->titre;
    }

    public function setTitre(string $titre): static
    {
        $this->titre = $titre;

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

    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    public function setOriginalName(string $originalName): static
    {
        $this->originalName = $originalName;

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

    public function getSize(): int
    {
        return $this->size;
    }

    public function setSize(int $size): static
    {
        $this->size = $size;

        return $this;
    }

    public function getSignatureDataUrlEntreprise(): ?string
    {
        return $this->signatureDataUrlEntreprise;
    }

    public function setSignatureDataUrlEntreprise(?string $signatureDataUrlEntreprise): static
    {
        $this->signatureDataUrlEntreprise = $signatureDataUrlEntreprise;

        return $this;
    }

    public function getSignedAtEntreprise(): ?\DateTimeImmutable
    {
        return $this->signedAtEntreprise;
    }

    public function setSignedAtEntreprise(?\DateTimeImmutable $signedAtEntreprise): static
    {
        $this->signedAtEntreprise = $signedAtEntreprise;

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

    public function getUploadedAt(): \DateTimeImmutable
    {
        return $this->uploadedAt;
    }

    public function setUploadedAt(?\DateTimeImmutable $uploadedAt): static
    {
        $this->uploadedAt = $uploadedAt;

        return $this;
    }

    public function getUploadedBy(): ?Utilisateur
    {
        return $this->uploadedBy;
    }

    public function setUploadedBy(?Utilisateur $uploadedBy): static
    {
        $this->uploadedBy = $uploadedBy;

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
