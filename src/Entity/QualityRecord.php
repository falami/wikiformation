<?php

declare(strict_types=1);
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity]
#[ORM\Index(name: 'quality_entity_status', columns: ['entite_id', 'status'])]
class QualityRecord
{
    public const KINDS = ['Réclamation' => 'complaint', 'Difficulté / aléa' => 'incident', 'Action d’amélioration / risque qualité' => 'improvement', 'Veille réglementaire' => 'legal_watch', 'Veille métier' => 'industry_watch', 'Veille pédagogique' => 'teaching_watch', 'Accessibilité / réseau handicap' => 'accessibility', 'Compétences des intervenants' => 'skills', 'Suivi de sous-traitance' => 'subcontracting'];
    public const STATUSES = ['À traiter' => 'open', 'En cours' => 'in_progress', 'Clôturé' => 'closed'];
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private ?Entite $entite = null;
    #[ORM\Column(length: 30), Assert\Choice(choices: ['complaint','incident','improvement','legal_watch','industry_watch','teaching_watch','accessibility','skills','subcontracting'])] private string $kind = 'improvement';
    #[ORM\Column(length: 180), Assert\NotBlank, Assert\Length(max: 180)] private string $title = '';
    #[ORM\Column(type: 'text'), Assert\NotBlank, Assert\Length(max: 12000)] private string $description = '';
    #[ORM\Column(type: 'date_immutable'), Assert\NotNull] private ?\DateTimeImmutable $occurredAt = null;
    #[ORM\Column(length: 180), Assert\NotBlank, Assert\Length(max: 180)] private string $owner = '';
    #[ORM\Column(type: 'date_immutable', nullable: true)] private ?\DateTimeImmutable $dueAt = null;
    #[ORM\Column(type: 'text', nullable: true), Assert\Length(max: 12000)] private ?string $actions = null;
    #[ORM\Column(type: 'text', nullable: true), Assert\Length(max: 8000)] private ?string $evidence = null;
    #[ORM\Column(type: 'text', nullable: true), Assert\Length(max: 12000)] private ?string $conclusion = null;
    #[ORM\Column(length: 20), Assert\Choice(choices: ['open','in_progress','closed'])] private string $status = 'open';
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    #[ORM\Column] private \DateTimeImmutable $updatedAt;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private ?Utilisateur $createdBy = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private ?Utilisateur $updatedBy = null;
    #[ORM\Version, ORM\Column(type: 'integer')] private int $version = 1;
    public function __construct() { $this->createdAt = $this->updatedAt = new \DateTimeImmutable(); $this->occurredAt = new \DateTimeImmutable('today'); }
    public function getId(): ?int { return $this->id; }
    public function getEntite(): ?Entite { return $this->entite; }
    public function setEntite(Entite $value): static { $this->entite = $value; return $this; }
    public function getKind(): string { return $this->kind; }
    public function setKind(string $value): static { $this->kind = $value; return $this; }
    public function getKindLabel(): string { return array_search($this->kind, self::KINDS, true) ?: $this->kind; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $value): static { $this->title = $value; return $this; }
    public function getDescription(): string { return $this->description; }
    public function setDescription(string $value): static { $this->description = $value; return $this; }
    public function getOccurredAt(): ?\DateTimeImmutable { return $this->occurredAt; }
    public function setOccurredAt(?\DateTimeImmutable $value): static { $this->occurredAt = $value; return $this; }
    public function getOwner(): string { return $this->owner; }
    public function setOwner(string $value): static { $this->owner = $value; return $this; }
    public function getDueAt(): ?\DateTimeImmutable { return $this->dueAt; }
    public function setDueAt(?\DateTimeImmutable $value): static { $this->dueAt = $value; return $this; }
    public function getActions(): ?string { return $this->actions; }
    public function setActions(?string $value): static { $this->actions = $value; return $this; }
    public function getEvidence(): ?string { return $this->evidence; }
    public function setEvidence(?string $value): static { $this->evidence = $value; return $this; }
    public function getConclusion(): ?string { return $this->conclusion; }
    public function setConclusion(?string $value): static { $this->conclusion = $value; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $value): static { $this->status = $value; return $this; }
    public function getStatusLabel(): string { return array_search($this->status, self::STATUSES, true) ?: $this->status; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function getCreatedBy(): ?Utilisateur { return $this->createdBy; }
    public function getUpdatedBy(): ?Utilisateur { return $this->updatedBy; }
    public function getVersion(): int { return $this->version; }
    public function recordEdit(Utilisateur $user): void { $this->createdBy ??= $user; $this->updatedBy = $user; $this->updatedAt = new \DateTimeImmutable(); }
    #[Assert\Callback]
    public function validateClosure(ExecutionContextInterface $context): void
    {
        if ($this->status !== 'closed') return;
        foreach (['actions' => $this->actions, 'evidence' => $this->evidence, 'conclusion' => $this->conclusion] as $field => $value) {
            if (!trim((string) $value)) $context->buildViolation('Pour clôturer, renseignez les actions, les références des preuves et la conclusion de leur efficacité.')->atPath($field)->addViolation();
        }
    }
}
