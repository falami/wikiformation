<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_habilitation_inscription', columns: ['inscription_id'])]
#[ORM\Index(columns: ['entite_id', 'state', 'active'])]
class HabilitationDossier
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    public function getId(): ?int { return $this->id; }

    #[ORM\ManyToOne(targetEntity: Entite::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Entite $entite = null;
    public function getEntite(): ?Entite { return $this->entite; }
    public function setEntite(?Entite $value): self { $this->entite = $value; return $this; }

    #[ORM\ManyToOne(targetEntity: Inscription::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Inscription $inscription = null;
    public function getInscription(): ?Inscription { return $this->inscription; }
    public function setInscription(?Inscription $value): self { $this->inscription = $value; return $this; }

    #[ORM\ManyToOne(targetEntity: HabilitationTemplate::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?HabilitationTemplate $template = null;
    public function getTemplate(): ?HabilitationTemplate { return $this->template; }
    public function setTemplate(?HabilitationTemplate $value): self { $this->template = $value; return $this; }

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $formateur = null;
    public function getFormateur(): ?Utilisateur { return $this->formateur; }
    public function setFormateur(?Utilisateur $value): self { $this->formateur = $value; return $this; }

    #[ORM\ManyToOne(targetEntity: Entreprise::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Entreprise $entreprise = null;
    public function getEntreprise(): ?Entreprise { return $this->entreprise; }
    public function setEntreprise(?Entreprise $value): self { $this->entreprise = $value; return $this; }

    #[ORM\Column(name: 'schema_definition', type: 'json')]
    private array $schema = [];
    public function getSchema(): array { return $this->schema; }
    public function setSchema(array $value): self { $this->schema = $value; return $this; }

    #[ORM\Column(type: 'json')]
    private array $trainerData = [];
    public function getTrainerData(): array { return $this->trainerData; }
    public function setTrainerData(array $value): self { $this->trainerData = $value; return $this; }

    #[ORM\Column(type: 'json')]
    private array $employerData = [];
    public function getEmployerData(): array { return $this->employerData; }
    public function setEmployerData(array $value): self { $this->employerData = $value; return $this; }

    #[ORM\Column(length: 180)]
    private string $state = 'draft';
    public function getState(): string { return $this->state; }
    public function setState(string $value): self { $this->state = $value; return $this; }

    #[ORM\Column]
    private bool $active = true;
    public function getActive(): bool { return $this->active; }
    public function setActive(bool $value): self { $this->active = $value; return $this; }

    #[ORM\Column]
    private bool $deleted = false;
    public function getDeleted(): bool { return $this->deleted; }
    public function setDeleted(bool $value): self { $this->deleted = $value; return $this; }

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $avisToken = null;
    public function getAvisToken(): ?string { return $this->avisToken; }
    public function setAvisToken(?string $value): self { $this->avisToken = $value; return $this; }

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $titreToken = null;
    public function getTitreToken(): ?string { return $this->titreToken; }
    public function setTitreToken(?string $value): self { $this->titreToken = $value; return $this; }

    #[ORM\Version, ORM\Column(type: 'integer')]
    private int $version = 1;
    public function getVersion(): int { return $this->version; }

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $value): self { $this->createdAt = $value; return $this; }

    public function __construct() { $this->createdAt = new \DateTimeImmutable(); }
}
