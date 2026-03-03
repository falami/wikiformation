<?php
// src/Entity/PositioningAssignment.php

namespace App\Entity;

use App\Repository\PositioningAssignmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PositioningAssignmentRepository::class)]
#[ORM\Table(name: 'positioning_assignment')]
#[ORM\UniqueConstraint(
  name: 'uniq_assignment_inscription_questionnaire',
  columns: ['inscription_id', 'questionnaire_id']
)]
class PositioningAssignment
{
  #[ORM\Id]
  #[ORM\GeneratedValue]
  #[ORM\Column]
  private ?int $id = null;

  #[ORM\ManyToOne(inversedBy: 'positioningAssignments')]
  #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
  private ?PositioningQuestionnaire $questionnaire = null;

  #[ORM\ManyToOne(inversedBy: 'positioningAssignments')]
  #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
  private ?Utilisateur $stagiaire = null;

  // Dans ton cas "lié à une session via inscription" -> peut être nullable en théorie,
  // mais si tu veux forcer, mets nullable:false
  #[ORM\ManyToOne(inversedBy: 'positioningAssignments')]
  #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
  private ?Inscription $inscription = null;

  #[ORM\ManyToOne(inversedBy: 'positioningAssignments')]
  #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
  private ?Session $session = null;

  #[ORM\Column(options: ['default' => false])]
  private bool $isRequired = false;

  #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
  private \DateTimeImmutable $createdAt;

  #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
  private ?\DateTimeImmutable $linkedAt = null;

  #[ORM\OneToOne(mappedBy: 'assignment', targetEntity: PositioningAttempt::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
  private ?PositioningAttempt $attempt = null;

  #[ORM\ManyToOne(inversedBy: 'positioningAssignementsFormateur')]
  private ?Utilisateur $evaluator = null;

  #[ORM\Column(nullable: true)]
  private ?\DateTimeImmutable $evaluatorAssignedAt = null;

  #[ORM\Column]
  private ?\DateTimeImmutable $dateCreation = null;

  #[ORM\ManyToOne(inversedBy: 'positioningAssignementCreateurs')]
  #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
  private ?Utilisateur $createur = null;

  #[ORM\ManyToOne(inversedBy: 'positioningAssignmentEntites')]
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

  public function getQuestionnaire(): ?PositioningQuestionnaire
  {
    return $this->questionnaire;
  }
  public function setQuestionnaire(?PositioningQuestionnaire $q): static
  {
    $this->questionnaire = $q;
    return $this;
  }

  public function getStagiaire(): ?Utilisateur
  {
    return $this->stagiaire;
  }
  public function setStagiaire(?Utilisateur $u): static
  {
    $this->stagiaire = $u;
    return $this;
  }

  public function getInscription(): ?Inscription
  {
    return $this->inscription;
  }
  public function setInscription(?Inscription $i): static
  {
    $this->inscription = $i;
    return $this;
  }

  public function getSession(): ?Session
  {
    return $this->session;
  }
  public function setSession(?Session $s): static
  {
    $this->session = $s;
    return $this;
  }

  public function isRequired(): bool
  {
    return $this->isRequired;
  }
  public function setIsRequired(bool $v): static
  {
    $this->isRequired = $v;
    return $this;
  }

  public function getCreatedAt(): \DateTimeImmutable
  {
    return $this->createdAt;
  }

  public function getLinkedAt(): ?\DateTimeImmutable
  {
    return $this->linkedAt;
  }
  public function setLinkedAt(?\DateTimeImmutable $d): static
  {
    $this->linkedAt = $d;
    return $this;
  }

  public function getAttempt(): ?PositioningAttempt
  {
    return $this->attempt;
  }

  public function setAttempt(?PositioningAttempt $a): static
  {
    $this->attempt = $a;
    // synchro inverse
    if ($a && $a->getAssignment() !== $this) {
      $a->setAssignment($this);
    }
    return $this;
  }

  public function getEvaluator(): ?Utilisateur
  {
    return $this->evaluator;
  }

  public function setEvaluator(?Utilisateur $evaluator): static
  {
    $this->evaluator = $evaluator;
    $this->evaluatorAssignedAt = $evaluator ? new \DateTimeImmutable() : null;
    return $this;
  }

  public function getEvaluatorAssignedAt(): ?\DateTimeImmutable
  {
    return $this->evaluatorAssignedAt;
  }

  public function setEvaluatorAssignedAt(?\DateTimeImmutable $evaluatorAssignedAt): static
  {
    $this->evaluatorAssignedAt = $evaluatorAssignedAt;

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
