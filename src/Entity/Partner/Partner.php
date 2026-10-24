<?php

declare (strict_types=1);
namespace App\Entity\Partner;

use App\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity, ORM\Table(name: 'affiliate_partner')]
class Partner
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;
    #[ORM\OneToOne, ORM\JoinColumn(nullable: false)]
    public Utilisateur $user;
    #[ORM\Column(length: 100)]
    public string $name = '';
    #[ORM\Column(length: 40, unique: true)]
    public string $code = '';
    #[ORM\Column]
    public bool $active = true;
    #[ORM\Column]
    public bool $automaticPayouts = false;
    #[ORM\Column(length: 100, nullable: true, unique: true)]
    public ?string $stripeAccountId = null;
    #[ORM\Column]
    public bool $payoutsEnabled = false;
    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $createdAt;
    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }
}
