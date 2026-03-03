<?php

namespace App\Entity\Elearning;

use App\Repository\Elearning\ElearningNodeProgressRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ElearningNodeProgressRepository::class)]
#[ORM\Table(name: 'elearning_node_progress')]
#[ORM\UniqueConstraint(name: 'uniq_enroll_node', columns: ['enrollment_id', 'node_id'])]
class ElearningNodeProgress
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'elearningNodeProgress')]
    #[ORM\JoinColumn(name: 'enrollment_id', nullable: false, onDelete: 'CASCADE')]
    private ?ElearningEnrollment $enrollment = null;

    #[ORM\ManyToOne(inversedBy: 'elearningNodeProgress')]
    #[ORM\JoinColumn(name: 'node_id', nullable: false, onDelete: 'CASCADE')]
    private ?ElearningNode $node = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEnrollment(): ?ElearningEnrollment
    {
        return $this->enrollment;
    }

    public function setEnrollment(?ElearningEnrollment $enrollment): static
    {
        $this->enrollment = $enrollment;

        return $this;
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

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): static
    {
        $this->completedAt = $completedAt;

        return $this;
    }
    public function isCompleted(): bool
    {
        return $this->completedAt !== null;
    }

    public function markCompleted(): void
    {
        $this->completedAt = new \DateTimeImmutable();
    }

    public function markIncomplete(): void
    {
        $this->completedAt = null;
    }
}
