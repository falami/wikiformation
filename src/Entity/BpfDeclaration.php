<?php

declare(strict_types=1);
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_bpf_period', columns: ['entite_id', 'start_date', 'end_date'])]
class BpfDeclaration
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Entite $entite;
    #[ORM\Column(type: 'date_immutable')] private \DateTimeImmutable $startDate;
    #[ORM\Column(type: 'date_immutable')] private \DateTimeImmutable $endDate;
    #[ORM\Column(type: 'json')] private array $data = [];
    #[ORM\Column(type: 'datetime_immutable')] private \DateTimeImmutable $updatedAt;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')] private ?Utilisateur $updatedBy = null;
    #[ORM\Version, ORM\Column(type: 'integer')] private int $version = 1;
    public function __construct(Entite $entite, \DateTimeImmutable $start, \DateTimeImmutable $end) { $this->entite = $entite; $this->startDate = $start; $this->endDate = $end; $this->updatedAt = new \DateTimeImmutable(); }
    public function getId(): ?int { return $this->id; }
    public function getData(): array { return $this->data; }
    public function getVersion(): int { return $this->version; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function getUpdatedBy(): ?Utilisateur { return $this->updatedBy; }
    public function getStartDate(): \DateTimeImmutable { return $this->startDate; }
    public function getEndDate(): \DateTimeImmutable { return $this->endDate; }
    public function save(array $data, Utilisateur $actor): void { $this->data = $data; $this->updatedBy = $actor; $this->updatedAt = new \DateTimeImmutable(); }
}
