<?php

declare(strict_types=1);
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class ConventionSignatureInvitation
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;
    #[ORM\Column] private int $companyId;
    #[ORM\Column] private int $tenantId;
    public function getCompanyId(): int { return $this->companyId; }
    public function getTenantId(): int { return $this->tenantId; }
    public function __construct(
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private ConventionContrat $convention,
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)]
        private Utilisateur $invitedBy,
        #[ORM\Column(length: 64, unique: true)] private string $tokenHash,
        #[ORM\Column(length: 180)] private string $email,
        #[ORM\Column(length: 160)] private string $name,
        #[ORM\Column(length: 120)] private string $position,
        #[ORM\Column(length: 64)] private string $documentHash,
        #[ORM\Column] private \DateTimeImmutable $expiresAt,
        #[ORM\Column] private \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) { $this->companyId = $convention->getEntreprise()->getId(); $this->tenantId = $convention->getEntite()->getId(); }
    public function getId(): ?int { return $this->id; }
    public function getConvention(): ConventionContrat { return $this->convention; }
    public function getInvitedBy(): Utilisateur { return $this->invitedBy; }
    public function getEmail(): string { return $this->email; }
    public function getName(): string { return $this->name; }
    public function getPosition(): string { return $this->position; }
    public function getDocumentHash(): string { return $this->documentHash; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function isActive(): bool { return !$this->usedAt && !$this->revokedAt && $this->expiresAt > new \DateTimeImmutable(); }
    public function revoke(): void { $this->revokedAt = new \DateTimeImmutable(); }
    public function consume(): void { $this->usedAt = new \DateTimeImmutable(); }
}
