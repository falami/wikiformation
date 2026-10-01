<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_document_formateur_numero', columns: ['document_id', 'numero'])]
class DocumentFormateurVersion
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\ManyToOne(inversedBy: 'versions'), ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?DocumentFormateur $document = null;
    #[ORM\Column]
    private int $numero = 1;
    #[ORM\Column(length: 100)]
    private string $filename = '';
    #[ORM\Column(length: 255)]
    private string $originalName = '';
    #[ORM\Column(length: 150)]
    private string $mimeType = '';
    #[ORM\Column]
    private int $sizeBytes = 0;
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $note = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $uploadedBy = null;
    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct() { $this->createdAt = new \DateTimeImmutable(); }
    public function getId(): ?int { return $this->id; }
    public function getDocument(): ?DocumentFormateur { return $this->document; }
    public function setDocument(DocumentFormateur $document): static { $this->document = $document; return $this; }
    public function getNumero(): int { return $this->numero; }
    public function setNumero(int $numero): static { $this->numero = $numero; return $this; }
    public function getFilename(): string { return $this->filename; }
    public function setFilename(string $filename): static { $this->filename = $filename; return $this; }
    public function getOriginalName(): string { return $this->originalName; }
    public function setOriginalName(string $name): static { $this->originalName = $name; return $this; }
    public function getMimeType(): string { return $this->mimeType; }
    public function setMimeType(string $mime): static { $this->mimeType = $mime; return $this; }
    public function getSizeBytes(): int { return $this->sizeBytes; }
    public function setSizeBytes(int $size): static { $this->sizeBytes = $size; return $this; }
    public function getNote(): ?string { return $this->note; }
    public function setNote(?string $note): static { $this->note = $note; return $this; }
    public function getUploadedBy(): ?Utilisateur { return $this->uploadedBy; }
    public function setUploadedBy(Utilisateur $user): static { $this->uploadedBy = $user; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getExtension(): string { return strtoupper(pathinfo($this->originalName, PATHINFO_EXTENSION)); }
}
