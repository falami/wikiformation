<?php

namespace App\Entity;

use App\Repository\QcmRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: QcmRepository::class)]
#[ORM\Index(columns: ['is_active'])]
#[ORM\HasLifecycleCallbacks]
class Qcm
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'qcms')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Entite $entite = null;

    #[ORM\Column(length: 180)]
    private string $titre = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * @var Collection<int, QcmQuestion>
     */
    #[ORM\OneToMany(
        targetEntity: QcmQuestion::class,
        mappedBy: 'qcm',
        orphanRemoval: true,
        cascade: ['persist']
    )]
    private Collection $questions;


    /**
     * @var Collection<int, QcmAssignment>
     */
    #[ORM\OneToMany(targetEntity: QcmAssignment::class, mappedBy: 'qcm')]
    private Collection $qcmAssignments;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'qcmCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->questions = new ArrayCollection();
        $this->qcmAssignments = new ArrayCollection();
        $this->dateCreation = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }


    public function getId(): ?int
    {
        return $this->id;
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

    public function getTitre(): string
    {
        return $this->titre;
    }

    public function setTitre(string $titre): static
    {
        $this->titre = $titre;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

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

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    /**
     * @return Collection<int, QcmQuestion>
     */
    public function getQuestions(): Collection
    {
        return $this->questions;
    }

    public function addQuestion(QcmQuestion $question): static
    {
        if (!$this->questions->contains($question)) {
            $this->questions->add($question);
            $question->setQcm($this);

            // ✅ defaults robustes
            if (!$question->getEntite()) {
                $question->setEntite($this->entite);
            }
            if (!$question->getCreateur()) {
                $question->setCreateur($this->createur);
            }
        }

        return $this;
    }


    public function removeQuestion(QcmQuestion $question): static
    {
        if ($this->questions->removeElement($question)) {
            // set the owning side to null (unless already changed)
            if ($question->getQcm() === $this) {
                $question->setQcm(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, QcmAssignment>
     */
    public function getQcmAssignments(): Collection
    {
        return $this->qcmAssignments;
    }

    public function addQcmAssignment(QcmAssignment $qcmAssignment): static
    {
        if (!$this->qcmAssignments->contains($qcmAssignment)) {
            $this->qcmAssignments->add($qcmAssignment);
            $qcmAssignment->setQcm($this);
        }

        return $this;
    }

    public function removeQcmAssignment(QcmAssignment $qcmAssignment): static
    {
        if ($this->qcmAssignments->removeElement($qcmAssignment)) {
            // set the owning side to null (unless already changed)
            if ($qcmAssignment->getQcm() === $this) {
                $qcmAssignment->setQcm(null);
            }
        }

        return $this;
    }

    public function getMaxPoints(): int
    {
        $sum = 0;
        foreach ($this->questions as $q) $sum += $q->getPointsMax();
        return $sum;
    }

    public function __toString(): string
    {
        return $this->titre;
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
