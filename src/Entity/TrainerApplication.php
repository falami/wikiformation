<?php
declare(strict_types=1);
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity]
#[ORM\Index(columns: ['status', 'created_at'])]
class TrainerApplication
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] public ?int $id = null;
    #[ORM\Column(length:180)] public string $email = '';
    #[ORM\Column(length:100)] public string $firstName = '';
    #[ORM\Column(length:100)] public string $lastName = '';
    #[ORM\Column(length:30)] public string $phone = '';
    #[ORM\Column(type:'json')] public array $details = [];
    #[ORM\Column(type:'json')] public array $subjects = [];
    #[ORM\Column(type:'json')] public array $departments = [];
    #[ORM\Column] public bool $nationwide = false;
    #[ORM\Column(nullable:true)] public ?int $hourlyRate = null;
    #[ORM\Column(nullable:true)] public ?int $dailyRate = null;
    #[ORM\Column(length:100)] public string $cvFilename = '';
    #[ORM\Column(length:255)] public string $cvOriginalName = '';
    #[ORM\Column(length:100,nullable:true)] public ?string $photoFilename = null;
    #[ORM\Column(length:20)] public string $status = 'new';
    #[ORM\Column(type:'text')] public string $notes = '';
    #[ORM\Column(type:'datetime_immutable')] public \DateTimeImmutable $createdAt;
    #[ORM\Column(type:'datetime_immutable')] public \DateTimeImmutable $consentedAt;
    #[ORM\ManyToOne] public ?Utilisateur $account = null;
    #[ORM\Version, ORM\Column(type:'integer')] public int $version = 1;
    public function __construct() { $this->createdAt = $this->consentedAt = new \DateTimeImmutable(); }
}
