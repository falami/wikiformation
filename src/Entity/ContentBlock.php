<?php

namespace App\Entity;

use App\Enum\BlockType;
use App\Repository\ContentBlockRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ContentBlockRepository::class)]
#[ORM\Table(name: 'content_block')]
class ContentBlock
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'blocks')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?FormationContentNode $node = null;

    #[ORM\Column(enumType: BlockType::class)]
    private BlockType $type = BlockType::RICHTEXT;

    // Texte riche (HTML) ou code
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $content = null;

    // Fichiers/Images/Vidéos : nom de fichier stocké
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $mediaFilename = null;

    // URL vidéo (YouTube/Vimeo) optionnel
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $mediaUrl = null;

    // Données quiz/checklist (JSON)
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $meta = null;

    #[ORM\Column(options: ['unsigned' => true])]
    #[Assert\PositiveOrZero]
    private int $position = 0;

    #[ORM\Column(options: ['default' => false])]
    private bool $isRequired = false;

    /**
     * Un ContentBlock peut embarquer un Quiz.
     * Unidirectionnel : ContentBlock -> Quiz
     * Le quiz est supprimé si le block est supprimé.
     */
    #[ORM\OneToOne(cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Quiz $quiz = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'contentBlockCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\ManyToOne(inversedBy: 'contentBlockEntites')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Entite $entite = null;

    public function __construct()
    {
        $this->dateCreation = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return ($this->type?->value ?? 'block') . '#' . ($this->id ?? 'new');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNode(): ?FormationContentNode
    {
        return $this->node;
    }

    public function setNode(?FormationContentNode $n): self
    {
        $this->node = $n;
        return $this;
    }

    public function getType(): BlockType
    {
        return $this->type;
    }

    public function setType(BlockType $t): self
    {
        $this->type = $t;
        return $this;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(?string $c): self
    {
        $this->content = $c;
        return $this;
    }

    public function getMediaFilename(): ?string
    {
        return $this->mediaFilename;
    }

    public function setMediaFilename(?string $f): self
    {
        $this->mediaFilename = $f;
        return $this;
    }

    public function getMediaUrl(): ?string
    {
        return $this->mediaUrl;
    }

    public function setMediaUrl(?string $u): self
    {
        $this->mediaUrl = $u;
        return $this;
    }

    public function getMeta(): ?array
    {
        return $this->meta;
    }

    public function setMeta(?array $m): self
    {
        $this->meta = $m;
        return $this;
    }

    /**
     * Toujours un tableau (jamais null).
     * Utile en Twig: block.metaArray.xxx
     */
    public function getMetaArray(): array
    {
        return $this->meta ?? [];
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

    public function isRequired(): bool
    {
        return $this->isRequired;
    }

    public function setIsRequired(bool $v): self
    {
        $this->isRequired = $v;
        return $this;
    }

    public function getQuiz(): ?Quiz
    {
        return $this->quiz;
    }

    public function setQuiz(?Quiz $quiz): self
    {
        $this->quiz = $quiz;
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
