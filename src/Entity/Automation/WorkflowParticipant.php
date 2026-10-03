<?php

declare(strict_types=1);

namespace App\Entity\Automation;

use App\Entity\Inscription;
use Doctrine\ORM\Mapping as ORM;

/** Information supplied by the customer; collection does not create an account. */
#[ORM\Entity]
#[ORM\Table(name: 'workflow_participant')]
#[ORM\UniqueConstraint(name: 'uniq_workflow_participant_position', columns: ['workflow_id', 'position'])]
class WorkflowParticipant
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private ?TrainingWorkflow $workflow = null;
    #[ORM\Column] private int $position = 0;
    #[ORM\Column(length: 100)] private string $prenom = '';
    #[ORM\Column(length: 100)] private string $nom = '';
    #[ORM\Column(length: 180, nullable: true)] private ?string $email = null;
    #[ORM\Column(type: 'date_immutable', nullable: true)] private ?\DateTimeImmutable $dateNaissance = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')] private ?Inscription $inscription = null;
    #[ORM\Column] private \DateTimeImmutable $updatedAt;

    public function __construct() { $this->updatedAt = new \DateTimeImmutable(); }
    public function getId(): ?int { return $this->id; }
    public function getWorkflow(): ?TrainingWorkflow { return $this->workflow; }
    public function setWorkflow(TrainingWorkflow $value): static { $this->workflow = $value; return $this; }
    public function getPosition(): int { return $this->position; }
    public function setPosition(int $value): static { $this->position = $value; return $this; }
    public function getPrenom(): string { return $this->prenom; }
    public function setPrenom(string $value): static { $this->prenom = trim($value); return $this; }
    public function getNom(): string { return $this->nom; }
    public function setNom(string $value): static { $this->nom = trim($value); return $this; }
    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $value): static { $this->email = $value ? mb_strtolower(trim($value)) : null; return $this; }
    public function getDateNaissance(): ?\DateTimeImmutable { return $this->dateNaissance; }
    public function setDateNaissance(?\DateTimeImmutable $value): static { $this->dateNaissance = $value; return $this; }
    public function getInscription(): ?Inscription { return $this->inscription; }
    public function setInscription(?Inscription $value): static { $this->inscription = $value; return $this; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }
}
