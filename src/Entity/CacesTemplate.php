<?php

declare(strict_types=1);
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class CacesTemplate
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    public function getId(): ?int { return $this->id; }
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Entite $entite = null;
    public function getEntite(): ?Entite { return $this->entite; }
    public function setEntite(?Entite $value): static { $this->entite = $value; return $this; }
    #[ORM\Column(length: 180)]
    private string $title = '';
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $value): static { $this->title = $value; return $this; }
    #[ORM\Column(length: 3)]
    private string $recommendation = '489';
    public function getRecommendation(): string { return $this->recommendation; }
    public function setRecommendation(string $value): static { $this->recommendation = $value; return $this; }
    #[ORM\Column(length: 12)]
    private string $category = '';
    public function getCategory(): string { return $this->category; }
    public function setCategory(string $value): static { $this->category = $value; return $this; }
    #[ORM\Column(length: 12)]
    private string $kind = 'theory';
    public function getKind(): string { return $this->kind; }
    public function setKind(string $value): static { $this->kind = $value; return $this; }
    #[ORM\Column(length: 80)]
    private string $versionLabel = 'V1';
    public function getVersionLabel(): string { return $this->versionLabel; }
    public function setVersionLabel(string $value): static { $this->versionLabel = $value; return $this; }
    #[ORM\Column(length: 16)]
    private string $status = 'draft';
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $value): static { $this->status = $value; return $this; }
    #[ORM\Column(name: 'definition', type: 'json')]
    private array $schema = [];
    public function getSchema(): array { return $this->schema; }
    public function setSchema(array $value): static { $this->schema = $value; return $this; }
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $value): static { $this->createdAt = $value; return $this; }
    public function __construct() { $this->createdAt = new \DateTimeImmutable(); }
}
