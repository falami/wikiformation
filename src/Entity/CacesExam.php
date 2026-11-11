<?php

declare(strict_types=1);
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class CacesExam
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    public function getId(): ?int { return $this->id; }
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Entite $entite = null;
    public function getEntite(): ?Entite { return $this->entite; }
    public function setEntite(?Entite $value): static { $this->entite = $value; return $this; }
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Session $session = null;
    public function getSession(): ?Session { return $this->session; }
    public function setSession(?Session $value): static { $this->session = $value; return $this; }
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;
    public function getCreateur(): ?Utilisateur { return $this->createur; }
    public function setCreateur(?Utilisateur $value): static { $this->createur = $value; return $this; }
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $theoryTester = null;
    public function getTheoryTester(): ?Utilisateur { return $this->theoryTester; }
    public function setTheoryTester(?Utilisateur $value): static { $this->theoryTester = $value; return $this; }
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $practiceTester = null;
    public function getPracticeTester(): ?Utilisateur { return $this->practiceTester; }
    public function setPracticeTester(?Utilisateur $value): static { $this->practiceTester = $value; return $this; }
    #[ORM\Column(length: 3)]
    private string $recommendation = '489';
    public function getRecommendation(): string { return $this->recommendation; }
    public function setRecommendation(string $value): static { $this->recommendation = $value; return $this; }
    #[ORM\Column(length: 12)]
    private string $category = '3';
    public function getCategory(): string { return $this->category; }
    public function setCategory(string $value): static { $this->category = $value; return $this; }
    #[ORM\Column(length: 255)]
    private string $location = '';
    public function getLocation(): string { return $this->location; }
    public function setLocation(string $value): static { $this->location = $value; return $this; }
    #[ORM\Column(type: 'json')]
    private array $theorySnapshot = [];
    public function getTheorySnapshot(): array { return $this->theorySnapshot; }
    public function setTheorySnapshot(array $value): static { $this->theorySnapshot = $value; return $this; }
    #[ORM\Column(type: 'json')]
    private array $practiceSnapshot = [];
    public function getPracticeSnapshot(): array { return $this->practiceSnapshot; }
    public function setPracticeSnapshot(array $value): static { $this->practiceSnapshot = $value; return $this; }
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $value): static { $this->createdAt = $value; return $this; }
    public function __construct() { $this->createdAt = new \DateTimeImmutable(); }
}
