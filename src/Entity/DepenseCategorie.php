<?php

namespace App\Entity;

use App\Repository\DepenseCategorieRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: DepenseCategorieRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_dep_cat_entite_libelle', columns: ['entite_id', 'libelle'])]
#[ORM\Index(name: 'idx_dep_cat_entite', columns: ['entite_id'])]
class DepenseCategorie
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'depenseCategories')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Entite $entite = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private ?string $libelle = '';

    #[ORM\Column(options: ['default' => true])]
    private bool $actif = true;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    /**
     * @var Collection<int, Depense>
     */
    #[ORM\OneToMany(targetEntity: Depense::class, mappedBy: 'categorie')]
    private Collection $depenses;


    // + use App\Enum\DepenseCategorieType;
    #[ORM\Column(length: 20, options: ['default' => 'operating'])]
    private string $type = 'operating';

    #[ORM\Column(options: ['default' => true])]
    private bool $includeInFinanceCharts = true;

    public function __construct()
    {
        $this->dateCreation = new \DateTimeImmutable();
        $this->depenses = new ArrayCollection();
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

    public function getLibelle(): ?string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): static
    {
        $this->libelle = $libelle;

        return $this;
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): static
    {
        $this->actif = $actif;

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

    public function __toString(): string
    {
        return $this->libelle;
    }

    /**
     * @return Collection<int, Depense>
     */
    public function getDepenses(): Collection
    {
        return $this->depenses;
    }

    public function addDepense(Depense $depense): static
    {
        if (!$this->depenses->contains($depense)) {
            $this->depenses->add($depense);
            $depense->setCategorie($this);
        }

        return $this;
    }

    public function removeDepense(Depense $depense): static
    {
        if ($this->depenses->removeElement($depense)) {
            // set the owning side to null (unless already changed)
            if ($depense->getCategorie() === $this) {
                $depense->setCategorie(null);
            }
        }

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }
    public function setType(string $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function isIncludeInFinanceCharts(): bool
    {
        return $this->includeInFinanceCharts;
    }
    public function setIncludeInFinanceCharts(bool $v): static
    {
        $this->includeInFinanceCharts = $v;
        return $this;
    }
}
