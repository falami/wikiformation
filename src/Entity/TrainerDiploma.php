<?php
declare(strict_types=1);
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity]
class TrainerDiploma
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] public ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable:false,onDelete:'CASCADE')] public ?Formateur $formateur = null;
    #[ORM\Column(length:180)] public string $title = '';
    #[ORM\Column(length:100)] public string $filename = '';
    #[ORM\Column(length:255)] public string $originalName = '';
    #[ORM\Column(length:80)] public string $mime = '';
    #[ORM\Column(type:'datetime_immutable')] public \DateTimeImmutable $uploadedAt;
    public function __construct() { $this->uploadedAt = new \DateTimeImmutable(); }
}
