<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class ConventionRevision
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\Column(type: 'blob')]
    private $pdf;
    public function __construct(
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private ConventionContrat $convention,
        #[ORM\Column(type: 'json')] private array $donnees,
        #[ORM\Column(length: 255)] private string $motif,
        #[ORM\Column(length: 255)] private string $auteur,
        string $pdf,
        #[ORM\Column] private \DateTimeImmutable $date = new \DateTimeImmutable(),
    ) { $this->pdf = $pdf; }
    public function getId(): ?int { return $this->id; }
    public function getConvention(): ConventionContrat { return $this->convention; }
    public function getDonnees(): array { return $this->donnees; }
    public function getMotif(): string { return $this->motif; }
    public function getAuteur(): string { return $this->auteur; }
    public function getDate(): \DateTimeImmutable { return $this->date; }
    public function getPdf(): string { if (is_resource($this->pdf)) { rewind($this->pdf); return stream_get_contents($this->pdf); } return $this->pdf; }
}
