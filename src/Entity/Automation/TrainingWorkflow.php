<?php

namespace App\Entity\Automation;

use App\Entity\{ConventionContrat, Entite, Facture, Session, Utilisateur};
use Doctrine\ORM\Mapping as ORM;

/** One administrative journey per convention, including sessions shared by several clients. */
#[ORM\Entity]
#[ORM\Table(name: 'training_workflow')]
class TrainingWorkflow
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private ?Entite $entite = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private ?Session $session = null;
    #[ORM\OneToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private ?ConventionContrat $convention = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)] private ?Utilisateur $createur = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')] private ?Facture $facture = null;
    #[ORM\Column(length: 180)] private string $contactEmail = '';
    #[ORM\Column] private bool $enabled = false;
    #[ORM\Column] private bool $renewalEnabled = false;
    #[ORM\Column(nullable: true)] private ?int $validityMonths = null;
    #[ORM\Column] private bool $renewalOptOut = false;
    #[ORM\Column(length: 64)] private string $portalNonce;
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    #[ORM\Column(type: 'json')] private array $options = [];

    public function __construct() { $this->createdAt = new \DateTimeImmutable(); $this->portalNonce = bin2hex(random_bytes(32)); }
    public function getId(): ?int { return $this->id; }
    public function getEntite(): ?Entite { return $this->entite; }
    public function setEntite(Entite $v): static { $this->entite = $v; return $this; }
    public function getSession(): ?Session { return $this->session; }
    public function setSession(Session $v): static { $this->session = $v; return $this; }
    public function getConvention(): ?ConventionContrat { return $this->convention; }
    public function setConvention(ConventionContrat $v): static { $this->convention = $v; return $this; }
    public function getCreateur(): ?Utilisateur { return $this->createur; }
    public function setCreateur(Utilisateur $v): static { $this->createur = $v; return $this; }
    public function getFacture(): ?Facture { return $this->facture; }
    public function setFacture(?Facture $v): static { $this->facture = $v; return $this; }
    public function getContactEmail(): string { return $this->contactEmail; }
    public function setContactEmail(string $v): static { $this->contactEmail = trim($v); return $this; }
    public function isEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $v): static { $this->enabled = $v; return $this; }
    public function isRenewalEnabled(): bool { return $this->renewalEnabled; }
    public function setRenewalEnabled(bool $v): static { $this->renewalEnabled = $v; return $this; }
    public function getValidityMonths(): ?int { return $this->validityMonths; }
    public function setValidityMonths(?int $v): static { if ($v !== null && ($v < 3 || $v > 120)) throw new \InvalidArgumentException('Validité : 3 à 120 mois.'); $this->validityMonths = $v; return $this; }
    public function isRenewalOptOut(): bool { return $this->renewalOptOut; }
    public function setRenewalOptOut(bool $v): static { $this->renewalOptOut = $v; return $this; }
    public function getPortalNonce(): string { return $this->portalNonce; }
    public function rotatePortalNonce(): void { $this->portalNonce = bin2hex(random_bytes(32)); }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getOptions(): array { return $this->options; }
    public function setOptions(array $v): static { $this->options = $v; return $this; }
}
