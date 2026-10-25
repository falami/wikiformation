<?php
namespace App\Entity\Marketplace;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity]
#[ORM\Table(name: "marketplace_settings")]
class Settings
{
    #[ORM\Id] #[ORM\Column]
    public int $id = 1;
    #[ORM\Column(nullable: true)]
    public ?int $commissionBps = null;
    #[ORM\Column(length: 12)]
    public string $feesPayer = 'seller';
    #[ORM\Column]
    public bool $enabled = false;
    #[ORM\Column]
    public bool $legalApproved = false;
    #[ORM\Column(type: 'text')]
    public string $terms = '';
    #[ORM\Column(type: 'text')]
    public string $privacy = '';
    #[ORM\Column(length: 40)]
    public string $termsVersion = '2026-10-25';
}
