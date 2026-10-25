<?php
namespace App\Entity\Marketplace;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity]
#[ORM\Table(name: "marketplace_seller")]
class Seller
{
    #[ORM\Id] #[ORM\GeneratedValue] #[ORM\Column]
    public ?int $id = null;
    #[ORM\OneToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    public ?\App\Entity\Utilisateur $user = null;
    #[ORM\Column(length: 255)]
    public string $legalName = '';
    #[ORM\Column(length: 60)]
    public string $registration = '';
    #[ORM\Column(type: 'text')]
    public string $address = '';
    #[ORM\Column(length: 255)]
    public string $email = '';
    #[ORM\Column(length: 80)]
    public string $taxNumber = '';
    #[ORM\Column(length: 255)]
    public string $taxExemption = '';
    #[ORM\Column(length: 255, nullable: true, unique: true)]
    public ?string $accountId = null;
    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $termsAcceptedAt = null;
    #[ORM\Column(length: 40)]
    public string $termsVersion = '';
}
