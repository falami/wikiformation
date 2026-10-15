<?php
declare(strict_types=1);
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity]
class ExpenseImportRule
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] public ?int $id=null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable:false,onDelete:'CASCADE')] public ?Entite $entite=null;
    #[ORM\Column(length:180)] public string $keyword='';
    #[ORM\Column] public int $priority=100;
    #[ORM\Column] public bool $enabled=true;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable:false,onDelete:'CASCADE')] public ?DepenseCategorie $category=null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable:false,onDelete:'CASCADE')] public ?DepenseFournisseur $supplier=null;
    #[ORM\Column] public bool $deductible=true;
    #[ORM\Column] public float $percentage=80;
}
