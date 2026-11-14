<?php
declare(strict_types=1);
namespace App\Entity\Billing;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity, ORM\Table(name: 'billing_module')]
class Module
{
    #[ORM\Id, ORM\Column(length: 60)] public string $code;
    #[ORM\Column(length: 120)] public string $name = '';
    #[ORM\Column(type: 'text')] public string $description = '';
    #[ORM\Column] public int $monthlyPrice = 20000;
    #[ORM\Column] public int $trialDays = 30;
    #[ORM\Column] public bool $active = false;
    #[ORM\Column(length: 255, nullable: true)] public ?string $stripePriceId = null;
    #[ORM\Column] public bool $included = false;
}
