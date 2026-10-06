<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class HabilitationRevision
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    public function getId(): ?int { return $this->id; }

    #[ORM\ManyToOne(targetEntity: HabilitationDossier::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?HabilitationDossier $dossier = null;
    public function getDossier(): ?HabilitationDossier { return $this->dossier; }
    public function setDossier(?HabilitationDossier $value): self { $this->dossier = $value; return $this; }

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $actor = null;
    public function getActor(): ?Utilisateur { return $this->actor; }
    public function setActor(?Utilisateur $value): self { $this->actor = $value; return $this; }

    #[ORM\Column(length: 32, unique: true)]
    private string $token = '';
    public function getToken(): string { return $this->token; }
    public function setToken(string $value): self { $this->token = $value; return $this; }

    #[ORM\Column(length: 180)]
    private string $kind = '';
    public function getKind(): string { return $this->kind; }
    public function setKind(string $value): self { $this->kind = $value; return $this; }

    #[ORM\Column(type: 'json')]
    private array $snapshot = [];
    public function getSnapshot(): array { return $this->snapshot; }
    public function setSnapshot(array $value): self { $this->snapshot = $value; return $this; }

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $value): self { $this->createdAt = $value; return $this; }

    public function __construct() { $this->createdAt = new \DateTimeImmutable(); }
}
