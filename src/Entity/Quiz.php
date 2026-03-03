<?php
// src/Entity/Quiz.php
namespace App\Entity;

use App\Entity\Elearning\ElearningBlock;
use App\Repository\QuizRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: QuizRepository::class)]
class Quiz
{
    #[ORM\Id] #[ORM\GeneratedValue] #[ORM\Column] private ?int $id = null;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $title = null;

    // options diverses (temps, shuffle, score de passage…)
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $settings = null;

    /** @var Collection<int, QuizQuestion> */
    #[ORM\OneToMany(mappedBy: 'quiz', targetEntity: QuizQuestion::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $questions;

    /**
     * @var Collection<int, QuizAttempt>
     */
    #[ORM\OneToMany(targetEntity: QuizAttempt::class, mappedBy: 'quiz')]
    private Collection $quizAttempts;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'quizCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\ManyToOne(inversedBy: 'quizEntites')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Entite $entite = null;

    #[ORM\OneToOne(mappedBy: 'quiz', cascade: ['persist', 'remove'])]
    private ?ElearningBlock $elearningBlock = null;

    public function __construct()
    {
        $this->questions = new ArrayCollection();
        $this->quizAttempts = new ArrayCollection();
        $this->dateCreation = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }
    public function getTitle(): ?string
    {
        return $this->title;
    }
    public function setTitle(?string $t): self
    {
        $this->title = $t;
        return $this;
    }
    public function getSettings(): ?array
    {
        return $this->settings;
    }
    public function setSettings(?array $s): self
    {
        $this->settings = $s;
        return $this;
    }

    /** @return Collection<int, QuizQuestion> */
    public function getQuestions(): Collection
    {
        return $this->questions;
    }
    public function addQuestion(QuizQuestion $q): self
    {
        if (!$this->questions->contains($q)) {
            $this->questions->add($q);
            $q->setQuiz($this);
        }
        return $this;
    }
    public function removeQuestion(QuizQuestion $q): self
    {
        if ($this->questions->removeElement($q) && $q->getQuiz() === $this) {
            $q->setQuiz(null);
        }
        return $this;
    }

    /**
     * @return Collection<int, QuizAttempt>
     */
    public function getQuizAttempts(): Collection
    {
        return $this->quizAttempts;
    }

    public function addQuizAttempt(QuizAttempt $quizAttempt): static
    {
        if (!$this->quizAttempts->contains($quizAttempt)) {
            $this->quizAttempts->add($quizAttempt);
            $quizAttempt->setQuiz($this);
        }

        return $this;
    }

    public function removeQuizAttempt(QuizAttempt $quizAttempt): static
    {
        if ($this->quizAttempts->removeElement($quizAttempt)) {
            // set the owning side to null (unless already changed)
            if ($quizAttempt->getQuiz() === $this) {
                $quizAttempt->setQuiz(null);
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

    public function getElearningBlock(): ?ElearningBlock
    {
        return $this->elearningBlock;
    }

    public function setElearningBlock(?ElearningBlock $elearningBlock): static
    {
        // unset the owning side of the relation if necessary
        if ($elearningBlock === null && $this->elearningBlock !== null) {
            $this->elearningBlock->setQuiz(null);
        }

        // set the owning side of the relation if necessary
        if ($elearningBlock !== null && $elearningBlock->getQuiz() !== $this) {
            $elearningBlock->setQuiz($this);
        }

        $this->elearningBlock = $elearningBlock;

        return $this;
    }
}
