<?php
declare(strict_types=1);
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity]
class TrainerCharter
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] public ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] public Entite $entite;
    #[ORM\Column(length: 180)] public string $title = 'Charte qualité des formateurs';
    #[ORM\Column] public \DateTimeImmutable $createdAt;
    public function __construct() { $this->createdAt = new \DateTimeImmutable(); }
    public function getId(): ?int { return $this->id; }
}
