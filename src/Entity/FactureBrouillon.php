<?php
declare(strict_types=1);

namespace App\Entity;

use App\Repository\FactureBrouillonRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

/** A working document, deliberately outside the issued invoice ledger. */
#[ORM\Entity(repositoryClass: FactureBrouillonRepository::class)]
#[ORM\Table(name: 'facture_brouillon')]
#[ORM\Index(name: 'idx_facture_brouillon_worklist', columns: ['entite_id', 'archived_at', 'facture_emise_id', 'updated_at'])]
class FactureBrouillon
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Entite $entite;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Utilisateur $createur;

    #[ORM\Column(length: 32, unique: true)]
    private string $reference;

    /** Raw form values, never an issued invoice or trusted monetary record. */
    #[ORM\Column(type: 'json')]
    private array $payload = [];

    /** Display-only summary; issuance always rebuilds and validates the payload. */
    #[ORM\Column(type: 'json')]
    private array $summary = [];

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $issuedAt = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Facture $factureEmise = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Devis $sourceDevis = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?ConventionContrat $sourceConvention = null;

    public function getSourceConvention(): ?ConventionContrat { return $this->sourceConvention; }

    public function setSourceConvention(ConventionContrat $convention): self
    {
        $this->assertEditable();
        if ($this->sourceConvention && $this->sourceConvention !== $convention) {
            throw new \DomainException('La convention d’origine ne peut pas être remplacée.');
        }
        if ($convention->getEntite()?->getId() !== $this->entite->getId()) {
            throw new \DomainException('La convention appartient à un autre organisme.');
        }
        if ($this->sourceDevis || $convention->getDevis()) {
            throw new \DomainException('Utilisez le brouillon du devis lié à cette convention.');
        }
        $this->sourceConvention = $convention;
        return $this;
    }

    /** Timestamped workflow events; no duplicated customer details or form secrets. */
    #[ORM\Column(type: 'json')]
    private array $history = [];

    public function __construct(Entite $entite, Utilisateur $createur)
    {
        $this->entite = $entite;
        $this->createur = $createur;
        $this->reference = 'BR-' . new Ulid();
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
        $this->record('created', $createur);
    }

    public function getId(): ?int { return $this->id; }
    public function getEntite(): Entite { return $this->entite; }
    public function getCreateur(): Utilisateur { return $this->createur; }
    public function getReference(): string { return $this->reference; }
    public function getPayload(): array { return $this->payload; }
    public function getSummary(): array { return $this->summary; }
    public function getVersion(): int { return $this->version; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function getArchivedAt(): ?\DateTimeImmutable { return $this->archivedAt; }
    public function getIssuedAt(): ?\DateTimeImmutable { return $this->issuedAt; }
    public function isArchived(): bool { return $this->archivedAt !== null; }
    public function getFactureEmise(): ?Facture { return $this->factureEmise; }
    public function getSourceDevis(): ?Devis { return $this->sourceDevis; }
    public function getHistory(): array { return $this->history; }

    public function setSourceDevis(Devis $devis): self
    {
        $this->assertEditable();
        if ($this->sourceDevis && $this->sourceDevis !== $devis) {
            throw new \DomainException('Le devis d’origine de ce brouillon ne peut pas être remplacé.');
        }
        if ($devis->getEntite()?->getId() !== $this->entite->getId()) {
            throw new \DomainException('Le devis appartient à un autre organisme.');
        }
        if ($this->sourceConvention) throw new \DomainException('Ce brouillon provient déjà d’une convention.');
        $this->sourceDevis = $devis;
        return $this;
    }

    public function savePayload(array $payload, array $summary, Utilisateur $actor): void
    {
        $this->assertEditable();
        $this->payload = $payload;
        $this->summary = $summary;
        $this->record('saved', $actor);
    }

    public function archive(Utilisateur $actor): void
    {
        $this->assertEditable();
        $this->archivedAt = new \DateTimeImmutable();
        $this->record('archived', $actor);
    }

    public function restore(Utilisateur $actor): void
    {
        if ($this->factureEmise) throw new \DomainException('Ce brouillon a déjà été émis. La facture définitive ne peut plus être modifiée.');
        if (!$this->isArchived()) return;
        $this->archivedAt = null;
        $this->record('restored', $actor);
    }

    public function markIssued(Facture $invoice, Utilisateur $actor): void
    {
        $this->assertEditable();
        if ($invoice->getEntite()?->getId() !== $this->entite->getId()) {
            throw new \DomainException('La facture appartient à un autre organisme.');
        }
        $this->factureEmise = $invoice;
        $this->issuedAt = new \DateTimeImmutable();
        $this->record('issued', $actor);
    }

    public function assertEditable(): void
    {
        if ($this->factureEmise) throw new \DomainException('Ce brouillon a déjà été émis. La facture définitive ne peut plus être modifiée.');
        if ($this->isArchived()) throw new \DomainException('Ce brouillon a été archivé.');
    }

    private function record(string $event, Utilisateur $actor): void
    {
        $this->updatedAt = new \DateTimeImmutable();
        $this->history[] = ['event' => $event, 'at' => $this->updatedAt->format(DATE_ATOM), 'actorId' => $actor->getId(), 'version' => $this->version];
    }
}
