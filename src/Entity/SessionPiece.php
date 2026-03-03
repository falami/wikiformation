<?php
// src/Entity/SessionPiece.php

namespace App\Entity;

use App\Repository\SessionPieceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Enum\SessionPieceType;

#[ORM\Entity(repositoryClass: SessionPieceRepository::class)]
#[ORM\Table(name: 'session_piece')]
#[ORM\Index(columns: ['session_id', 'type'], name: 'idx_session_piece_session_type')]
class SessionPiece
{
  #[ORM\Id]
  #[ORM\GeneratedValue]
  #[ORM\Column]
  private ?int $id = null;

  #[ORM\ManyToOne(inversedBy: 'pieces')]
  #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
  private ?Session $session = null;

  #[ORM\Column(enumType: SessionPieceType::class)]
  private ?SessionPieceType $type;

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

  #[ORM\Column]
  private ?\DateTimeImmutable $dateCreation = null;

  #[ORM\ManyToOne]
  #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
  private ?Utilisateur $createur = null;

  #[ORM\ManyToOne]
  #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
  private ?Entite $entite = null;

  public function __construct()
  {
    $now = new \DateTimeImmutable();
    $this->uploadedAt = $now;
    $this->dateCreation = $now;
  }

  public function getId(): ?int
  {
    return $this->id;
  }

  public function getSession(): ?Session
  {
    return $this->session;
  }
  public function setSession(?Session $session): self
  {
    $this->session = $session;
    return $this;
  }

  public function getType(): ?SessionPieceType
  {
    return $this->type;
  }
  public function setType(?SessionPieceType $type): self
  {
    $this->type = $type;
    return $this;
  }

  public function getFilename(): string
  {
    return $this->filename;
  }
  public function setFilename(string $filename): self
  {
    $this->filename = $filename;
    return $this;
  }

  public function getMimeType(): ?string
  {
    return $this->mimeType;
  }
  public function setMimeType(?string $mimeType): self
  {
    $this->mimeType = $mimeType;
    return $this;
  }

  public function getUploadedAt(): \DateTimeImmutable
  {
    return $this->uploadedAt;
  }
  public function setUploadedAt(\DateTimeImmutable $uploadedAt): self
  {
    $this->uploadedAt = $uploadedAt;
    return $this;
  }

  public function isValide(): bool
  {
    return $this->valide;
  }
  public function setValide(bool $valide): self
  {
    $this->valide = $valide;
    return $this;
  }

  public function getCommentaireControle(): ?string
  {
    return $this->commentaireControle;
  }
  public function setCommentaireControle(?string $commentaireControle): self
  {
    $this->commentaireControle = $commentaireControle;
    return $this;
  }

  public function getDateCreation(): ?\DateTimeImmutable
  {
    return $this->dateCreation;
  }
  public function setDateCreation(?\DateTimeImmutable $dateCreation): self
  {
    $this->dateCreation = $dateCreation;
    return $this;
  }

  public function getCreateur(): ?Utilisateur
  {
    return $this->createur;
  }
  public function setCreateur(?Utilisateur $createur): self
  {
    $this->createur = $createur;
    return $this;
  }

  public function getEntite(): ?Entite
  {
    return $this->entite;
  }
  public function setEntite(?Entite $entite): self
  {
    $this->entite = $entite;
    return $this;
  }
}
