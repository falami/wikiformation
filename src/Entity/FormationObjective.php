<?php

namespace App\Entity;

use App\Repository\FormationObjectiveRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FormationObjectiveRepository::class)]
class FormationObjective
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'objectives')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Formation $formation = null;

    #[ORM\Column(length: 255)]
    private string $label = '';

    #[ORM\Column(options: ['unsigned' => true])]
    private int $position = 1;

    /**
     * @var Collection<int, FormateurObjectiveEvaluation>
     */
    #[ORM\OneToMany(targetEntity: FormateurObjectiveEvaluation::class, mappedBy: 'objective')]
    private Collection $formateurObjectiveEvaluations;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'formationObjectiveCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\ManyToOne(inversedBy: 'formationObjectiveEntites')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Entite $entite = null;

    public function __construct()
    {
        $this->formateurObjectiveEvaluations = new ArrayCollection();
        $this->dateCreation = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFormation(): ?Formation
    {
        return $this->formation;
    }

    public function setFormation(?Formation $formation): static
    {
        $this->formation = $formation;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    /**
     * @return Collection<int, FormateurObjectiveEvaluation>
     */
    public function getFormateurObjectiveEvaluations(): Collection
    {
        return $this->formateurObjectiveEvaluations;
    }

    public function addFormateurObjectiveEvaluation(FormateurObjectiveEvaluation $formateurObjectiveEvaluation): static
    {
        if (!$this->formateurObjectiveEvaluations->contains($formateurObjectiveEvaluation)) {
            $this->formateurObjectiveEvaluations->add($formateurObjectiveEvaluation);
            $formateurObjectiveEvaluation->setObjective($this);
        }

        return $this;
    }

    public function removeFormateurObjectiveEvaluation(FormateurObjectiveEvaluation $formateurObjectiveEvaluation): static
    {
        if ($this->formateurObjectiveEvaluations->removeElement($formateurObjectiveEvaluation)) {
            // set the owning side to null (unless already changed)
            if ($formateurObjectiveEvaluation->getObjective() === $this) {
                $formateurObjectiveEvaluation->setObjective(null);
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
