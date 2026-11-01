<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'evaluation_dossier')]
#[ORM\Index(name: 'idx_evaluation_dossier_worklist', columns: ['entite_id', 'evaluator_id', 'published_at'])]
class EvaluationDossier
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Entite $entite = null;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?EvaluationTemplate $template = null;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Inscription $inscription = null;

    /** Registration identity can be edited elsewhere; an assessment must never change owner. */
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $learner = null;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Session $trainingSession = null;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $evaluator = null;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\ManyToOne(targetEntity: self::class), ORM\JoinColumn(onDelete: 'RESTRICT')]
    private ?self $previousDossier = null;

    #[ORM\Column(type: 'json')]
    private array $snapshot = [];

    #[ORM\Column(type: 'json')]
    private array $theoryAnswers = [];

    #[ORM\Column(type: 'json')]
    private array $theoryGrading = [];

    #[ORM\Column(type: 'json')]
    private array $practiceGrading = [];

    #[ORM\Column(type: 'json')]
    private array $publicationRecord = [];

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $theoryVerdict = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $practiceVerdict = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $theorySubmittedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $notificationSentAt = null;

    #[ORM\Version, ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $version = 1;

    public function __construct() { $this->createdAt = new \DateTimeImmutable(); }
    public function getId(): ?int { return $this->id; }
    public function getVersion(): int { return $this->version; }
    public function getEntite(): ?Entite { return $this->entite; }
    public function setEntite(Entite $value): static { $this->assertUnpublished(); $this->entite = $value; return $this; }
    public function getTemplate(): ?EvaluationTemplate { return $this->template; }
    public function setTemplate(EvaluationTemplate $value): static { $this->assertUnpublished(); $this->template = $value; return $this; }
    public function getInscription(): ?Inscription { return $this->inscription; }
    public function setInscription(Inscription $value): static
    {
        $this->assertUnpublished();
        if ($this->inscription !== null) {
            if ($this->inscription !== $value) throw new \DomainException('L’inscription d’un dossier affecté ne peut plus être remplacée.');
            return $this;
        }
        if ($value->getStagiaire() === null || $value->getSession() === null) throw new \InvalidArgumentException('L’inscription doit préciser le stagiaire et la session.');
        $this->inscription = $value;
        $this->learner = $value->getStagiaire();
        $this->trainingSession = $value->getSession();
        return $this;
    }
    public function getSession(): ?Session { return $this->trainingSession; }
    public function getTrainingSession(): ?Session { return $this->trainingSession; }
    public function getStagiaire(): ?Utilisateur { return $this->learner; }
    public function getLearner(): ?Utilisateur { return $this->learner; }
    public function getEvaluator(): ?Utilisateur { return $this->evaluator; }
    public function setEvaluator(Utilisateur $value): static { $this->assertUnpublished(); $this->evaluator = $value; return $this; }
    public function getCreateur(): ?Utilisateur { return $this->createur; }
    public function setCreateur(Utilisateur $value): static { $this->assertUnpublished(); $this->createur = $value; return $this; }
    public function getPreviousDossier(): ?self { return $this->previousDossier; }
    public function setPreviousDossier(?self $value): static
    {
        $this->assertUnpublished();
        if ($value === $this) throw new \InvalidArgumentException('Une nouvelle tentative doit désigner un dossier précédent distinct.');
        if ($value !== null && ($value->getPublishedAt() === null || $value->getInscription() !== $this->inscription || $value->getStagiaire() !== $this->learner || $value->getSession() !== $this->trainingSession)) throw new \DomainException('La tentative précédente doit être publiée et concerner le même stagiaire, la même session et la même inscription.');
        $this->previousDossier = $value;
        return $this;
    }
    public function getSnapshot(): array { return $this->snapshot; }
    public function setSnapshot(array $value): static
    {
        $this->assertUnpublished();
        if ($this->snapshot !== [] && $this->snapshot !== $value) throw new \DomainException('Le modèle de cette affectation est figé. Créez une nouvelle tentative.');
        $this->snapshot = $value;
        return $this;
    }
    public function getTheoryAnswers(): array { return $this->theoryAnswers; }
    public function setTheoryAnswers(array $value): static
    {
        $this->assertUnpublished();
        if ($this->theorySubmittedAt !== null) throw new \DomainException('Le test théorique a déjà été soumis.');
        $this->theoryAnswers = $value;
        return $this;
    }
    public function getTheoryGrading(): array { return $this->theoryGrading; }
    public function setTheoryGrading(array $value): static { $this->assertUnpublished(); $this->theoryGrading = $value; return $this; }
    public function getPracticeGrading(): array { return $this->practiceGrading; }
    public function setPracticeGrading(array $value): static { $this->assertUnpublished(); $this->practiceGrading = $value; return $this; }
    public function getTheoryVerdict(): ?string { return $this->theoryVerdict; }
    public function setTheoryVerdict(?string $value): static { $this->assertUnpublished(); $this->assertVerdict($value); $this->theoryVerdict = $value; return $this; }
    public function getPracticeVerdict(): ?string { return $this->practiceVerdict; }
    public function setPracticeVerdict(?string $value): static { $this->assertUnpublished(); $this->assertVerdict($value); $this->practiceVerdict = $value; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getTheorySubmittedAt(): ?\DateTimeImmutable { return $this->theorySubmittedAt; }
    public function setTheorySubmittedAt(\DateTimeImmutable $value): static
    {
        $this->assertUnpublished();
        if ($this->theorySubmittedAt !== null) throw new \DomainException('Le test théorique a déjà été soumis.');
        $this->theorySubmittedAt = $value;
        return $this;
    }
    public function getPublishedAt(): ?\DateTimeImmutable { return $this->publishedAt; }
    public function getPublicationRecord(): array { return $this->publicationRecord; }
    public function setPublicationRecord(array $value): static { $this->assertUnpublished(); $this->publicationRecord = $value; return $this; }
    public function setPublishedAt(\DateTimeImmutable $value): static { $this->assertUnpublished(); $this->publishedAt = $value; return $this; }
    public function getNotificationSentAt(): ?\DateTimeImmutable { return $this->notificationSentAt; }
    public function setNotificationSentAt(\DateTimeImmutable $value): static
    {
        if ($this->publishedAt === null) throw new \DomainException('Les résultats ne sont pas encore publiés.');
        $this->notificationSentAt ??= $value;
        return $this;
    }
    public function getState(): string { return $this->publishedAt ? 'published' : ($this->theorySubmittedAt ? 'to_review' : 'assigned'); }
    public function isSuccessful(): bool { return $this->publishedAt !== null && $this->theoryVerdict === 'favorable' && $this->practiceVerdict === 'favorable'; }
    public function assertUnpublished(): void
    {
        if ($this->publishedAt !== null) throw new \DomainException('Cette évaluation est publiée et ne peut plus être modifiée.');
    }
    private function assertVerdict(?string $value): void
    {
        if ($value !== null && !in_array($value, ['favorable', 'defavorable'], true)) throw new \InvalidArgumentException('Avis invalide.');
    }
}
