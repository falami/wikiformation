<?php

namespace App\Entity;

use App\Enum\NodeType;
use App\Repository\FormationContentNodeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: FormationContentNodeRepository::class)]
#[ORM\Table(name: 'formation_content_node')]
#[ORM\UniqueConstraint(name: 'uniq_node_slug_in_formation', columns: ['formation_id', 'slug'])]
class FormationContentNode
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'contentNodes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Formation $formation = null;

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
    #[Assert\Positive]
    private ?int $dureeMinutes = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isPublished = false;

    #[ORM\Column(options: ['unsigned' => true, 'default' => 1])]
    private int $version = 1;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTime $updatedAt;

    /** @var Collection<int, ContentBlock> */
    #[ORM\OneToMany(mappedBy: 'node', targetEntity: ContentBlock::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $blocks;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'formationContentNodeCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\ManyToOne(inversedBy: 'formationContentNodeEntites')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Entite $entite = null;

    public function __construct()
    {
        $this->children = new ArrayCollection();
        $this->blocks   = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTime();
        $this->dateCreation = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return $this->titre ?: 'Node#' . $this->id;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFormation(): ?Formation
    {
        return $this->formation;
    }
    public function setFormation(?Formation $f): self
    {
        $this->formation = $f;
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

    public function getType(): NodeType
    {
        return $this->type;
    }
    public function setType(NodeType $t): self
    {
        $this->type = $t;
        return $this;
    }

    public function getTitre(): string
    {
        return $this->titre;
    }
    public function setTitre(string $t): self
    {
        $this->titre = $t;
        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }
    public function setSlug(string $s): self
    {
        $this->slug = $s;
        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }
    public function setPosition(int $p): self
    {
        $this->position = $p;
        return $this;
    }

    public function getDureeMinutes(): ?int
    {
        return $this->dureeMinutes;
    }
    public function setDureeMinutes(?int $m): self
    {
        $this->dureeMinutes = $m;
        return $this;
    }

    public function isPublished(): bool
    {
        return $this->isPublished;
    }
    public function setIsPublished(bool $v): self
    {
        $this->isPublished = $v;
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

    /** @return Collection<int, ContentBlock> */
    public function getBlocks(): Collection
    {
        return $this->blocks;
    }
    public function addBlock(ContentBlock $b): self
    {
        if (!$this->blocks->contains($b)) {
            $this->blocks->add($b);
            $b->setNode($this);
        }
        return $this;
    }
    public function removeBlock(ContentBlock $b): self
    {
        if ($this->blocks->removeElement($b) && $b->getNode() === $this) {
            $b->setNode(null);
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
