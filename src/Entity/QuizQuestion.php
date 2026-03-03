<?php
// src/Entity/QuizQuestion.php
namespace App\Entity;

use App\Enum\QuestionType;
use App\Repository\QuizQuestionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: QuizQuestionRepository::class)]
class QuizQuestion
{
    #[ORM\Id] #[ORM\GeneratedValue] #[ORM\Column] private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'questions')] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Quiz $quiz = null;

    #[ORM\Column(enumType: QuestionType::class)]
    private QuestionType $type = QuestionType::SINGLE;

    #[ORM\Column(length: 300)]
    private string $text = '';

    #[ORM\Column(nullable: true)]
    private ?int $position = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $explanation = null;

    // Pour TEXT : correction facultative
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $expectedText = null;

    /** @var Collection<int, QuizChoice> */
    #[ORM\OneToMany(mappedBy: 'question', targetEntity: QuizChoice::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $choices;

    /**
     * @var Collection<int, QuizAnswer>
     */
    #[ORM\OneToMany(targetEntity: QuizAnswer::class, mappedBy: 'question')]
    private Collection $quizAnswers;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'quizQuestionCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\ManyToOne(inversedBy: 'quizQuestionEntites')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Entite $entite = null;

    public function __construct()
    {
        $this->choices = new ArrayCollection();
        $this->quizAnswers = new ArrayCollection();
        $this->dateCreation = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }
    public function getQuiz(): ?Quiz
    {
        return $this->quiz;
    }
    public function setQuiz(?Quiz $q): self
    {
        $this->quiz = $q;
        return $this;
    }

    public function getType(): QuestionType
    {
        return $this->type;
    }
    public function setType(QuestionType $t): self
    {
        $this->type = $t;
        return $this;
    }

    public function getText(): string
    {
        return $this->text;
    }
    public function setText(string $t): self
    {
        $this->text = $t;
        return $this;
    }

    public function getPosition(): ?int
    {
        return $this->position;
    }
    public function setPosition(?int $p): self
    {
        $this->position = $p;
        return $this;
    }

    public function getExplanation(): ?string
    {
        return $this->explanation;
    }
    public function setExplanation(?string $e): self
    {
        $this->explanation = $e;
        return $this;
    }

    public function getExpectedText(): ?string
    {
        return $this->expectedText;
    }
    public function setExpectedText(?string $e): self
    {
        $this->expectedText = $e;
        return $this;
    }

    /** @return Collection<int, QuizChoice> */
    public function getChoices(): Collection
    {
        return $this->choices;
    }
    public function addChoice(QuizChoice $c): self
    {
        if (!$this->choices->contains($c)) {
            $this->choices->add($c);
            $c->setQuestion($this);
        }
        return $this;
    }
    public function removeChoice(QuizChoice $c): self
    {
        $this->choices->removeElement($c);
        return $this;
    }


    /**
     * @return Collection<int, QuizAnswer>
     */
    public function getQuizAnswers(): Collection
    {
        return $this->quizAnswers;
    }

    public function addQuizAnswer(QuizAnswer $quizAnswer): static
    {
        if (!$this->quizAnswers->contains($quizAnswer)) {
            $this->quizAnswers->add($quizAnswer);
            $quizAnswer->setQuestion($this);
        }

        return $this;
    }

    public function removeQuizAnswer(QuizAnswer $quizAnswer): static
    {
        if ($this->quizAnswers->removeElement($quizAnswer)) {
            // set the owning side to null (unless already changed)
            if ($quizAnswer->getQuestion() === $this) {
                $quizAnswer->setQuestion(null);
            }
        }

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
