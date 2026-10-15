<?php
declare(strict_types=1);
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity]
class TrainerCv
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] public ?int $id = null;
    #[ORM\OneToOne, ORM\JoinColumn(nullable:false,onDelete:'CASCADE')] public ?Formateur $formateur = null;
    #[ORM\Column(type:'json')] public array $content = [];
    #[ORM\Column(type:'json',nullable:true)] public ?array $published = null;
    #[ORM\Column(type:'json',nullable:true)] public ?array $suggestion = null;
    #[ORM\Column(length:100,nullable:true)] public ?string $filename = null;
    #[ORM\Column(length:255,nullable:true)] public ?string $originalName = null;
    #[ORM\Version, ORM\Column(type:'integer')] public int $version = 1;
}
