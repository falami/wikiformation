<?php
declare(strict_types=1);
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity]
class TrainerProfile
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] public ?int $id = null;
    #[ORM\OneToOne, ORM\JoinColumn(nullable:false,onDelete:'CASCADE')] public ?Formateur $formateur = null;
    #[ORM\Column(type:'json')] public array $subjects = [];
    #[ORM\Column(type:'json')] public array $workingDays = [1,2,3,4,5];
    #[ORM\Column(type:'boolean')] public bool $declaredAvailable = false;
    #[ORM\Column(type:'json')] public array $unavailable = [];
    #[ORM\Column(type:'text',nullable:true)] public ?string $calendarSecret = null;
    #[ORM\Column(type:'json')] public array $busy = [];
    #[ORM\Column(type:'datetime_immutable',nullable:true)] public ?\DateTimeImmutable $syncedAt = null;
    #[ORM\Column(type:'datetime_immutable',nullable:true)] public ?\DateTimeImmutable $attemptedAt = null;
    #[ORM\Column(type:'datetime_immutable',nullable:true)] public ?\DateTimeImmutable $coveredUntil = null;
    #[ORM\Column(length:255,nullable:true)] public ?string $syncError = null;
}
