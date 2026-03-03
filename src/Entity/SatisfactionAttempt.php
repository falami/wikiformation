<?php

namespace App\Entity;


use App\Repository\SatisfactionAttemptRepository;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Types\Types;

#[ORM\Entity(repositoryClass: SatisfactionAttemptRepository::class)]
class SatisfactionAttempt
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $answers = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\OneToOne(inversedBy: 'attempt', targetEntity: SatisfactionAssignment::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE', unique: true)]
    private ?SatisfactionAssignment $assignment = null;


    // src/Entity/SatisfactionAttempt.php

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $noteGlobale = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $noteFormateur = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $noteSite = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $noteContenu = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $recommendationScore = null;



    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $noteOrganisme = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'satisfactionAttemptCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\ManyToOne(inversedBy: 'satisfactionAttemptEntites')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Entite $entite = null;







    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->dateCreation = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): static
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getSubmittedAt(): ?\DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function setSubmittedAt(?\DateTimeImmutable $submittedAt): static
    {
        $this->submittedAt = $submittedAt;

        return $this;
    }

    public function getAnswers(): array
    {
        return $this->answers ?? [];
    }

    public function setAnswers(?array $answers): static
    {
        $this->answers = $answers;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getAssignment(): ?SatisfactionAssignment
    {
        return $this->assignment;
    }

    public function setAssignment(?SatisfactionAssignment $a): static
    {
        $this->assignment = $a;
        if ($a && $a->getAttempt() !== $this) $a->setAttempt($this);
        return $this;
    }


    public function getNoteGlobale(): ?int
    {
        return $this->noteGlobale;
    }


    public function isSubmitted(): bool
    {
        return $this->submittedAt !== null;
    }

    public function isStarted(): bool
    {
        return $this->startedAt !== null;
    }

    public function getNoteFormateur(): ?int
    {
        return $this->noteFormateur;
    }


    public function getNoteSite(): ?int
    {
        return $this->noteSite;
    }


    public function getNoteContenu(): ?int
    {
        return $this->noteContenu;
    }


    public function getRecommendationScore(): ?int
    {
        return $this->recommendationScore;
    }

    private function clamp(?int $v, int $min, int $max): ?int
    {
        if ($v === null) return null;
        return max($min, min($max, $v));
    }

    public function setRecommendationScore(?int $recommendationScore): static
    {
        // NPS 0..10
        $this->recommendationScore = $this->clamp($recommendationScore, 0, 10);
        return $this;
    }

    public function setNoteGlobale(?int $noteGlobale): static
    {
        $this->noteGlobale = $this->clamp($noteGlobale, 0, 10);
        return $this;
    }
    public function setNoteFormateur(?int $noteFormateur): static
    {
        $this->noteFormateur = $this->clamp($noteFormateur, 0, 10);
        return $this;
    }
    public function setNoteSite(?int $noteSite): static
    {
        $this->noteSite = $this->clamp($noteSite, 0, 10);
        return $this;
    }
    public function setNoteContenu(?int $noteContenu): static
    {
        $this->noteContenu = $this->clamp($noteContenu, 0, 10);
        return $this;
    }


    public function getNoteOrganisme(): ?int
    {
        return $this->noteOrganisme;
    }

    public function setNoteOrganisme(?int $noteOrganisme): static
    {
        $this->noteOrganisme = $this->clamp($noteOrganisme, 0, 10);
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
