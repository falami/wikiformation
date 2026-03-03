<?php

namespace App\Entity;

use App\Repository\PositioningAttemptRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Enum\SuggestedLevel;


#[ORM\Entity(repositoryClass: PositioningAttemptRepository::class)]
#[ORM\Table(name: 'positioning_attempt')]
#[ORM\UniqueConstraint(name: 'uniq_attempt_assignment', columns: ['assignment_id'])]
class PositioningAttempt
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'attempt')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?PositioningAssignment $assignment = null;

    #[ORM\ManyToOne(inversedBy: 'positioningAttempts')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?PositioningQuestionnaire $questionnaire = null;

    #[ORM\ManyToOne(inversedBy: 'positioningAttempts')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Utilisateur $stagiaire = null;

    // ✅ nullable = true (assignation directe possible)
    #[ORM\ManyToOne(inversedBy: 'positioningAttempts')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Inscription $inscription = null;

    // ✅ nullable = true (assignation directe possible)
    #[ORM\ManyToOne(inversedBy: 'positioningAttempts')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Session $session = null;

    #[ORM\ManyToOne(inversedBy: 'positioningAttempts')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Formateur $assignedFormateur = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $stagiaireComment = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $formateurConclusion = null;

    #[ORM\Column(enumType: SuggestedLevel::class, nullable: true)]
    private ?SuggestedLevel $suggestedLevel = null;





    /**
     * @var Collection<int, PositioningAnswer>
     */
    #[ORM\OneToMany(mappedBy: 'attempt', targetEntity: PositioningAnswer::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $answers;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'positioningAttemptCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\ManyToOne(inversedBy: 'positioningAttemptEntites')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Entite $entite = null;

    public function __construct()
    {
        $this->answers = new ArrayCollection();
        $this->startedAt = new \DateTimeImmutable();
        $this->dateCreation = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAssignment(): ?PositioningAssignment
    {
        return $this->assignment;
    }
    public function setAssignment(PositioningAssignment $a): static
    {
        $this->assignment = $a;
        return $this;
    }

    public function getQuestionnaire(): ?PositioningQuestionnaire
    {
        return $this->questionnaire;
    }
    public function setQuestionnaire(?PositioningQuestionnaire $q): static
    {
        $this->questionnaire = $q;
        return $this;
    }

    public function getStagiaire(): ?Utilisateur
    {
        return $this->stagiaire;
    }
    public function setStagiaire(?Utilisateur $u): static
    {
        $this->stagiaire = $u;
        return $this;
    }

    public function getInscription(): ?Inscription
    {
        return $this->inscription;
    }
    public function setInscription(?Inscription $i): static
    {
        $this->inscription = $i;
        return $this;
    }

    public function getSession(): ?Session
    {
        return $this->session;
    }
    public function setSession(?Session $s): static
    {
        $this->session = $s;
        return $this;
    }

    public function getAssignedFormateur(): ?Formateur
    {
        return $this->assignedFormateur;
    }
    public function setAssignedFormateur(?Formateur $f): static
    {
        $this->assignedFormateur = $f;
        return $this;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }
    public function setStartedAt(\DateTimeImmutable $d): static
    {
        $this->startedAt = $d;
        return $this;
    }

    public function getSubmittedAt(): ?\DateTimeImmutable
    {
        return $this->submittedAt;
    }
    public function setSubmittedAt(?\DateTimeImmutable $d): static
    {
        $this->submittedAt = $d;
        return $this;
    }

    public function getStagiaireComment(): ?string
    {
        return $this->stagiaireComment;
    }
    public function setStagiaireComment(?string $c): static
    {
        $this->stagiaireComment = $c;
        return $this;
    }

    public function getFormateurConclusion(): ?string
    {
        return $this->formateurConclusion;
    }
    public function setFormateurConclusion(?string $c): static
    {
        $this->formateurConclusion = $c;
        return $this;
    }

    public function getSuggestedLevel(): ?SuggestedLevel
    {
        return $this->suggestedLevel;
    }

    public function setSuggestedLevel(?SuggestedLevel $level): static
    {
        $this->suggestedLevel = $level;
        return $this;
    }

    /** @return Collection<int, PositioningAnswer> */
    public function getAnswers(): Collection
    {
        return $this->answers;
    }

    public function addAnswer(PositioningAnswer $answer): static
    {
        if (!$this->answers->contains($answer)) {
            $this->answers->add($answer);
            $answer->setAttempt($this);
        }
        return $this;
    }

    public function removeAnswer(PositioningAnswer $answer): static
    {
        if ($this->answers->removeElement($answer)) {
            if ($answer->getAttempt() === $this) {
                $answer->setAttempt(null);
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
