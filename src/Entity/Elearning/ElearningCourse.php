<?php

namespace App\Entity\Elearning;

use App\Entity\Entite;
use App\Entity\Utilisateur;
use App\Repository\Elearning\ElearningCourseRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ElearningCourseRepository::class)]
#[ORM\Table(name: 'elearning_course')]
#[ORM\UniqueConstraint(name: 'uniq_course_entite_slug', columns: ['entite_id', 'slug'])]
class ElearningCourse
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'elearningCourses')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Entite $entite = null;

    #[ORM\ManyToOne(inversedBy: 'elearningCourseCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $titre = '';

    #[ORM\Column(length: 160)]
    private string $slug = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(nullable: true)]
    private ?int $dureeMinutes = null;

    #[ORM\Column(options: ['unsigned' => true])]
    private int $prixCents = 0;

    #[ORM\Column]
    private bool $isPublic = false;

    #[ORM\Column]
    private bool $isPublished = true;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photoCouverture = null;

    /**
     * @var Collection<int, ElearningNode>
     */
    #[ORM\OneToMany(
        mappedBy: 'course',
        targetEntity: ElearningNode::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $nodes;

    /**
     * @var Collection<int, ElearningEnrollment>
     */
    #[ORM\OneToMany(targetEntity: ElearningEnrollment::class, mappedBy: 'course')]
    private Collection $elearningEnrollments;

    /**
     * @var Collection<int, ElearningOrderItem>
     */
    #[ORM\OneToMany(targetEntity: ElearningOrderItem::class, mappedBy: 'course')]
    private Collection $elearningOrderItems;


    public function __construct()
    {
        $this->dateCreation = new \DateTimeImmutable();
        $this->nodes = new ArrayCollection();
        $this->elearningEnrollments = new ArrayCollection();
        $this->elearningOrderItems = new ArrayCollection();
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

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

    public function getPrixCents(): int
    {
        return $this->prixCents;
    }

    public function setPrixCents(int $prixCents): static
    {
        $this->prixCents = $prixCents;

        return $this;
    }

    public function isPublic(): bool
    {
        return $this->isPublic;
    }

    public function setIsPublic(bool $isPublic): static
    {
        $this->isPublic = $isPublic;

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

    public function getPhotoCouverture(): ?string
    {
        return $this->photoCouverture;
    }

    public function setPhotoCouverture(?string $photoCouverture): static
    {
        $this->photoCouverture = $photoCouverture;

        return $this;
    }

    /**
     * @return Collection<int, ElearningNode>
     */
    public function getNodes(): Collection
    {
        return $this->nodes;
    }

    public function addNode(ElearningNode $node): static
    {
        if (!$this->nodes->contains($node)) {
            $this->nodes->add($node);
            $node->setCourse($this);
        }

        return $this;
    }

    public function removeNode(ElearningNode $node): static
    {
        if ($this->nodes->removeElement($node)) {
            // set the owning side to null (unless already changed)
            if ($node->getCourse() === $this) {
                $node->setCourse(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ElearningEnrollment>
     */
    public function getElearningEnrollments(): Collection
    {
        return $this->elearningEnrollments;
    }

    public function addElearningEnrollment(ElearningEnrollment $elearningEnrollment): static
    {
        if (!$this->elearningEnrollments->contains($elearningEnrollment)) {
            $this->elearningEnrollments->add($elearningEnrollment);
            $elearningEnrollment->setCourse($this);
        }

        return $this;
    }

    public function removeElearningEnrollment(ElearningEnrollment $elearningEnrollment): static
    {
        if ($this->elearningEnrollments->removeElement($elearningEnrollment)) {
            // set the owning side to null (unless already changed)
            if ($elearningEnrollment->getCourse() === $this) {
                $elearningEnrollment->setCourse(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ElearningOrderItem>
     */
    public function getElearningOrderItems(): Collection
    {
        return $this->elearningOrderItems;
    }

    public function addElearningOrderItem(ElearningOrderItem $elearningOrderItem): static
    {
        if (!$this->elearningOrderItems->contains($elearningOrderItem)) {
            $this->elearningOrderItems->add($elearningOrderItem);
            $elearningOrderItem->setCourse($this);
        }

        return $this;
    }

    public function removeElearningOrderItem(ElearningOrderItem $elearningOrderItem): static
    {
        if ($this->elearningOrderItems->removeElement($elearningOrderItem)) {
            // set the owning side to null (unless already changed)
            if ($elearningOrderItem->getCourse() === $this) {
                $elearningOrderItem->setCourse(null);
            }
        }

        return $this;
    }
}
