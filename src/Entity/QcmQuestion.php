<?php

namespace App\Entity;

use App\Enum\QcmQuestionType;
use App\Repository\QcmQuestionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Validator\Constraints as Assert;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: QcmQuestionRepository::class)]
#[ORM\Index(columns: ['ordre'])]
class QcmQuestion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'questions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Qcm $qcm = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $ordre = null;

    #[ORM\Column(enumType: QcmQuestionType::class)]
    private QcmQuestionType $type = QcmQuestionType::SINGLE;

    #[ORM\Column(type: Types::TEXT)]
    private string $enonce = '';

    #[Assert\NotNull(message: 'Veuillez renseigner le nombre de points pour cette question.')]
    #[Assert\PositiveOrZero(message: 'Le nombre de points doit être positif ou nul.')]
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $pointsMax = null;


    #[ORM\Column(length: 255, nullable: true)]
    private ?string $image = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $videoUrl = null;

    /**
     * @var Collection<int, QcmOption>
     */
    #[ORM\OneToMany(
        targetEntity: QcmOption::class,
        mappedBy: 'question',
        orphanRemoval: true,
        cascade: ['persist']
    )]
    #[ORM\OrderBy(['ordre' => 'ASC', 'id' => 'ASC'])]
    private Collection $options;



    /**
     * @var Collection<int, QcmAnswer>
     */
    #[ORM\OneToMany(targetEntity: QcmAnswer::class, mappedBy: 'question')]
    private Collection $qcmAnswers;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'qcmQuestionCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\ManyToOne(inversedBy: 'qcmQuestionEntites')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Entite $entite = null;

    public function __construct()
    {
        $this->options = new ArrayCollection();
        $this->qcmAnswers = new ArrayCollection();
        $this->dateCreation = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getQcm(): ?Qcm
    {
        return $this->qcm;
    }

    public function setQcm(?Qcm $qcm): static
    {
        $this->qcm = $qcm;

        return $this;
    }

    public function getOrdre(): ?int
    {
        return $this->ordre;
    }

    public function setOrdre(?int $ordre): self
    {
        $this->ordre = $ordre ?? 0;
        return $this;
    }


    public function getEnonce(): string
    {
        return $this->enonce;
    }

    public function setEnonce(?string $enonce): static
    {
        $this->enonce = (string)($enonce ?? '');
        return $this;
    }


    public function getPointsMax(): int
    {
        return $this->pointsMax ?? 0;
    }


    public function setPointsMax(?int $pointsMax): self
    {
        $this->pointsMax = $pointsMax;
        return $this;
    }


    public function getImage(): ?string
    {
        return $this->image;
    }

    public function setImage(?string $image): static
    {
        $this->image = $image;

        return $this;
    }

    public function getVideoUrl(): ?string
    {
        return $this->videoUrl;
    }

    public function setVideoUrl(?string $videoUrl): static
    {
        $this->videoUrl = $videoUrl;

        return $this;
    }

    /**
     * @return Collection<int, QcmOption>
     */
    public function getOptions(): Collection
    {
        return $this->options;
    }

    public function addOption(QcmOption $option): static
    {
        if (!$this->options->contains($option)) {
            $this->options->add($option);
            $option->setQuestion($this);

            // ✅ defaults robustes
            if (!$option->getEntite()) {
                $option->setEntite($this->getEntite());
            }
            if (!$option->getCreateur()) {
                $option->setCreateur($this->getCreateur());
            }
        }

        return $this;
    }


    public function removeOption(QcmOption $option): static
    {
        if ($this->options->removeElement($option)) {
            // set the owning side to null (unless already changed)
            if ($option->getQuestion() === $this) {
                $option->setQuestion(null);
            }
        }

        return $this;
    }

    public function getType(): QcmQuestionType
    {
        return $this->type;
    }

    public function setType(?QcmQuestionType $type): static
    {
        $this->type = $type ?? QcmQuestionType::SINGLE; // ✅ fallback
        return $this;
    }


    /**
     * @return Collection<int, QcmAnswer>
     */
    public function getQcmAnswers(): Collection
    {
        return $this->qcmAnswers;
    }

    public function addQcmAnswer(QcmAnswer $qcmAnswer): static
    {
        if (!$this->qcmAnswers->contains($qcmAnswer)) {
            $this->qcmAnswers->add($qcmAnswer);
            $qcmAnswer->setQuestion($this);
        }

        return $this;
    }

    public function removeQcmAnswer(QcmAnswer $qcmAnswer): static
    {
        if ($this->qcmAnswers->removeElement($qcmAnswer)) {
            // set the owning side to null (unless already changed)
            if ($qcmAnswer->getQuestion() === $this) {
                $qcmAnswer->setQuestion(null);
            }
        }

        return $this;
    }
    /** @return int[] */
    public function getCorrectOptionIds(): array
    {
        $ids = [];
        foreach ($this->options as $o) {
            if ($o->isCorrect() && $o->getId()) $ids[] = $o->getId();
        }
        sort($ids);
        return $ids;
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
