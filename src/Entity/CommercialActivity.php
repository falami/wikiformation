<?php

declare(strict_types=1);
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Index(columns: ['entite_id', 'module', 'record_id'])]
class CommercialActivity
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id=null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable:false, onDelete:'CASCADE')] private ?Entite $entite=null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable:false)] private ?Utilisateur $author=null;
    #[ORM\Column(length:30)] private string $module='entreprises';
    #[ORM\Column] private int $recordId=0;
    #[ORM\Column(length:180)] private string $title='';
    #[ORM\Column(type:'text')] private string $content='';
    #[ORM\Column(length:20)] private string $kind='note';
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    #[ORM\Column(nullable:true)] private ?\DateTimeImmutable $dueAt=null;
    #[ORM\Column(nullable:true)] private ?\DateTimeImmutable $completedAt=null;
    public function __construct() { $this->createdAt=new \DateTimeImmutable(); }
    public function getId(): ?int {return $this->id;}
    public function getEntite(): ?Entite {return $this->entite;}
    public function setEntite(Entite $v): static {$this->entite=$v;return $this;}
    public function getAuthor(): ?Utilisateur {return $this->author;}
    public function setAuthor(Utilisateur $v): static {$this->author=$v;return $this;}
    public function getModule(): string {return $this->module;}
    public function setModule(string $v): static {$this->module=$v;return $this;}
    public function getRecordId(): int {return $this->recordId;}
    public function setRecordId(int $v): static {$this->recordId=$v;return $this;}
    public function getTitle(): string {return $this->title;}
    public function setTitle(string $v): static {$this->title=$v;return $this;}
    public function getContent(): string {return $this->content;}
    public function setContent(string $v): static {$this->content=$v;return $this;}
    public function getKind(): string {return $this->kind;}
    public function setKind(string $v): static {$this->kind=$v;return $this;}
    public function getCreatedAt(): \DateTimeImmutable {return $this->createdAt;}
    public function getDueAt(): ?\DateTimeImmutable {return $this->dueAt;}
    public function setDueAt(?\DateTimeImmutable $v): static {$this->dueAt=$v;return $this;}
    public function getCompletedAt(): ?\DateTimeImmutable {return $this->completedAt;}
    public function complete(): void {$this->completedAt=new \DateTimeImmutable();}
}
