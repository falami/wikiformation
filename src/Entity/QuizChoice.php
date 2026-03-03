<?php
// src/Entity/QuizChoice.php
namespace App\Entity;

use App\Repository\QuizChoiceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: QuizChoiceRepository::class)]
class QuizChoice
{
    #[ORM\Id] #[ORM\GeneratedValue] #[ORM\Column] private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'choices')] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?QuizQuestion $question = null;

    #[ORM\Column(type: 'text')]
    private string $label = '';

    #[ORM\Column(options: ['default' => false])]
    private bool $isCorrect = false;

    #[ORM\Column(nullable: true)]
    private ?int $position = 0;

    /**
     * @var Collection<int, QuizAnswer>
     */
    #[ORM\OneToMany(targetEntity: QuizAnswer::class, mappedBy: 'quizChoice')]
    private Collection $quizAnswers;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'quizChoiceCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\ManyToOne(inversedBy: 'quizChoiceEntites')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Entite $entite = null;

    public function __construct()
    {
        $this->quizAnswers = new ArrayCollection();
        $this->dateCreation = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }
    public function getQuestion(): ?QuizQuestion
    {
        return $this->question;
    }
    public function setQuestion(?QuizQuestion $q): self
    {
        $this->question = $q;
        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }
    public function setLabel(string $l): self
    {
        $this->label = $l;
        return $this;
    }

    public function isCorrect(): bool
    {
        return $this->isCorrect;
    }
    public function setIsCorrect(bool $c): self
    {
        $this->isCorrect = $c;
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
            $quizAnswer->setQuizChoice($this);
        }

        return $this;
    }

    public function removeQuizAnswer(QuizAnswer $quizAnswer): static
    {
        if ($this->quizAnswers->removeElement($quizAnswer)) {
            // set the owning side to null (unless already changed)
            if ($quizAnswer->getQuizChoice() === $this) {
                $quizAnswer->setQuizChoice(null);
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
