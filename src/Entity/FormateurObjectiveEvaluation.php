<?php

namespace App\Entity;

use App\Repository\FormateurObjectiveEvaluationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Enum\AcquisitionLevel;

#[ORM\Entity(repositoryClass: FormateurObjectiveEvaluationRepository::class)]
#[ORM\Table(name: 'formateur_objective_evaluation')]
#[ORM\UniqueConstraint(name: 'uniq_attempt_stag_obj', columns: ['attempt_id', 'stagiaire_id', 'objective_id'])]
class FormateurObjectiveEvaluation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'objectiveEvaluations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?FormateurSatisfactionAttempt $attempt = null;

    #[ORM\ManyToOne(inversedBy: 'formateurObjectiveEvaluations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Utilisateur $stagiaire = null;

    #[ORM\ManyToOne(inversedBy: 'formateurObjectiveEvaluations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?FormationObjective $objective = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    #[ORM\Column(enumType: AcquisitionLevel::class, nullable: true)]
    private ?AcquisitionLevel $level = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'formateurObjectiveEvaluationCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\ManyToOne(inversedBy: 'formateurObjectiveEvalutaionEntites')]
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

    public function getAttempt(): ?FormateurSatisfactionAttempt
    {
        return $this->attempt;
    }

    public function setAttempt(?FormateurSatisfactionAttempt $attempt): static
    {
        $this->attempt = $attempt;

        return $this;
    }

    public function getStagiaire(): ?Utilisateur
    {
        return $this->stagiaire;
    }

    public function setStagiaire(?Utilisateur $stagiaire): static
    {
        $this->stagiaire = $stagiaire;

        return $this;
    }

    public function getObjective(): ?FormationObjective
    {
        return $this->objective;
    }

    public function setObjective(?FormationObjective $objective): static
    {
        $this->objective = $objective;

        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): static
    {
        $this->comment = $comment;

        return $this;
    }

    public function getLevel(): ?AcquisitionLevel
    {
        return $this->level;
    }

    public function setLevel(?AcquisitionLevel $level): static
    {
        $this->level = $level;
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
