<?php
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity]
class TrainerInquiry {
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] public ?int $id=null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable:false)] public ?Formateur $formateur=null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable:false)] public ?Utilisateur $author=null;
    #[ORM\Column(length:100)] public string $subject='';
    #[ORM\Column(length:180)] public string $recipient='';
    #[ORM\Column(type:'json')] public array $slots=[];
    #[ORM\Column(type:'text')] public string $message='';
    #[ORM\Column(length:20)] public string $status='pending';
    #[ORM\Column(length:64,unique:true)] public string $requestKey='';
    #[ORM\Column(type:'json')] public array $history=[];
    #[ORM\Column(type:'datetime_immutable')] public \DateTimeImmutable $createdAt;
    public function __construct(){ $this->createdAt=new \DateTimeImmutable(); }
}
