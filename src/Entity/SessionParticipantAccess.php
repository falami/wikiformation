<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Identité de participation à une session, indépendante d'un compte de connexion. */
#[ORM\Entity]
#[ORM\Table(name: 'session_participant_access')]
#[ORM\UniqueConstraint(name: 'uniq_participant_access_source', columns: ['session_id', 'source_key'])]
class SessionParticipantAccess
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 48, unique: true)]
    private string $publicId;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Session $session = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Entite $entite = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Inscription $inscription = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ConventionContrat $convention = null;

    #[ORM\Column(length: 100)]
    private string $sourceKey = '';

    // Snapshot retained even if the convention name or enrolment is subsequently removed.
    #[ORM\Column(type: 'text')]
    private string $displayName = '';

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(options: ['default' => 1])]
    private int $version = 1;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->publicId = bin2hex(random_bytes(24));
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = $this->createdAt->modify('+90 days');
    }

    public function getId(): ?int { return $this->id; }
    public function getPublicId(): string { return $this->publicId; }
    public function getSession(): ?Session { return $this->session; }
    public function setSession(Session $session): static { $this->session = $session; return $this; }
    public function getEntite(): ?Entite { return $this->entite; }
    public function setEntite(Entite $entite): static { $this->entite = $entite; return $this; }
    public function getInscription(): ?Inscription { return $this->inscription; }
    public function setInscription(?Inscription $inscription): static { $this->inscription = $inscription; return $this; }
    public function getConvention(): ?ConventionContrat { return $this->convention; }
    public function setConvention(?ConventionContrat $convention): static { $this->convention = $convention; return $this; }
    public function getSourceKey(): string { return $this->sourceKey; }
    public function setSourceKey(string $sourceKey): static { $this->sourceKey = $sourceKey; return $this; }
    public function getDisplayName(): string { return $this->displayName; }
    public function setDisplayName(string $displayName): static { $this->displayName = $displayName; return $this; }
    public function isGuest(): bool { return str_starts_with($this->sourceKey, 'guest:'); }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): static
    {
        if ($this->active !== $active) ++$this->version;
        $this->active = $active;
        return $this;
    }
    public function getVersion(): int { return $this->version; }
    public function revoke(): static { ++$this->version; $this->active = false; return $this; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(\DateTimeImmutable $expiresAt): static { $this->expiresAt = $expiresAt; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
