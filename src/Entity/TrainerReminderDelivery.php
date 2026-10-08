<?php

declare(strict_types=1);
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Durable delivery claims: a message with an uncertain outcome is never automatically resent. */
#[ORM\Entity]
#[ORM\Table(name: 'trainer_reminder_delivery')]
#[ORM\UniqueConstraint(name: 'UNIQ_TRAINER_DELIVERY', columns: ['entite_id', 'scope_key', 'sequence_number'])]
class TrainerReminderDelivery
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Entite $entite = null;
    #[ORM\Column(length: 160)]
    private string $scopeKey;
    #[ORM\Column]
    private int $sequenceNumber;
    #[ORM\Column(length: 30)]
    private string $kind;
    #[ORM\Column(length: 255)]
    private string $recipient;
    #[ORM\Column(length: 255)]
    private string $subject;
    #[ORM\Column(length: 20)]
    private string $status;
    #[ORM\Column]
    private \DateTimeImmutable $createdAt;
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;
    public function getId(): ?int { return $this->id; }
    public function getKind(): string { return $this->kind; }
    public function getRecipient(): string { return $this->recipient; }
    public function getSubject(): string { return $this->subject; }
    public function getStatus(): string { return $this->status; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getSentAt(): ?\DateTimeImmutable { return $this->sentAt; }
}
