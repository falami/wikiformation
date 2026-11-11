<?php

declare(strict_types=1);
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_caces_exam_learner', columns: ['exam_id', 'learner_id'])]
class CacesAttempt
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    public function getId(): ?int { return $this->id; }
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Entite $entite = null;
    public function getEntite(): ?Entite { return $this->entite; }
    public function setEntite(?Entite $value): static { $this->entite = $value; return $this; }
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?CacesExam $exam = null;
    public function getExam(): ?CacesExam { return $this->exam; }
    public function setExam(?CacesExam $value): static { $this->exam = $value; return $this; }
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Inscription $inscription = null;
    public function getInscription(): ?Inscription { return $this->inscription; }
    public function setInscription(?Inscription $value): static { $this->inscription = $value; return $this; }
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $learner = null;
    public function getLearner(): ?Utilisateur { return $this->learner; }
    public function setLearner(?Utilisateur $value): static { $this->learner = $value; return $this; }
    #[ORM\Column(type: 'json')]
    private array $answers = [];
    public function getAnswers(): array { return $this->answers; }
    public function setAnswers(array $value): static { $this->answers = $value; return $this; }
    #[ORM\Column(type: 'json')]
    private array $theoryResult = [];
    public function getTheoryResult(): array { return $this->theoryResult; }
    public function setTheoryResult(array $value): static { $this->theoryResult = $value; return $this; }
    #[ORM\Column(type: 'json')]
    private array $practiceResult = [];
    public function getPracticeResult(): array { return $this->practiceResult; }
    public function setPracticeResult(array $value): static { $this->practiceResult = $value; return $this; }
    #[ORM\Column(type: 'json')]
    private array $profile = [];
    public function getProfile(): array { return $this->profile; }
    public function setProfile(array $value): static { $this->profile = $value; return $this; }
    #[ORM\Column(type: 'json')]
    private array $signatures = [];
    public function getSignatures(): array { return $this->signatures; }
    public function setSignatures(array $value): static { $this->signatures = $value; return $this; }
    #[ORM\Column(type: 'json')]
    private array $certificate = [];
    public function getCertificate(): array { return $this->certificate; }
    public function setCertificate(array $value): static { $this->certificate = $value; return $this; }
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;
    public function getStartedAt(): ?\DateTimeImmutable { return $this->startedAt; }
    public function setStartedAt(?\DateTimeImmutable $value): static { $this->startedAt = $value; return $this; }
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;
    public function getSubmittedAt(): ?\DateTimeImmutable { return $this->submittedAt; }
    public function setSubmittedAt(?\DateTimeImmutable $value): static { $this->submittedAt = $value; return $this; }
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $practiceAt = null;
    public function getPracticeAt(): ?\DateTimeImmutable { return $this->practiceAt; }
    public function setPracticeAt(?\DateTimeImmutable $value): static { $this->practiceAt = $value; return $this; }
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $value): static { $this->createdAt = $value; return $this; }
    public function __construct() { $this->createdAt = new \DateTimeImmutable(); }
}
