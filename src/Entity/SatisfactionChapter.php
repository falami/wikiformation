<?php
// src/Entity/SatisfactionChapter.php
namespace App\Entity;

use App\Repository\SatisfactionChapterRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SatisfactionChapterRepository::class)]
class SatisfactionChapter
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'chapters')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?SatisfactionTemplate $template = null;

    #[ORM\Column(length: 160)]
    private string $titre = 'Chapitre';

    #[ORM\Column(options: ['unsigned' => true])]
    private int $position = 1;

    /**
     * @var Collection<int, SatisfactionQuestion>
     */
    #[ORM\OneToMany(mappedBy: 'chapter', targetEntity: SatisfactionQuestion::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $questions;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'satisfactionChapterCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\ManyToOne(inversedBy: 'satisfactionChapterEntites')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Entite $entite = null;

    public function __construct()
    {
        $this->questions = new ArrayCollection();
        $this->dateCreation = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTemplate(): ?SatisfactionTemplate
    {
        return $this->template;
    }
    public function setTemplate(?SatisfactionTemplate $t): self
    {
        $this->template = $t;
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

    public function getPosition(): int
    {
        return $this->position;
    }
    public function setPosition(int $position): self
    {
        $this->position = $position;
        return $this;
    }

    /** @return Collection<int, SatisfactionQuestion> */
    public function getQuestions(): Collection
    {
        return $this->questions;
    }

    public function addQuestion(SatisfactionQuestion $q): self
    {
        if (!$this->questions->contains($q)) {
            $this->questions->add($q);
            $q->setChapter($this);

            // ✅ héritage
            if (!$q->getEntite()) {
                $q->setEntite($this->getEntite());
            }
            if (!$q->getCreateur()) {
                $q->setCreateur($this->getCreateur());
            }
        }
        return $this;
    }


    public function removeQuestion(SatisfactionQuestion $q): self
    {
        $this->questions->removeElement($q);
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
