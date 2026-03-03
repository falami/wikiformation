<?php

namespace App\Entity;

use App\Repository\PositioningQuestionnaireRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PositioningQuestionnaireRepository::class)]
class PositioningQuestionnaire
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'positioningQuestionnaires')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Entite $entite = null;

    #[ORM\Column(length: 160)]
    private ?string $title = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $software = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isPublished = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @var Collection<int, PositioningChapter>
     */
    #[ORM\OneToMany(mappedBy: 'questionnaire', targetEntity: PositioningChapter::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $chapters;

    /**
     * @var Collection<int, SessionPositioning>
     */
    #[ORM\OneToMany(mappedBy: 'questionnaire', targetEntity: SessionPositioning::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $sessionPositionings;

    /**
     * @var Collection<int, PositioningAttempt>
     */
    #[ORM\OneToMany(mappedBy: 'questionnaire', targetEntity: PositioningAttempt::class)]
    private Collection $positioningAttempts;

    /**
     * ✅ NOUVEAU : pour inversedBy="positioningAssignments" dans PositioningAssignment
     * @var Collection<int, PositioningAssignment>
     */
    #[ORM\OneToMany(mappedBy: 'questionnaire', targetEntity: PositioningAssignment::class, orphanRemoval: true)]
    private Collection $positioningAssignments;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'positioningQuestionnaireCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    public function __construct()
    {
        $this->chapters = new ArrayCollection();
        $this->sessionPositionings = new ArrayCollection();
        $this->positioningAttempts = new ArrayCollection();
        $this->positioningAssignments = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->dateCreation = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEntite(): ?Entite
    {
        return $this->entite;
    }
    public function setEntite(?Entite $e): static
    {
        $this->entite = $e;
        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }
    public function setTitle(string $t): static
    {
        $this->title = $t;
        return $this;
    }

    public function getSoftware(): ?string
    {
        return $this->software;
    }
    public function setSoftware(?string $s): static
    {
        $this->software = $s;
        return $this;
    }

    public function isPublished(): bool
    {
        return $this->isPublished;
    }
    public function setIsPublished(bool $v): static
    {
        $this->isPublished = $v;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return Collection<int, PositioningChapter>
     */
    public function getChapters(): Collection
    {
        return $this->chapters;
    }

    public function addChapter(PositioningChapter $c): static
    {
        if (!$this->chapters->contains($c)) {
            $this->chapters->add($c);
            $c->setQuestionnaire($this);

            // ✅ defaults hérités du questionnaire
            if (!$c->getEntite()) {
                $c->setEntite($this->getEntite());
            }
            if (!$c->getCreateur()) {
                $c->setCreateur($this->getCreateur());
            }
        }
        return $this;
    }


    public function removeChapter(PositioningChapter $c): static
    {
        $this->chapters->removeElement($c);
        return $this;
    }


    

    /**
     * @return Collection<int, SessionPositioning>
     */
    public function getSessionPositionings(): Collection
    {
        return $this->sessionPositionings;
    }

    /**
     * @return Collection<int, PositioningAttempt>
     */
    public function getPositioningAttempts(): Collection
    {
        return $this->positioningAttempts;
    }

    public function addPositioningAttempt(PositioningAttempt $a): static
    {
        if (!$this->positioningAttempts->contains($a)) {
            $this->positioningAttempts->add($a);
            $a->setQuestionnaire($this);
        }
        return $this;
    }

    public function removePositioningAttempt(PositioningAttempt $a): static
    {
        // pas de setQuestionnaire(null) (NOT NULL)
        $this->positioningAttempts->removeElement($a);
        return $this;
    }

    /**
     * @return Collection<int, PositioningAssignment>
     */
    public function getPositioningAssignments(): Collection
    {
        return $this->positioningAssignments;
    }

    public function addPositioningAssignment(PositioningAssignment $a): static
    {
        if (!$this->positioningAssignments->contains($a)) {
            $this->positioningAssignments->add($a);
            $a->setQuestionnaire($this);
        }
        return $this;
    }

    public function removePositioningAssignment(PositioningAssignment $a): static
    {
        $this->positioningAssignments->removeElement($a);
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
}
