<?php
declare(strict_types=1);
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_charter_version', columns: ['charter_id', 'number'])]
class TrainerCharterVersion
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] public ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] public TrainerCharter $charter;
    #[ORM\Column] public int $number = 1;
    #[ORM\Version, ORM\Column(type: 'integer')] public int $revision = 1;
    #[ORM\Column(length: 180)] public string $title = '';
    #[ORM\Column(type: 'json')] public array $chapters = [];
    #[ORM\Column(type: 'json')] public array $issuer = [];
    #[ORM\Column(length: 1000)] public string $changeNote = '';
    #[ORM\Column(type: 'date_immutable', nullable: true)] public ?\DateTimeImmutable $effectiveAt = null;
    #[ORM\Column(nullable: true)] public ?\DateTimeImmutable $publishedAt = null;
    #[ORM\Column(length: 64, nullable: true)] public ?string $digest = null;
    #[ORM\Column(length: 255)] public string $authorName = '';
    public function getId(): ?int { return $this->id; }
    public function payload(): array { return ['title'=>$this->title,'number'=>$this->number,'effectiveAt'=>$this->effectiveAt?->format('Y-m-d'),'issuer'=>$this->issuer,'chapters'=>$this->chapters]; }
    public function hash(): string { return hash('sha256', json_encode($this->payload(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)); }
}
