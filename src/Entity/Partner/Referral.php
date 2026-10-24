<?php

declare (strict_types=1);
namespace App\Entity\Partner;

use App\Entity\{Entite, Utilisateur};
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity, ORM\Table(name: 'affiliate_referral')]
class Referral
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)]
    public Partner $partner;
    #[ORM\OneToOne, ORM\JoinColumn(nullable: false)]
    public Entite $entite;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)]
    public Utilisateur $author;
    #[ORM\Column(length: 20)]
    public string $source = 'code';
    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $createdAt;
    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }
}
