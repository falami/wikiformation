<?php

namespace App\Entity;

use App\Repository\QuestionnaireSatisfactionRepository;
use Doctrine\ORM\Mapping as ORM;
use App\Enum\SatisfactionType;

#[ORM\Entity(repositoryClass: QuestionnaireSatisfactionRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_sat_once', columns: ['entite_id', 'session_id', 'stagiaire_id', 'type'])]
class QuestionnaireSatisfaction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'questionnaireSatisfactions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Session $session = null;

    #[ORM\ManyToOne(inversedBy: 'questionnaireSatisfactions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Inscription $inscription = null;

    #[ORM\ManyToOne(inversedBy: 'questionnaireSatisfactions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Utilisateur $stagiaire = null;

    #[ORM\ManyToOne(inversedBy: 'questionnaireSatisfactions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Entite $entite = null;

    #[ORM\Column(enumType: SatisfactionType::class)]
    private SatisfactionType $type; // A_CHAUD, A_FROID

    #[ORM\Column]
    private array $reponses = [];

    #[ORM\Column(nullable: true)]
    private ?int $noteGlobale = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]

    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'questionnaireSatisfactionCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    public function __construct()
    {
        $this->dateCreation = new \DateTimeImmutable();
    }


    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSession(): ?Session
    {
        return $this->session;
    }

    public function setSession(?Session $session): static
    {
        $this->session = $session;

        return $this;
    }

    public function getInscription(): ?Inscription
    {
        return $this->inscription;
    }

    public function setInscription(?Inscription $inscription): static
    {
        $this->inscription = $inscription;

        return $this;
    }

    public function getStagiaire(): ?Utilisateur
    {
        return $this->stagiaire;
    }

    public function setStagiaire(?Utilisateur $stagiaire): static
    {
        $this->stagiaire = $stagiaire;

        return $this;
    }

    public function getReponses(): array
    {
        return $this->reponses;
    }

    public function setReponses(array $reponses): static
    {
        $this->reponses = $reponses;

        return $this;
    }

    public function getNoteGlobale(): ?int
    {
        return $this->noteGlobale;
    }

    public function setNoteGlobale(?int $noteGlobale): static
    {
        $this->noteGlobale = $noteGlobale;

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



    public function getType(): SatisfactionType
    {
        return $this->type;
    }

    public function setType(SatisfactionType $type): static
    {
        $this->type = $type;

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


    public function isSubmitted(): bool
    {
        return $this->submittedAt !== null;
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
