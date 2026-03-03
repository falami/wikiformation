<?php

namespace App\Entity;

use App\Repository\FormateurSatisfactionAttemptRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Types\Types;

#[ORM\Entity(repositoryClass: FormateurSatisfactionAttemptRepository::class)]
#[ORM\Table(name: 'formateur_satisfaction_attempt')]
class FormateurSatisfactionAttempt
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $answers = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\OneToOne(inversedBy: 'attempt', targetEntity: FormateurSatisfactionAssignment::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE', unique: true)]
    private ?FormateurSatisfactionAssignment $assignment = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $noteGlobale = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $recommendationScore = null;

    /**
     * @var Collection<int, FormateurObjectiveEvaluation>
     */
    #[ORM\OneToMany(mappedBy: 'attempt', targetEntity: FormateurObjectiveEvaluation::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $objectiveEvaluations;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'formateurSatisfactionAttemptCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\ManyToOne(inversedBy: 'formateurSatisfactionAttemptEntites')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Entite $entite = null;



    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->objectiveEvaluations = new ArrayCollection();
        $this->dateCreation = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(\DateTimeImmutable $startedAt): static
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getSubmittedAt(): ?\DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function setSubmittedAt(?\DateTimeImmutable $submittedAt): static
    {
        $this->submittedAt = $submittedAt;

        return $this;
    }

    public function getAnswers(): ?array
    {
        return $this->answers;
    }

    public function setAnswers(?array $answers): static
    {
        $this->answers = $answers;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getAssignment(): ?FormateurSatisfactionAssignment
    {
        return $this->assignment;
    }

    public function setAssignment(?FormateurSatisfactionAssignment $assignment): static
    {
        $this->assignment = $assignment;

        return $this;
    }

    public function getNoteGlobale(): ?int
    {
        return $this->noteGlobale;
    }

    public function setNoteGlobale(?int $noteGlobale): static
    {
        $this->noteGlobale = $noteGlobale;

        return $this;
    }

    public function getRecommendationScore(): ?int
    {
        return $this->recommendationScore;
    }

    public function setRecommendationScore(?int $recommendationScore): static
    {
        $this->recommendationScore = $recommendationScore;

        return $this;
    }

    /**
     * @return Collection<int, FormateurObjectiveEvaluation>
     */
    public function getObjectiveEvaluations(): Collection
    {
        return $this->objectiveEvaluations;
    }

    public function addObjectiveEvaluation(FormateurObjectiveEvaluation $objectiveEvaluation): static
    {
        if (!$this->objectiveEvaluations->contains($objectiveEvaluation)) {
            $this->objectiveEvaluations->add($objectiveEvaluation);
            $objectiveEvaluation->setAttempt($this);
        }

        return $this;
    }

    public function removeObjectiveEvaluation(FormateurObjectiveEvaluation $objectiveEvaluation): static
    {
        if ($this->objectiveEvaluations->removeElement($objectiveEvaluation)) {
            // set the owning side to null (unless already changed)
            if ($objectiveEvaluation->getAttempt() === $this) {
                $objectiveEvaluation->setAttempt(null);
            }
        }

        return $this;
    }

    public function upsertObjectiveEval(Utilisateur $stagiaire, FormationObjective $objective): FormateurObjectiveEvaluation
    {
        foreach ($this->objectiveEvaluations as $e) {
            if (
                $e->getStagiaire()?->getId() === $stagiaire->getId()
                && $e->getObjective()?->getId() === $objective->getId()
            ) {
                return $e;
            }
        }

        $e = (new FormateurObjectiveEvaluation())
            ->setAttempt($this)
            ->setStagiaire($stagiaire)
            ->setObjective($objective)
            // ✅ champs NOT NULL de FormateurObjectiveEvaluation
            ->setCreateur($this->getCreateur())
            ->setEntite($this->getEntite());

        // garde-fou (optionnel mais utile)
        if (!$e->getCreateur() || !$e->getEntite()) {
            throw new \LogicException('Attempt.createur et Attempt.entite doivent être définis avant de créer une évaluation objectif.');
        }

        $this->objectiveEvaluations->add($e);

        return $e;
    }


    public function isSubmitted(): bool
    {
        return $this->submittedAt !== null;
    }

    public function isStarted(): bool
    {
        return $this->startedAt !== null;
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
