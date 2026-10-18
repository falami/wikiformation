<?php
declare(strict_types=1);
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity]
#[ORM\UniqueConstraint(name:'uniq_recruitment_placement',columns:['application_id','entite_id'])]
class RecruitmentPlacement
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] public ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable:false,onDelete:'CASCADE')] public ?TrainerApplication $application = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable:false,onDelete:'RESTRICT')] public ?Entite $entite = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable:false,onDelete:'RESTRICT')] public ?Formateur $formateur = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable:false,onDelete:'RESTRICT')] public ?Utilisateur $actor = null;
    #[ORM\Column(type:'datetime_immutable')] public \DateTimeImmutable $createdAt;
    public function __construct() { $this->createdAt = new \DateTimeImmutable(); }
}
