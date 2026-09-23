<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** An immutable snapshot of the version superseded by an explicit administrator change. */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_contrat_revision', columns: ['contrat_id', 'numero'])]
class ContratFormateurRevision
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private ContratFormateur $contrat,
        #[ORM\Column]
        private int $numero,
        #[ORM\Column(type: 'json')]
        private array $donnees,
        #[ORM\Column(length: 255)]
        private string $pdfFilename,
        #[ORM\Column(length: 64)]
        private string $pdfSha256,
        #[ORM\Column(length: 1000)]
        private string $motif,
        #[ORM\Column(length: 255)]
        private string $auteurNom,
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?Utilisateur $auteur,
        #[ORM\Column]
        private \DateTimeImmutable $archiveLe = new \DateTimeImmutable(),
    ) {}

    public function getId(): ?int { return $this->id; }
    public function getContrat(): ContratFormateur { return $this->contrat; }
    public function getNumero(): int { return $this->numero; }
    public function getDonnees(): array { return $this->donnees; }
    public function getPdfFilename(): string { return $this->pdfFilename; }
    public function getPdfSha256(): string { return $this->pdfSha256; }
    public function getMotif(): string { return $this->motif; }
    public function getAuteurNom(): string { return $this->auteurNom; }
    public function getAuteur(): ?Utilisateur { return $this->auteur; }
    public function getArchiveLe(): \DateTimeImmutable { return $this->archiveLe; }
}
