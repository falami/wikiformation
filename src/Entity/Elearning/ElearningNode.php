<?php

namespace App\Entity\Elearning;

use App\Entity\Entite;
use App\Entity\Utilisateur;
use App\Enum\NodeType;
use App\Repository\Elearning\ElearningNodeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: ElearningNodeRepository::class)]
#[ORM\Table(name: 'elearning_node')]
#[ORM\UniqueConstraint(name: 'uniq_node_slug_in_course', columns: ['course_id', 'slug'])]
class ElearningNode
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'nodes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ElearningCourse $course = null;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?self $parent = null;

    /** @var Collection<int, self> */
    #[ORM\OneToMany(mappedBy: 'parent', targetEntity: self::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $children;

    #[ORM\Column(enumType: NodeType::class)]
    private NodeType $type = NodeType::CHAPITRE;

    #[ORM\Column(length: 160)]
    #[Assert\NotBlank]
    private string $titre = '';

    #[ORM\Column(length: 160)]
    #[Assert\NotBlank]
    private string $slug = '';

    #[ORM\Column(options: ['unsigned' => true])]
    #[Assert\PositiveOrZero]
    private int $position = 0;

    #[ORM\Column(nullable: true, options: ['unsigned' => true])]
    #[Assert\PositiveOrZero]
    private ?int $dureeMinutes = null;


    #[ORM\Column(options: ['default' => false])]
    private bool $isPublished = false;

    /** @var Collection<int, ElearningBlock> */
    #[ORM\OneToMany(mappedBy: 'node', targetEntity: ElearningBlock::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $blocks;


    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'elearningNodeCreateurs')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Utilisateur $createur = null;

    #[ORM\ManyToOne(inversedBy: 'elearningNodes')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Entite $entite = null;

    #[ORM\Column(options: ['unsigned' => true, 'default' => 1])]
    private int $version = 1;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTime $updatedAt;

    /**
     * @var Collection<int, ElearningNodeProgress>
     */
    #[ORM\OneToMany(targetEntity: ElearningNodeProgress::class, mappedBy: 'node')]
    private Collection $elearningNodeProgress;

    public function __construct()
    {
        $this->dateCreation = new \DateTimeImmutable();
        $this->blocks = new ArrayCollection();
        $this->children = new ArrayCollection();

        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTime();
        $this->elearningNodeProgress = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCourse(): ?ElearningCourse
    {
        return $this->course;
    }

    public function setCourse(?ElearningCourse $course): static
    {
        $this->course = $course;

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

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

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

    public function getDureeMinutes(): ?int
    {
        return $this->dureeMinutes;
    }

    public function setDureeMinutes(?int $dureeMinutes): static
    {
        $this->dureeMinutes = $dureeMinutes;

        return $this;
    }

    public function isPublished(): bool
    {
        return $this->isPublished;
    }

    public function setIsPublished(bool $isPublished): static
    {
        $this->isPublished = $isPublished;

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

    /**
     * @return Collection<int, ElearningBlock>
     */
    public function getBlocks(): Collection
    {
        return $this->blocks;
    }

    public function addBlock(ElearningBlock $block): static
    {
        if (!$this->blocks->contains($block)) {
            $this->blocks->add($block);
            $block->setNode($this);
        }

        return $this;
    }

    public function removeBlock(ElearningBlock $block): static
    {
        if ($this->blocks->removeElement($block)) {
            // set the owning side to null (unless already changed)
            if ($block->getNode() === $this) {
                $block->setNode(null);
            }
        }

        return $this;
    }

    public function getType(): NodeType
    {
        return $this->type;
    }
    public function setType(NodeType $t): self
    {
        $this->type = $t;
        return $this;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }
    public function setParent(?self $p): self
    {
        $this->parent = $p;
        return $this;
    }

    /** @return Collection<int, self> */
    public function getChildren(): Collection
    {
        return $this->children;
    }
    public function addChild(self $child): self
    {
        if (!$this->children->contains($child)) {
            $this->children->add($child);
            $child->setParent($this);
        }
        return $this;
    }
    public function removeChild(self $child): self
    {
        $this->children->removeElement($child);
        if ($child->getParent() === $this) {
            $child->setParent(null);
        }
        return $this;
    }

    public function getVersion(): int
    {
        return $this->version;
    }
    public function setVersion(int $v): self
    {
        $this->version = $v;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
    public function setCreatedAt(\DateTimeImmutable $d): self
    {
        $this->createdAt = $d;
        return $this;
    }

    public function getUpdatedAt(): \DateTime
    {
        return $this->updatedAt;
    }
    public function setUpdatedAt(\DateTime $d): self
    {
        $this->updatedAt = $d;
        return $this;
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ($this->getType() === NodeType::SOUS_CHAPITRE && !$this->getParent()) {
            $context->buildViolation('Un sous-chapitre doit avoir un parent.')
                ->atPath('parent')
                ->addViolation();
        }
    }

    /**
     * @return Collection<int, ElearningNodeProgress>
     */
    public function getElearningNodeProgress(): Collection
    {
        return $this->elearningNodeProgress;
    }

    public function addElearningNodeProgress(ElearningNodeProgress $elearningNodeProgress): static
    {
        if (!$this->elearningNodeProgress->contains($elearningNodeProgress)) {
            $this->elearningNodeProgress->add($elearningNodeProgress);
            $elearningNodeProgress->setNode($this);
        }

        return $this;
    }

    public function removeElearningNodeProgress(ElearningNodeProgress $elearningNodeProgress): static
    {
        if ($this->elearningNodeProgress->removeElement($elearningNodeProgress)) {
            // set the owning side to null (unless already changed)
            if ($elearningNodeProgress->getNode() === $this) {
                $elearningNodeProgress->setNode(null);
            }
        }

        return $this;
    }
}
