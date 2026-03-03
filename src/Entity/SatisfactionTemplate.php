<?php
// src/Entity/SatisfactionTemplate.php
namespace App\Entity;

use App\Repository\SatisfactionTemplateRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SatisfactionTemplateRepository::class)]
#[ORM\Index(columns: ['is_active'])]
class SatisfactionTemplate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'satisfactionTemplates')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Entite $entite = null;

    #[ORM\Column(length: 160)]
    private string $titre = 'Évaluation de la formation';

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * @var Collection<int, SatisfactionChapter>
     */
    #[ORM\OneToMany(mappedBy: 'template', targetEntity: SatisfactionChapter::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $chapters;


    /**
     * @var Collection<int, SatisfactionAssignment>
     */
    #[ORM\OneToMany(targetEntity: SatisfactionAssignment::class, mappedBy: 'template')]
    private Collection $satisfactionAssignments;


    /**
     * @var Collection<int, Formation>
     */
    #[ORM\OneToMany(mappedBy: 'satisfactionTemplate', targetEntity: Formation::class)]
    private Collection $formations;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'satisfactionTemplateCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    public function __construct()
    {
        $this->chapters = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->formations = new ArrayCollection();
        $this->satisfactionAssignments = new ArrayCollection();
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
    public function setEntite(?Entite $entite): self
    {
        $this->entite = $entite;
        return $this;
    }

    public function getTitre(): string
    {
        return $this->titre;
    }
    public function setTitre(string $titre): self
    {
        $this->titre = $titre;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }
    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }
    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** @return Collection<int, SatisfactionChapter> */
    public function getChapters(): Collection
    {
        return $this->chapters;
    }

    public function addChapter(SatisfactionChapter $c): self
    {
        if (!$this->chapters->contains($c)) {
            $this->chapters->add($c);
            $c->setTemplate($this);

            // ✅ héritage
            if (!$c->getEntite()) {
                $c->setEntite($this->getEntite());
            }
            if (!$c->getCreateur()) {
                $c->setCreateur($this->getCreateur());
            }
        }
        return $this;
    }


    public function removeChapter(SatisfactionChapter $c): self
    {
        $this->chapters->removeElement($c);
        return $this;
    }


    /** @return Collection<int, Formation> */
    public function getFormations(): Collection
    {
        return $this->formations;
    }

    public function addFormation(Formation $f): self
    {
        if (!$this->formations->contains($f)) {
            $this->formations->add($f);
            $f->setSatisfactionTemplate($this);
        }
        return $this;
    }

    public function removeFormation(Formation $f): self
    {
        if ($this->formations->removeElement($f)) {
            if ($f->getSatisfactionTemplate() === $this) {
                $f->setSatisfactionTemplate(null);
            }
        }
        return $this;
    }


    public function isGeneric(): bool
    {
        return $this->formations->isEmpty();
    }

    /**
     * @return Collection<int, SatisfactionAssignment>
     */
    public function getSatisfactionAssignments(): Collection
    {
        return $this->satisfactionAssignments;
    }

    public function addSatisfactionAssignment(SatisfactionAssignment $satisfactionAssignment): static
    {
        if (!$this->satisfactionAssignments->contains($satisfactionAssignment)) {
            $this->satisfactionAssignments->add($satisfactionAssignment);
            $satisfactionAssignment->setTemplate($this);
        }

        return $this;
    }

    public function removeSatisfactionAssignment(SatisfactionAssignment $satisfactionAssignment): static
    {
        if ($this->satisfactionAssignments->removeElement($satisfactionAssignment)) {
            // set the owning side to null (unless already changed)
            if ($satisfactionAssignment->getTemplate() === $this) {
                $satisfactionAssignment->setTemplate(null);
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
}
