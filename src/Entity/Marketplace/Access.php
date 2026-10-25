<?php
namespace App\Entity\Marketplace;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity]
#[ORM\Table(name: "marketplace_access")]
#[ORM\UniqueConstraint(name: "uniq_marketplace_access", columns: ["buyer_id", "course_id"])]
class Access
{
    #[ORM\Id] #[ORM\GeneratedValue] #[ORM\Column]
    public ?int $id = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    public ?\App\Entity\Utilisateur $buyer = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    public ?\App\Entity\Elearning\ElearningCourse $course = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    public ?Purchase $purchase = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?\App\Entity\Utilisateur $assignedBy = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    public ?\App\Entity\Entite $entite = null;
    #[ORM\Column(type: 'json')]
    public array $snapshot = [];
    #[ORM\Column(type: 'json')]
    public array $completed = [];
    #[ORM\Column]
    public bool $suspended = false;
    #[ORM\Column]
    public ?\DateTimeImmutable $createdAt = null;
}
