<?php

namespace App\Entity;

use App\Repository\SessionPositioningRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SessionPositioningRepository::class)]
#[ORM\Table(name: 'session_positioning')]
#[ORM\UniqueConstraint(name: 'uniq_session_questionnaire', columns: ['session_id', 'questionnaire_id'])]
class SessionPositioning

{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'sessionPositionings')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Session $session = null;


    #[ORM\ManyToOne(inversedBy: 'sessionPositionings')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?PositioningQuestionnaire $questionnaire = null;


    #[ORM\Column(options: ['default' => false])]
    private bool $isRequired = false;


    #[ORM\Column(options: ['unsigned' => true])]
    private int $position = 0;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'sessionPositioningCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\ManyToOne(inversedBy: 'sessionPositioningEntites')]
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

    public function getSession(): ?Session
    {
        return $this->session;
    }

    public function setSession(?Session $session): static
    {
        $this->session = $session;

        return $this;
    }

    public function getQuestionnaire(): ?PositioningQuestionnaire
    {
        return $this->questionnaire;
    }

    public function setQuestionnaire(?PositioningQuestionnaire $questionnaire): static
    {
        $this->questionnaire = $questionnaire;

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

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

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
