<?php
declare(strict_types=1);
namespace App\Entity\Platform;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity, ORM\Table(name: 'platform_cost')]
class Cost
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] public ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')] public ?\App\Entity\Entite $entite = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] public \App\Entity\Utilisateur $author;
    #[ORM\Column] public \DateTimeImmutable $date;
    #[ORM\Column] public \DateTimeImmutable $createdAt;
    #[ORM\Column(length: 30, options: ['default'=>'shared'])] public string $activity = 'shared';
    #[ORM\Column(length: 30)] public string $category = 'other';
    #[ORM\Column(length: 255)] public string $label = '';
    #[ORM\Column(length: 255)] public string $reference = '';
    #[ORM\Column] public int $ht = 0;
    #[ORM\Column] public int $ttc = 0;
    #[ORM\Column] public bool $estimated = false;
    #[ORM\Column] public bool $archived = false;
    public function __construct() { $this->createdAt = new \DateTimeImmutable(); }
}
