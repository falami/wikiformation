<?php

namespace App\Entity;

use App\Repository\CategorieRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CategorieRepository::class)]
#[ORM\Table(name: 'categorie')]
#[ORM\UniqueConstraint(name: 'uniq_categorie_entite_slug', columns: ['entite_id', 'slug'])]
class Categorie
{
  #[ORM\Id]
  #[ORM\GeneratedValue]
  #[ORM\Column]
  private ?int $id = null;

  #[ORM\ManyToOne(inversedBy: 'categories')]
  #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
  private ?Entite $entite = null;

  #[ORM\Column(length: 120)]
  #[Assert\NotBlank]
  private ?string $nom = null;

  #[ORM\Column(length: 140)]
  #[Assert\NotBlank]
  private ?string $slug = null;

  // Catégorie parente (ex: Bureautique)
  #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'enfants')]
  #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
  private ?self $parent = null;

  /** @var Collection<int, self> */
  #[ORM\OneToMany(mappedBy: 'parent', targetEntity: self::class)]
  #[ORM\OrderBy(['nom' => 'ASC'])]
  private Collection $enfants;

  /** @var Collection<int, Formation> */
  #[ORM\OneToMany(mappedBy: 'categorie', targetEntity: Formation::class)]
  private Collection $formations;

  // Photo catégorie (nom de fichier)
  #[ORM\Column(length: 255, nullable: true)]
  private ?string $photo = null;

  #[ORM\Column(type: 'boolean', options: ['default' => true])]
  private bool $showOnHome = true;

  #[ORM\Column]
  private ?\DateTimeImmutable $dateCreation = null;

  #[ORM\ManyToOne(inversedBy: 'categorieCreateurs')]
  #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
  private ?Utilisateur $createur = null;

  public function __construct()
  {
    $this->enfants = new ArrayCollection();
    $this->formations = new ArrayCollection();
    $this->dateCreation = new \DateTimeImmutable();
  }

  public function __toString(): string
  {
    // utile dans les selects
    $path = $this->parent ? ($this->parent->getNom() . ' > ') : '';
    return $path . ($this->nom ?? '');
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

  public function getNom(): ?string
  {
    return $this->nom;
  }
  public function setNom(string $nom): static
  {
    $this->nom = $nom;
    return $this;
  }

  public function getSlug(): ?string
  {
    return $this->slug;
  }
  public function setSlug(string $slug): static
  {
    $this->slug = $slug;
    return $this;
  }

  public function getParent(): ?self
  {
    return $this->parent;
  }
  public function setParent(?self $parent): static
  {
    $this->parent = $parent;
    return $this;
  }

  /** @return Collection<int, self> */
  public function getEnfants(): Collection
  {
    return $this->enfants;
  }

  public function getPhoto(): ?string
  {
    return $this->photo;
  }
  public function setPhoto(?string $photo): static
  {
    $this->photo = $photo;
    return $this;
  }

  /** @return Collection<int, Formation> */
  public function getFormations(): Collection
  {
    return $this->formations;
  }

  public function isShowOnHome(): bool
  {
    return $this->showOnHome;
  }

  public function setShowOnHome(bool $showOnHome): static
  {
    $this->showOnHome = $showOnHome;
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
