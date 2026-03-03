<?php

namespace App\Entity;

use App\Repository\FormateurSatisfactionAssignmentRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FormateurSatisfactionAssignmentRepository::class)]
#[ORM\Table(name: 'formateur_satisfaction_assignment')]
#[ORM\UniqueConstraint(name: 'uniq_formateur_eval', columns: ['session_id', 'formateur_id', 'template_id'])]
class FormateurSatisfactionAssignment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'formateurSatisfactionAssignments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Session $session = null;

    #[ORM\ManyToOne(inversedBy: 'formateurSatisfactionAssignments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Utilisateur $formateur = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $isRequired = true;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\OneToOne(mappedBy: 'assignment', targetEntity: FormateurSatisfactionAttempt::class, cascade: ['persist', 'remove'])]
    private ?FormateurSatisfactionAttempt $attempt = null;

    #[ORM\ManyToOne(inversedBy: 'assignments')]
    #[ORM\JoinColumn(nullable: false)]
    private ?FormateurSatisfactionTemplate $template = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'formateurSatisfactionAssignementCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\ManyToOne(inversedBy: 'formateurSatisfactionAssignementEntites')]
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

    public function getSession(): ?Session
    {
        return $this->session;
    }

    public function setSession(?Session $session): static
    {
        $this->session = $session;

        return $this;
    }

    public function getFormateur(): ?Utilisateur
    {
        return $this->formateur;
    }

    public function setFormateur(?Utilisateur $formateur): static
    {
        $this->formateur = $formateur;

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

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getAttempt(): ?FormateurSatisfactionAttempt
    {
        return $this->attempt;
    }

    public function setAttempt(?FormateurSatisfactionAttempt $attempt): static
    {
        // unset the owning side of the relation if necessary
        if ($attempt === null && $this->attempt !== null) {
            $this->attempt->setAssignment(null);
        }

        // set the owning side of the relation if necessary
        if ($attempt !== null && $attempt->getAssignment() !== $this) {
            $attempt->setAssignment($this);
        }

        $this->attempt = $attempt;

        return $this;
    }

    public function getTemplate(): ?FormateurSatisfactionTemplate
    {
        return $this->template;
    }

    public function setTemplate(?FormateurSatisfactionTemplate $template): static
    {
        $this->template = $template;

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
