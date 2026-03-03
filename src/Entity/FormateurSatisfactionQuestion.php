<?php

namespace App\Entity;

use App\Repository\FormateurSatisfactionQuestionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Enum\SatisfactionQuestionType;

#[ORM\Entity(repositoryClass: FormateurSatisfactionQuestionRepository::class)]
#[ORM\Table(name: 'formateur_satisfaction_question')]
class FormateurSatisfactionQuestion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'questions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?FormateurSatisfactionChapter $chapter = null;

    #[ORM\Column(length: 255)]
    private string $libelle = 'Question';

    #[ORM\Column(nullable: true, options: ['unsigned' => true])]
    private ?int $maxStars = 10;

    #[ORM\Column(options: ['default' => true])]
    private bool $required = true;

    #[ORM\Column(options: ['unsigned' => true])]
    private int $position = 1;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $help = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $metricKey = null;

    #[ORM\Column(nullable: true, options: ['unsigned' => true])]
    private ?int $metricMax = null;

    #[ORM\Column(nullable: true, options: ['unsigned' => true])]
    private ?int $minValue = null;

    #[ORM\Column(nullable: true, options: ['unsigned' => true])]
    private ?int $maxValeur = null;

    #[ORM\Column(nullable: true)]
    private ?array $choices = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $placeholder = null;


    #[ORM\Column(enumType: SatisfactionQuestionType::class)]
    private SatisfactionQuestionType $type = SatisfactionQuestionType::SCALE;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'formateurSatisfactionQuestionCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\ManyToOne(inversedBy: 'formateurSatisfactionEntites')]
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

    public function getChapter(): ?FormateurSatisfactionChapter
    {
        return $this->chapter;
    }

    public function setChapter(?FormateurSatisfactionChapter $chapter): static
    {
        $this->chapter = $chapter;

        return $this;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): static
    {
        $this->libelle = $libelle;

        return $this;
    }

    public function getMaxStars(): ?int
    {
        return $this->maxStars;
    }

    public function setMaxStars(?int $maxStars): static
    {
        $this->maxStars = $maxStars;

        return $this;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function setRequired(bool $required): static
    {
        $this->required = $required;

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

    public function getHelp(): ?string
    {
        return $this->help;
    }

    public function setHelp(?string $help): static
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


    public function getType(): SatisfactionQuestionType
    {
        return $this->type;
    }

    public function setType(?SatisfactionQuestionType $type): static
    {
        $this->type = $type ?? SatisfactionQuestionType::SCALE;
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
