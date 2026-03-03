<?php

namespace App\Entity\Elearning;

use App\Entity\Entite;
use App\Entity\Quiz;
use App\Entity\Utilisateur;
use App\Repository\Elearning\ElearningBlockRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Enum\BlockType;

#[ORM\Entity(repositoryClass: ElearningBlockRepository::class)]
#[ORM\Table(name: 'elearning_block')]
class ElearningBlock
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;


    #[ORM\ManyToOne(inversedBy: 'blocks')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ElearningNode $node = null;

    #[ORM\Column(enumType: BlockType::class)]
    private BlockType $type = BlockType::RICHTEXT;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $content = null;

    #[ORM\OneToOne(inversedBy: 'elearningBlock', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Quiz $quiz = null;



    #[ORM\Column(length: 255, nullable: true)]
    private ?string $mediaFilename = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $mediaUrl = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $meta = null;

    #[ORM\Column(options: ['unsigned' => true])]
    private int $position = 0;

    #[ORM\Column(options: ['default' => false])]
    private bool $isRequired = false;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'elearningBlockCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\ManyToOne(inversedBy: 'elearningBlocks')]
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

    public function getNode(): ?ElearningNode
    {
        return $this->node;
    }

    public function setNode(?ElearningNode $node): static
    {
        $this->node = $node;

        return $this;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(?string $content): static
    {
        $this->content = $content;

        return $this;
    }

    public function getMediaFilename(): ?string
    {
        return $this->mediaFilename;
    }

    public function setMediaFilename(?string $mediaFilename): static
    {
        $this->mediaFilename = $mediaFilename;

        return $this;
    }

    public function getMediaUrl(): ?string
    {
        return $this->mediaUrl;
    }

    public function setMediaUrl(?string $mediaUrl): static
    {
        $this->mediaUrl = $mediaUrl;

        return $this;
    }

    public function getMeta(): ?array
    {
        return $this->meta;
    }

    public function setMeta(?array $meta): static
    {
        $this->meta = $meta;

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

    public function isRequired(): bool
    {
        return $this->isRequired;
    }

    public function setIsRequired(bool $isRequired): static
    {
        $this->isRequired = $isRequired;

        return $this;
    }

    public function getQuiz(): ?Quiz
    {
        return $this->quiz;
    }

    public function setQuiz(?Quiz $quiz): static
    {
        $this->quiz = $quiz;

        if ($quiz !== null && $quiz->getElearningBlock() !== $this) {
            $quiz->setElearningBlock($this);
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

    public function getType(): BlockType
    {
        return $this->type;
    }
    public function setType(BlockType $t): self
    {
        $this->type = $t;
        return $this;
    }

    public function getMetaArray(): array
    {
        return $this->meta ?? [];
    }
}
