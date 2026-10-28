<?php
declare(strict_types=1);
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'einvoice_connection')]
#[ORM\UniqueConstraint(name: 'uniq_einvoice_tenant_mode', columns: ['entite_id', 'environment'])]
#[ORM\UniqueConstraint(name: 'uniq_einvoice_remote_company', columns: ['environment', 'company_id'])]
class EInvoiceConnection
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private Entite $entite;
    #[ORM\Column(length: 16)] private string $environment;
    #[ORM\Column(type: 'text', nullable: true)] private ?string $credentials = null;
    #[ORM\Column(length: 40, nullable: true)] private ?string $companyId = null;
    #[ORM\Column(length: 100, nullable: true)] private ?string $companyNumber = null;
    #[ORM\Column(length: 255, nullable: true)] private ?string $companyName = null;
    #[ORM\Column] private bool $verified = false;
    #[ORM\Column] private bool $automatic = false;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $enabledAt = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $connectedAt = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $lastSyncAt = null;
    #[ORM\Column(length: 255, nullable: true)] private ?string $lastError = null;
    #[ORM\Column(length: 40, nullable: true)] private ?string $incomingCursor = null;
    #[ORM\Column(nullable: true)] private ?int $discoveryCursor = null;
    #[ORM\Column(length: 40, nullable: true)] private ?string $outgoingCursor = null;
    #[ORM\Column(length: 64, nullable: true)] private ?string $workerToken = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $workerUntil = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $billingConsentAt = null;
    #[ORM\Column(nullable: true)] private ?int $billingConsentBy = null;
    #[ORM\Column(nullable: true)] private ?int $unitPriceCents = null;
    #[ORM\Version, ORM\Column(type: 'integer')] private int $version = 1;

    public function __construct(Entite $entite, string $environment)
    {
        if (!in_array($environment, ['sandbox', 'production'], true)) throw new \InvalidArgumentException('Environnement invalide.');
        $this->entite = $entite; $this->environment = $environment;
    }
    public function getId(): ?int { return $this->id; }
    public function getEntite(): Entite { return $this->entite; }
    public function getEnvironment(): string { return $this->environment; }
    public function getCredentials(): ?string { return $this->credentials; }
    public function setCredentials(string $value): void { $this->credentials = $value; }
    public function getCompanyId(): ?string { return $this->companyId; }
    public function getCompanyNumber(): ?string { return $this->companyNumber; }
    public function getCompanyName(): ?string { return $this->companyName; }
    public function isVerified(): bool { return $this->verified; }
    public function isAutomatic(): bool { return $this->automatic; }
    public function getEnabledAt(): ?\DateTimeImmutable { return $this->enabledAt; }
    public function getConnectedAt(): ?\DateTimeImmutable { return $this->connectedAt; }
    public function getLastSyncAt(): ?\DateTimeImmutable { return $this->lastSyncAt; }
    public function getLastError(): ?string { return $this->lastError; }
    public function getIncomingCursor(): ?string { return $this->incomingCursor; }
    public function getDiscoveryCursor(): ?int { return $this->discoveryCursor; }
    public function discovered(int $id): void { $this->discoveryCursor = max($id, $this->discoveryCursor ?? 0); }
    public function getOutgoingCursor(): ?string { return $this->outgoingCursor; }
    public function reconciledThrough(string $id): void { $this->outgoingCursor = $id; }
    public function getBillingConsentAt(): ?\DateTimeImmutable { return $this->billingConsentAt; }
    public function getBillingConsentBy(): ?int { return $this->billingConsentBy; }
    public function getUnitPriceCents(): ?int { return $this->unitPriceCents === null ? null : min($this->unitPriceCents, \App\Service\EInvoicing\EInvoiceTariff::unitPriceCents($this->entite)); }
    public function hasCurrentBillingConsent(): bool
    {
        return $this->billingConsentAt !== null && $this->billingConsentBy !== null
            && $this->getUnitPriceCents() === \App\Service\EInvoicing\EInvoiceTariff::unitPriceCents($this->entite);
    }
    public function consentToBilling(int $actor): void
    {
        if ($this->environment !== 'production') throw new \DomainException('La facturation concerne uniquement la production.');
        $this->billingConsentAt = new \DateTimeImmutable(); $this->billingConsentBy = $actor; $this->unitPriceCents = \App\Service\EInvoicing\EInvoiceTariff::unitPriceCents($this->entite);
    }
    public function connected(array $company, string $encrypted): void
    {
        if ($this->companyId !== null && $this->companyId !== (string) $company['id']) throw new \DomainException('Le compte distant doit rester celui de cet organisme.');
        $this->credentials = $encrypted; $this->companyId = (string) $company['id'];
        $this->companyNumber = (string) $company['number']; $this->companyName = (string) $company['formal_name'];
        $this->verified = true; $this->connectedAt = new \DateTimeImmutable(); $this->lastError = null;
    }
    public function setAutomatic(bool $value): void
    {
        if ($value && (!$this->verified || !$this->credentials)) throw new \DomainException('Connectez et vérifiez d’abord votre organisme.');
        if ($value && ($this->environment !== 'production' || !$this->hasCurrentBillingConsent())) throw new \DomainException('Acceptez le tarif actuel de transmission avant activation.');
        if ($value && $this->enabledAt === null) $this->enabledAt = new \DateTimeImmutable();
        $this->automatic = $value;
    }
    public function disconnect(): void { $this->automatic = false; $this->verified = false; $this->credentials = null; }
    public function synchronized(?string $cursor = null): void
    {
        $this->lastSyncAt = new \DateTimeImmutable(); $this->lastError = null;
        if ($cursor !== null) $this->incomingCursor = $cursor;
    }
    public function failed(string $safeMessage): void { $this->lastError = mb_substr($safeMessage, 0, 255); }
}
