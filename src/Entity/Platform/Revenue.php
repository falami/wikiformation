<?php
declare(strict_types=1);
namespace App\Entity\Platform;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity, ORM\Table(name: 'platform_revenue')]
class Revenue
{
    #[ORM\Id, ORM\Column(length: 100)] public string $id;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] public \App\Entity\Entite $entite;
    #[ORM\Column(length: 3)] public string $currency = 'eur';
    #[ORM\Column] public bool $live = false;
    #[ORM\Column] public \DateTimeImmutable $paidAt;
    #[ORM\Column] public \DateTimeImmutable $syncedAt;
    #[ORM\Column(nullable: true)] public ?int $ht = null;
    #[ORM\Column] public int $ttc = 0;
    #[ORM\Column] public int $paid = 0;
    #[ORM\Column] public int $refund = 0;
    #[ORM\Column(nullable: true)] public ?int $fee = null;
    #[ORM\Column] public bool $review = false;
    #[ORM\Column(length: 255)] public string $label = '';
}
