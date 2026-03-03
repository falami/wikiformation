<?php

namespace App\Entity;

use App\Repository\PositioningItemRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PositioningItemRepository::class)]
class PositioningItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false)]
    private ?PositioningChapter $chapter = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $label = null;

    #[ORM\Column(options: ['unsigned' => true])]
    private int $position = 0;

    #[ORM\Column(options: ['unsigned' => true])]
    private int $level = 1;

    /**
     * @var Collection<int, PositioningAnswer>
     */
    #[ORM\OneToMany(
        mappedBy: 'item',
        targetEntity: PositioningAnswer::class,
        cascade: ['remove'],
        orphanRemoval: true
    )]
    private Collection $positioningAnswers;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'positioningItemCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\ManyToOne(inversedBy: 'positioningItemEntites')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Entite $entite = null;


    public function __construct()
    {
        $this->positioningAnswers = new ArrayCollection();
        $this->dateCreation = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getChapter(): ?PositioningChapter
    {
        return $this->chapter;
    }

    public function setChapter(?PositioningChapter $chapter): static
    {
        $this->chapter = $chapter;

        return $this;
    }

    public function getLabel(): ?string
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

    public function getLevel(): ?int
    {
        return $this->level;
    }

    public function setLevel(int $level): static
    {
        $this->level = $level;

        return $this;
    }

    /**
     * @return Collection<int, PositioningAnswer>
     */
    public function getPositioningAnswers(): Collection
    {
        return $this->positioningAnswers;
    }

    public function addPositioningAnswer(PositioningAnswer $positioningAnswer): static
    {
        if (!$this->positioningAnswers->contains($positioningAnswer)) {
            $this->positioningAnswers->add($positioningAnswer);
            $positioningAnswer->setItem($this);
        }

        return $this;
    }

    public function removePositioningAnswer(PositioningAnswer $positioningAnswer): static
    {
        if ($this->positioningAnswers->removeElement($positioningAnswer)) {
            // set the owning side to null (unless already changed)
            if ($positioningAnswer->getItem() === $this) {
                $positioningAnswer->setItem(null);
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
