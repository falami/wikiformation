<?php

namespace App\Entity\Automation;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'workflow_task')]
#[ORM\UniqueConstraint(name: 'uniq_workflow_task_key', columns: ['workflow_id', 'task_key'])]
class WorkflowTask
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private ?TrainingWorkflow $workflow = null;
    #[ORM\Column(length: 100)] private string $taskKey = '';
    #[ORM\Column(length: 40)] private string $action = '';
    #[ORM\Column(nullable: true)] private ?int $targetId = null;
    #[ORM\Column] private \DateTimeImmutable $dueAt;
    #[ORM\Column(length: 20)] private string $status = 'pending';
    #[ORM\Column(type: 'text', nullable: true)] private ?string $detail = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $processedAt = null;
    #[ORM\Column(type: 'json')] private array $snapshot = [];
    public function getId(): ?int { return $this->id; }
    public function getWorkflow(): ?TrainingWorkflow { return $this->workflow; }
    public function setWorkflow(TrainingWorkflow $v): static { $this->workflow = $v; return $this; }
    public function getTaskKey(): string { return $this->taskKey; }
    public function setTaskKey(string $v): static { $this->taskKey = $v; return $this; }
    public function getAction(): string { return $this->action; }
    public function setAction(string $v): static { $this->action = $v; return $this; }
    public function getTargetId(): ?int { return $this->targetId; }
    public function setTargetId(?int $v): static { $this->targetId = $v; return $this; }
    // Doctrine DATETIME columns carry no zone. Interpret persisted task timestamps as UTC,
    // regardless of the PHP timezone used by a web process or a scheduled worker.
    public function getDueAt(): \DateTimeImmutable { return self::storedUtc($this->dueAt); }
    public function setDueAt(\DateTimeImmutable $v): static { $this->dueAt = $v->setTimezone(new \DateTimeZone('UTC')); return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): static { $this->status = $v; return $this; }
    public function getDetail(): ?string { return $this->detail; }
    public function setDetail(?string $v): static { $this->detail = $v; return $this; }
    public function getProcessedAt(): ?\DateTimeImmutable { return $this->processedAt ? self::storedUtc($this->processedAt) : null; }
    public function setProcessedAt(?\DateTimeImmutable $v): static { $this->processedAt = $v?->setTimezone(new \DateTimeZone('UTC')); return $this; }
    public function getSnapshot(): array { return $this->snapshot; }
    public function setSnapshot(array $v): static { $this->snapshot = $v; return $this; }
    private static function storedUtc(\DateTimeImmutable $v): \DateTimeImmutable
    { return new \DateTimeImmutable($v->format('Y-m-d H:i:s.u'), new \DateTimeZone('UTC')); }
}
