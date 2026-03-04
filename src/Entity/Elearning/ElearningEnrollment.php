<?php

namespace App\Entity\Elearning;

use App\Entity\Entite;
use App\Entity\Utilisateur;
use App\Repository\Elearning\ElearningEnrollmentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use App\Enum\EnrollmentStatus;

#[ORM\Entity(repositoryClass: ElearningEnrollmentRepository::class)]
#[ORM\Table(name: 'elearning_enrollment')]
#[ORM\UniqueConstraint(name: 'uniq_enroll_course_user', columns: ['course_id', 'stagiaire_id'])]
class ElearningEnrollment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'elearningEnrollments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ElearningCourse $course = null;

    #[ORM\ManyToOne(inversedBy: 'elearningEnrollments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Utilisateur $stagiaire = null;

    #[ORM\Column(enumType: EnrollmentStatus::class)]
    private EnrollmentStatus $status = EnrollmentStatus::ACTIVE;

    #[ORM\Column]
    private \DateTimeImmutable $assignedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startsAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $endsAt = null;

    // % progression (0..100)
    #[ORM\Column(options: ['unsigned' => true, 'default' => 0])]
    private int $progressPct = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\ManyToOne(inversedBy: 'elearningEnrollments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Entite $entite = null;

    #[ORM\ManyToOne(inversedBy: 'elearningEnrollmentCreateur')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'elearningEnrollments')]
    private ?ElearningOrder $newOrder = null;

    /**
     * @var Collection<int, ElearningNodeProgress>
     */
    #[ORM\OneToMany(targetEntity: ElearningNodeProgress::class, mappedBy: 'enrollment')]
    private Collection $elearningNodeProgress;


    public function __construct()
    {
        $this->dateCreation = new \DateTimeImmutable();
        $this->assignedAt = new \DateTimeImmutable();
        $this->elearningNodeProgress = new ArrayCollection();
    }

    public function isActiveNow(?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();

        if ($this->status !== EnrollmentStatus::ACTIVE) return false;
        if ($this->startsAt && $now < $this->startsAt) return false;
        if ($this->endsAt && $now > $this->endsAt) return false;

        return true;
    }

    public function getId(): ?int
    {
        return $this->id;
    }


    public function getCourse(): ?ElearningCourse
    {
        return $this->course;
    }

    public function setCourse(?ElearningCourse $course): static
    {
        $this->course = $course;

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

    public function getAssignedAt(): \DateTimeImmutable
    {
        return $this->assignedAt;
    }

    public function setAssignedAt(\DateTimeImmutable $assignedAt): static
    {
        $this->assignedAt = $assignedAt;

        return $this;
    }

    public function getStartsAt(): ?\DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function setStartsAt(?\DateTimeImmutable $startsAt): static
    {
        $this->startsAt = $startsAt;

        return $this;
    }

    public function getEndsAt(): ?\DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function setEndsAt(?\DateTimeImmutable $endsAt): static
    {
        $this->endsAt = $endsAt;

        return $this;
    }

    public function getProgressPct(): int
    {
        return $this->progressPct;
    }

    public function setProgressPct(int $progressPct): static
    {
        $this->progressPct = $progressPct;

        return $this;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): static
    {
        $this->completedAt = $completedAt;

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

    public function getCreateur(): ?Utilisateur
    {
        return $this->createur;
    }

    public function setCreateur(?Utilisateur $createur): static
    {
        $this->createur = $createur;

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

    public function getStatus(): EnrollmentStatus
    {
        return $this->status;
    }
    public function setStatus(EnrollmentStatus $s): self
    {
        $this->status = $s;
        return $this;
    }

    public function getNewOrder(): ?ElearningOrder
    {
        return $this->newOrder;
    }

    public function setNewOrder(?ElearningOrder $newOrder): static
    {
        $this->newOrder = $newOrder;

        return $this;
    }

    // App\Entity\Elearning\ElearningEnrollment.php
    
    public function getComputedState(?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable();

        if ($this->status !== EnrollmentStatus::ACTIVE) {
            return match ($this->status) {
                EnrollmentStatus::UPCOMING   => 'upcoming',
                EnrollmentStatus::EXPIRED    => 'expired',
                EnrollmentStatus::SUSPENDED  => 'suspended',
                EnrollmentStatus::COMPLETED  => 'completed',
                default                      => 'inactive',
            };
        }

        if ($this->startsAt && $now < $this->startsAt) return 'upcoming';
        if ($this->endsAt && $now > $this->endsAt) return 'expired';

        return 'active';
    }

    public function getComputedStateLabel(?\DateTimeImmutable $now = null): string
    {
        return match ($this->getComputedState($now)) {
            'active'    => 'Actif',
            'upcoming'  => 'À venir',
            'expired'   => 'Expiré',
            'suspended' => 'Suspendu',
            'completed' => 'Terminé',
            default     => 'Inactif',
        };
    }

    /**
     * @return Collection<int, ElearningNodeProgress>
     */
    public function getElearningNodeProgress(): Collection
    {
        return $this->elearningNodeProgress;
    }

    public function addElearningNodeProgress(ElearningNodeProgress $elearningNodeProgress): static
    {
        if (!$this->elearningNodeProgress->contains($elearningNodeProgress)) {
            $this->elearningNodeProgress->add($elearningNodeProgress);
            $elearningNodeProgress->setEnrollment($this);
        }

        return $this;
    }

    public function removeElearningNodeProgress(ElearningNodeProgress $elearningNodeProgress): static
    {
        if ($this->elearningNodeProgress->removeElement($elearningNodeProgress)) {
            // set the owning side to null (unless already changed)
            if ($elearningNodeProgress->getEnrollment() === $this) {
                $elearningNodeProgress->setEnrollment(null);
            }
        }

        return $this;
    }
}
