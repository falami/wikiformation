<?php

namespace App\Entity;


use App\Enum\QcmAssignmentStatus;
use App\Enum\QcmPhase;
use App\Repository\QcmAssignmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: QcmAssignmentRepository::class)]
class QcmAssignment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'qcmAssignments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Session $session = null;

    #[ORM\ManyToOne(inversedBy: 'qcmAssignments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Inscription $inscription = null;

    #[ORM\ManyToOne(inversedBy: 'qcmAssignments')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Qcm $qcm = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $assignedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $adminFollowUpNote = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $adminFollowUpAt = null;

    #[ORM\ManyToOne(inversedBy: 'qcmAssignments')]
    private ?Utilisateur $adminFollowUpBy = null;


    #[ORM\Column(enumType: QcmPhase::class)]
    private QcmPhase $phase = QcmPhase::PRE;

    #[ORM\Column(enumType: QcmAssignmentStatus::class)]
    private QcmAssignmentStatus $status = QcmAssignmentStatus::ASSIGNED;

    #[ORM\OneToOne(mappedBy: 'assignment', cascade: ['persist', 'remove'])]
    private ?QcmAttempt $attempt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'qcmAssignementCreateur')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\ManyToOne(inversedBy: 'qcmAssignmentEntites')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Entite $entite = null;

    public function __construct()
    {
        $this->assignedAt = new \DateTimeImmutable();
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

    public function getPhase(): QcmPhase
    {
        return $this->phase;
    }
    public function setPhase(QcmPhase $phase): static
    {
        $this->phase = $phase;
        return $this;
    }

    public function getStatus(): QcmAssignmentStatus
    {
        return $this->status;
    }
    public function setStatus(QcmAssignmentStatus $status): static
    {
        $this->status = $status;
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

    public function getQcm(): ?Qcm
    {
        return $this->qcm;
    }

    public function setQcm(?Qcm $qcm): static
    {
        $this->qcm = $qcm;

        return $this;
    }

    public function getAssignedAt(): ?\DateTimeImmutable
    {
        return $this->assignedAt;
    }

    public function setAssignedAt(\DateTimeImmutable $assignedAt): static
    {
        $this->assignedAt = $assignedAt;

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

    public function getAdminFollowUpNote(): ?string
    {
        return $this->adminFollowUpNote;
    }

    public function setAdminFollowUpNote(?string $adminFollowUpNote): static
    {
        $this->adminFollowUpNote = $adminFollowUpNote;

        return $this;
    }

    public function setAdminFollowUp(string $note, Utilisateur $admin): static
    {
        $this->adminFollowUpNote = trim($note);
        $this->adminFollowUpAt = new \DateTimeImmutable();
        $this->adminFollowUpBy = $admin;
        return $this;
    }

    public function getAdminFollowUpAt(): ?\DateTimeImmutable
    {
        return $this->adminFollowUpAt;
    }

    public function setAdminFollowUpAt(?\DateTimeImmutable $adminFollowUpAt): static
    {
        $this->adminFollowUpAt = $adminFollowUpAt;

        return $this;
    }

    public function getAdminFollowUpBy(): ?Utilisateur
    {
        return $this->adminFollowUpBy;
    }

    public function setAdminFollowUpBy(?Utilisateur $adminFollowUpBy): static
    {
        $this->adminFollowUpBy = $adminFollowUpBy;

        return $this;
    }

    public function isSubmitted(): bool
    {
        return \in_array($this->status, [QcmAssignmentStatus::SUBMITTED, QcmAssignmentStatus::REVIEW_REQUIRED, QcmAssignmentStatus::VALIDATED], true);
    }

    public function needsFollowUp(): bool
    {
        return $this->status === QcmAssignmentStatus::REVIEW_REQUIRED;
    }

    public function getAttempt(): ?QcmAttempt
    {
        return $this->attempt;
    }

    public function setAttempt(QcmAttempt $attempt): static
    {
        // set the owning side of the relation if necessary
        if ($attempt->getAssignment() !== $this) {
            $attempt->setAssignment($this);
        }

        $this->attempt = $attempt;

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
