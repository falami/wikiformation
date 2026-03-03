<?php

namespace App\Entity;

use App\Enum\KnowledgeLevel;
use App\Enum\InterestChoice;
use App\Repository\PositioningAnswerRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'positioning_answer')]
#[ORM\UniqueConstraint(name: 'uniq_attempt_item', columns: ['attempt_id', 'item_id'])]
#[ORM\Entity(repositoryClass: PositioningAnswerRepository::class)]
class PositioningAnswer

{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(enumType: KnowledgeLevel::class, nullable: true)]
    private ?KnowledgeLevel $knowledge = null;

    #[ORM\Column(enumType: InterestChoice::class, nullable: true)]
    private ?InterestChoice $interest = null;

    #[ORM\ManyToOne(inversedBy: 'answers')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?PositioningAttempt $attempt = null;


    #[ORM\ManyToOne(inversedBy: 'positioningAnswers')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?PositioningItem $item = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'positioningAnswerCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\ManyToOne(inversedBy: 'positioningAnswerEntites')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Entite $entite = null;


    public function __construct()
    {
        $this->dateCreation = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAttempt(): ?PositioningAttempt
    {
        return $this->attempt;
    }

    public function setAttempt(?PositioningAttempt $attempt): static
    {
        $this->attempt = $attempt;

        return $this;
    }

    public function getItem(): ?PositioningItem
    {
        return $this->item;
    }

    public function setItem(?PositioningItem $item): static
    {
        $this->item = $item;

        return $this;
    }


    public function getKnowledge(): ?KnowledgeLevel
    {
        return $this->knowledge;
    }

    public function setKnowledge(?KnowledgeLevel $knowledge): static
    {
        $this->knowledge = $knowledge;
        return $this;
    }

    public function getInterest(): ?InterestChoice
    {
        return $this->interest;
    }

    public function setInterest(?InterestChoice $interest): static
    {
        $this->interest = $interest;
        return $this;
    }

    public function getDateCreation(): ?\DateTimeImmutable
    {
        return $this->dateCreation;
    }

    public function setDateCreation(\DateTimeImmutable $dateCreation): static
    {
        $this->dateCreation = $dateCreation;

        return $this;
    }

    public function getCreateur(): ?Utilisateur
    {
        return $this->createur;
    }

    public function setCreateur(?Utilisateur $createur): static
    {
        $this->createur = $createur;

        return $this;
    }

    public function getEntite(): ?Entite
    {
        return $this->entite;
    }

    public function setEntite(?Entite $entite): static
    {
        $this->entite = $entite;

        return $this;
    }
}
