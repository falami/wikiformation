<?php
declare(strict_types=1);
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_charter_signer', columns: ['version_id', 'trainer_id'])]
class TrainerCharterSignature
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] public ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] public TrainerCharterVersion $version;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] public Formateur $trainer;
    #[ORM\Column] public \DateTimeImmutable $requestedAt;
    #[ORM\Column(nullable: true)] public ?\DateTimeImmutable $signedAt = null;
    #[ORM\Column(length: 255)] public string $requestedBy = '';
    #[ORM\Column(type: 'json')] public array $evidence = [];
    #[ORM\Column(type: 'blob', nullable: true)] public mixed $signedPdf = null;
    #[ORM\Column(length: 64, nullable: true)] public ?string $pdfDigest = null;
    #[ORM\Version, ORM\Column(type: 'integer')] public int $revision = 1;
    public function __construct() { $this->requestedAt = new \DateTimeImmutable(); }
    public function getId(): ?int { return $this->id; }
    public function pdfBytes(): string { if (is_resource($this->signedPdf)) { rewind($this->signedPdf); return stream_get_contents($this->signedPdf); } return $this->signedPdf ?? ''; }
}
