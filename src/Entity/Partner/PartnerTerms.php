<?php

declare (strict_types=1);
namespace App\Entity\Partner;

use App\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
/** Append-only terms. Invoices use the terms effective at their payment date. */
#[ORM\Entity, ORM\Table(name: 'affiliate_terms')]
class PartnerTerms
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)]
    public Partner $partner;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)]
    public Utilisateur $author;
    #[ORM\Column]
    public int $rateBps = 0;
    #[ORM\Column]
    public int $feeBps = 0;
    #[ORM\Column]
    public int $feeFixedCents = 0;
    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $effectiveAt;
    public function __construct()
    {
        $this->effectiveAt = new \DateTimeImmutable();
    }
}
