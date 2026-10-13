<?php
declare(strict_types=1);
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Index(columns: ['entite_id', 'kind'])]
class DocumentTemplate
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Entite $entite;
    #[ORM\Column(length: 60)] private string $kind;
    #[ORM\Column(length: 160)] private string $name;
    #[ORM\Column(type: 'json')] private array $config = [];
    #[ORM\Column(type: 'json')] private array $history = [];
    #[ORM\Version, ORM\Column(type: 'integer')] private int $version = 1;
    #[ORM\Column] private \DateTimeImmutable $updatedAt;
    public function __construct(Entite $entite, string $kind, string $name, array $config) { $this->entite=$entite; $this->kind=$kind; $this->name=$name; $this->config=$config; $this->updatedAt=new \DateTimeImmutable(); }
    public function getId(): ?int { return $this->id; }
    public function getEntite(): Entite { return $this->entite; }
    public function getKind(): string { return $this->kind; }
    public function getName(): string { return $this->name; }
    public function getConfig(): array { return $this->config; }
    public function getHistory(): array { return $this->history; }
    public function getVersion(): int { return $this->version; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function save(string $name, array $config): void { $this->name=$name; $this->config=$config; $this->updatedAt=new \DateTimeImmutable(); }
    public function recordPublication(string $author): void {
        $this->history[]=['date'=>(new \DateTimeImmutable())->format(DATE_ATOM), 'author'=>$author, 'config'=>$this->config, 'name'=>$this->name];
        $this->updatedAt=new \DateTimeImmutable();
    }
}
