<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\{ArrayCollection, Collection};

/** Separate from production PRE/POST questionnaires; dossier snapshots preserve past versions. */
#[ORM\Entity]
#[ORM\Table(name: 'evaluation_template')]
#[ORM\Index(name: 'idx_evaluation_template_tenant', columns: ['entite_id', 'active'])]
class EvaluationTemplate
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Entite $entite = null;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\Column(length: 180)]
    private string $title = '';

    #[ORM\Column(name: 'schema_definition', type: 'json')]
    private array $schema = [];

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(options: ['default' => 1])]
    private int $version = 1;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Utilisateur $trainerOwner = null;

    #[ORM\ManyToOne(targetEntity: self::class), ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?self $sourceTemplate = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $assignedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    public function getTrainerOwner(): ?Utilisateur { return $this->trainerOwner; }
    public function setTrainerOwner(?Utilisateur $owner): static { $this->trainerOwner = $owner; return $this; }
    public function getSourceTemplate(): ?self { return $this->sourceTemplate; }
    public function setSourceTemplate(self $source): static { $this->sourceTemplate = $source; return $this; }
    public function getAssignedAt(): ?\DateTimeImmutable { return $this->assignedAt; }
    public function markAssigned(): void { $this->assignedAt ??= new \DateTimeImmutable(); }
    public function getArchivedAt(): ?\DateTimeImmutable { return $this->archivedAt; }
    public function archive(): void { $this->archivedAt ??= new \DateTimeImmutable(); $this->active = false; }
    public function getOwnerLabel(): string { return $this->trainerOwner ? trim($this->trainerOwner->getPrenom().' '.$this->trainerOwner->getNom()) : ($this->entite?->getNom() ?? 'Organisme'); }

    #[ORM\ManyToMany(targetEntity: Entite::class)]
    #[ORM\JoinTable(name: 'evaluation_template_authorized_entite')]
    #[ORM\JoinColumn(name: 'evaluation_template_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'entite_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $authorizedEntites;

    public function getAuthorizedEntites(): Collection { return $this->authorizedEntites; }
    public function authorizes(Entite $entite): bool {
        foreach ($this->authorizedEntites as $allowed) {
            if ($allowed === $entite || ($entite->getId() !== null && $allowed->getId() === $entite->getId())) return true;
        }
        return false;
    }
    public function setAuthorizedEntites(array $entites): void {
        $this->authorizedEntites->clear();
        foreach ($entites as $entite) if (!$this->authorizedEntites->contains($entite)) $this->authorizedEntites->add($entite);
    }

    public function __construct() { $this->authorizedEntites = new ArrayCollection(); $this->createdAt = new \DateTimeImmutable(); }
    public function getId(): ?int { return $this->id; }
    public function getEntite(): ?Entite { return $this->entite; }
    public function setEntite(Entite $value): static { $this->entite = $value; return $this; }
    public function getCreateur(): ?Utilisateur { return $this->createur; }
    public function setCreateur(Utilisateur $value): static { $this->createur = $value; return $this; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $value): static { $this->title = trim($value); return $this; }
    public function getSchema(): array { return $this->schema; }
    public function setSchema(array $value): static { $this->schema = $value; return $this; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $value): static { $this->active = $value; return $this; }
    public function getVersion(): int { return $this->version; }
    public function setVersion(int $value): static
    {
        if ($value < 1) throw new \InvalidArgumentException('La version doit être positive.');
        $this->version = $value;
        return $this;
    }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
