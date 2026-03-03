<?php

namespace App\Entity;

use App\Repository\QuizAnswerRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: QuizAnswerRepository::class)]
class QuizAnswer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'answers')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?QuizAttempt $attempt = null;


    #[ORM\ManyToOne(inversedBy: 'quizAnswers')]
    private ?QuizQuestion $question = null;

    #[ORM\ManyToOne(inversedBy: 'quizAnswers')]
    private ?QuizChoice $quizChoice = null;


    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $textAnswer = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isCorrect = false;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'quizAnswerCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\ManyToOne(inversedBy: 'quizAnswerEntites')]
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

    public function getAttempt(): ?QuizAttempt
    {
        return $this->attempt;
    }

    public function setAttempt(?QuizAttempt $attempt): static
    {
        $this->attempt = $attempt;

        return $this;
    }

    public function getQuestion(): ?QuizQuestion
    {
        return $this->question;
    }

    public function setQuestion(?QuizQuestion $question): static
    {
        $this->question = $question;

        return $this;
    }

    public function getQuizChoice(): ?QuizChoice
    {
        return $this->quizChoice;
    }

    public function setQuizChoice(?QuizChoice $quizChoice): static
    {
        $this->quizChoice = $quizChoice;

        return $this;
    }

    public function getTextAnswer(): ?string
    {
        return $this->textAnswer;
    }

    public function setTextAnswer(?string $textAnswer): static
    {
        $this->textAnswer = $textAnswer;

        return $this;
    }

    public function isCorrect(): bool
    {
        return $this->isCorrect;
    }

    public function setIsCorrect(bool $isCorrect): static
    {
        $this->isCorrect = $isCorrect;

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
