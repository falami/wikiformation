<?php
namespace App\Entity\Marketplace;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity]
#[ORM\Table(name: "marketplace_offer")]
class Offer
{
    #[ORM\Id] #[ORM\GeneratedValue] #[ORM\Column]
    public ?int $id = null;
    #[ORM\OneToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    public ?\App\Entity\Elearning\ElearningCourse $course = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    public ?Seller $seller = null;
    #[ORM\Column]
    public bool $membersVisible = false;
    #[ORM\Column]
    public bool $publicVisible = false;
    #[ORM\Column]
    public bool $published = false;
    #[ORM\Column]
    public int $priceCents = 0;
    #[ORM\Column]
    public int $taxBps = 0;
    #[ORM\Column(type: 'json')]
    public array $snapshot = [];
    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $publishedAt = null;
}
