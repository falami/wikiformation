<?php
// src/Entity/SatisfactionQuestion.php
namespace App\Entity;

use App\Enum\SatisfactionQuestionType;
use App\Repository\SatisfactionQuestionRepository;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Types\Types;

#[ORM\Entity(repositoryClass: SatisfactionQuestionRepository::class)]
class SatisfactionQuestion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'questions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?SatisfactionChapter $chapter = null;

    #[ORM\Column(length: 255)]
    private string $libelle = 'Question';

    #[ORM\Column(enumType: SatisfactionQuestionType::class)]
    private SatisfactionQuestionType $type = SatisfactionQuestionType::STARS;

    // pour les étoiles : nombre max (5, 10, etc.)
    #[ORM\Column(nullable: true, options: ['unsigned' => true])]
    private ?int $maxStars = 5;

    // obligatoire ?
    #[ORM\Column(options: ['default' => true])]
    private bool $required = true;

    #[ORM\Column(options: ['unsigned' => true])]
    private int $position = 1;

    // aide / placeholder
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $help = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $metricKey = null;

    #[ORM\Column(nullable: true, options: ['unsigned' => true])]
    private ?int $metricMax = null;

    #[ORM\Column(nullable: true, options: ['unsigned' => true])]
    private ?int $minValue = null;

    #[ORM\Column(nullable: true, options: ['unsigned' => true])]
    private ?int $maxValeur = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $choices = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $placeholder = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'satisfactionQuestionCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\ManyToOne(inversedBy: 'satisfactionQuestionEntites')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Entite $entite = null;

    public function __construct()
    {
        $this->dateCreation = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getChapter(): ?SatisfactionChapter
    {
        return $this->chapter;
    }
    public function setChapter(?SatisfactionChapter $c): self
    {
        $this->chapter = $c;
        return $this;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }
    public function setLibelle(string $libelle): self
    {
        $this->libelle = $libelle;
        return $this;
    }

    public function getType(): SatisfactionQuestionType
    {
        return $this->type;
    }
    public function setType(SatisfactionQuestionType $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function getMaxStars(): ?int
    {
        return $this->maxStars;
    }
    public function setMaxStars(?int $maxStars): self
    {
        $this->maxStars = $maxStars;
        return $this;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }
    public function setRequired(bool $required): self
    {
        $this->required = $required;
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

    public function getHelp(): ?string
    {
        return $this->help;
    }
    public function setHelp(?string $help): self
    {
        $this->help = $help;
        return $this;
    }

    public function getMetricKey(): ?string
    {
        return $this->metricKey;
    }

    public function setMetricKey(?string $metricKey): static
    {
        $this->metricKey = $metricKey;

        return $this;
    }

    public function getMetricMax(): ?int
    {
        return $this->metricMax;
    }

    public function setMetricMax(?int $metricMax): static
    {
        $this->metricMax = $metricMax;

        return $this;
    }

    public function getMinValue(): ?int
    {
        return $this->minValue;
    }

    public function setMinValue(?int $minValue): static
    {
        $this->minValue = $minValue;

        return $this;
    }

    public function getMaxValeur(): ?int
    {
        return $this->maxValeur;
    }

    public function setMaxValeur(?int $maxValeur): static
    {
        $this->maxValeur = $maxValeur;

        return $this;
    }

    public function getChoices(): ?array
    {
        return $this->choices;
    }

    public function setChoices(?array $choices): static
    {
        $this->choices = $choices;

        return $this;
    }

    public function getPlaceholder(): ?string
    {
        return $this->placeholder;
    }

    public function setPlaceholder(?string $placeholder): static
    {
        $this->placeholder = $placeholder;

        return $this;
    }
    public function getMaxValue(): ?int
    {
        return $this->maxValeur;
    }

    public function setMaxValue(?int $max): static
    {
        $this->maxValeur = $max;
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
