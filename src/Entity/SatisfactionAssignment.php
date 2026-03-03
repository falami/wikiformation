<?php

namespace App\Entity;


use App\Entity\SatisfactionAttempt;
use App\Repository\SatisfactionAssignmentRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SatisfactionAssignmentRepository::class)]
class SatisfactionAssignment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'satisfactionAssignments')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Session $session = null;

    #[ORM\ManyToOne(inversedBy: 'satisfactionAssignments')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Utilisateur $stagiaire = null;

    #[ORM\ManyToOne(inversedBy: 'satisfactionAssignments')]
    #[ORM\JoinColumn(nullable: false)]
    private ?SatisfactionTemplate $template = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $isRequired = true;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\OneToOne(mappedBy: 'assignment', targetEntity: SatisfactionAttempt::class, cascade: ['persist', 'remove'])]
    private ?SatisfactionAttempt $attempt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'satisfactionAssignementCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\ManyToOne(inversedBy: 'satisfactionAssignmentEntites')]
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

    public function getStagiaire(): ?Utilisateur
    {
        return $this->stagiaire;
    }

    public function setStagiaire(?Utilisateur $stagiaire): static
    {
        $this->stagiaire = $stagiaire;

        return $this;
    }

    public function getTemplate(): ?SatisfactionTemplate
    {
        return $this->template;
    }

    public function setTemplate(?SatisfactionTemplate $template): static
    {
        $this->template = $template;

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

    public function getAttempt(): ?SatisfactionAttempt
    {
        return $this->attempt;
    }

    public function setAttempt(?SatisfactionAttempt $t): static
    {
        $this->attempt = $t;
        if ($t && $t->getAssignment() !== $this) $t->setAssignment($this);
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
