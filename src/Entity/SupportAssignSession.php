<?php

namespace App\Entity;

use App\Repository\SupportAssignSessionRepository;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: SupportAssignSessionRepository::class)]
#[ORM\Table(name: 'support_assign_session')]
#[ORM\UniqueConstraint(name: 'uniq_asset_session', columns: ['asset_id', 'session_id'])]
class SupportAssignSession
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'supportAssignSessions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?SupportAsset $asset = null;

    #[ORM\ManyToOne(inversedBy: 'supportAssignSessions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Session $session = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isVisibleToTrainee = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $visibleFrom = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $visibleUntil = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'supportAssignSessionCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\ManyToOne(inversedBy: 'supportAssignSessionEntites')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Entite $entite = null;

    public function __construct()
    {
        $this->dateCreation = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAsset(): ?SupportAsset
    {
        return $this->asset;
    }

    public function setAsset(?SupportAsset $asset): static
    {
        $this->asset = $asset;

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

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function isVisibleToTrainee(): bool
    {
        return $this->isVisibleToTrainee;
    }

    public function setIsVisibleToTrainee(bool $isVisibleToTrainee): static
    {
        $this->isVisibleToTrainee = $isVisibleToTrainee;

        return $this;
    }

    public function getVisibleFrom(): ?\DateTimeImmutable
    {
        return $this->visibleFrom;
    }

    public function setVisibleFrom(?\DateTimeImmutable $visibleFrom): static
    {
        $this->visibleFrom = $visibleFrom;

        return $this;
    }

    public function getVisibleUntil(): ?\DateTimeImmutable
    {
        return $this->visibleUntil;
    }

    public function setVisibleUntil(?\DateTimeImmutable $visibleUntil): static
    {
        $this->visibleUntil = $visibleUntil;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

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

    public function getEntite(): ?Entite
    {
        return $this->entite;
    }

    public function setEntite(?Entite $entite): static
    {
        $this->entite = $entite;

        return $this;
    }
}
