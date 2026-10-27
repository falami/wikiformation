<?php

declare(strict_types=1);

namespace App\Entity\Privacy;

use App\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'privacy_rights_request')]
#[ORM\Index(name: 'idx_privacy_request_due', columns: ['status', 'due_at'])]
class DataRightsRequest
{
    public const TYPES = [
        'access' => 'Accès à mes données',
        'rectification' => 'Rectification',
        'erasure' => 'Effacement',
        'restriction' => 'Limitation du traitement',
        'objection' => 'Opposition',
        'portability' => 'Portabilité',
    ];
    public const STATUSES = ['pending' => 'Reçue', 'processing' => 'En cours', 'answered' => 'Réponse disponible'];

    #[ORM\Id, ORM\Column(length: 40)]
    private string $reference;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $owner;
    #[ORM\Column(length: 20)]
    private string $type;
    #[ORM\Column(type: 'text')]
    private string $details;
    #[ORM\Column(length: 20)]
    private string $status = 'pending';
    #[ORM\Column]
    private \DateTimeImmutable $requestedAt;
    #[ORM\Column]
    private \DateTimeImmutable $dueAt;
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $response = null;
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $answeredAt = null;
    #[ORM\Column(type: 'json')]
    private array $history = [];

    public function __construct(Utilisateur $owner, string $type, string $details, ?\DateTimeImmutable $now = null)
    {
        if (!isset(self::TYPES[$type])) throw new \InvalidArgumentException('Choisissez un droit proposé.');
        $details = trim($details);
        if (mb_strlen($details) > 2000) throw new \InvalidArgumentException('Limitez votre demande à 2 000 caractères.');
        $this->reference = 'RGPD-'.strtoupper(bin2hex(random_bytes(12)));
        $this->owner = $owner;
        $this->type = $type;
        $this->details = $details;
        $this->requestedAt = $now ?? new \DateTimeImmutable();
        // Calendar month, clamped to month-end (Jan 31 -> Feb 28/29), never +30 days.
        $next = $this->requestedAt->modify('first day of next month');
        $this->dueAt = $next->setDate((int) $next->format('Y'), (int) $next->format('m'), min((int) $this->requestedAt->format('d'), (int) $next->format('t')));
    }

    public function updateStatus(string $status, string $operator, ?string $response = null): void
    {
        if (!in_array($status, ['processing', 'answered'], true)) throw new \InvalidArgumentException('Statut autorisé : processing ou answered.');
        if ($this->status === 'answered') throw new \DomainException('Une réponse enregistrée ne peut pas être écrasée.');
        $operator = trim($operator);
        if ($operator === '' || mb_strlen($operator) > 100) throw new \InvalidArgumentException('Identifiant opérateur requis (100 caractères maximum).');
        if ($status === 'answered' && (trim($response ?? '') === '' || mb_strlen($response ?? '') > 10000)) throw new \InvalidArgumentException('Une réponse de 1 à 10 000 caractères est requise.');
        $now = new \DateTimeImmutable();
        $this->history[] = ['at' => $now->format(DATE_ATOM), 'operator' => $operator, 'from' => $this->status, 'to' => $status];
        $this->status = $status;
        if ($status === 'answered') { $this->response = trim($response); $this->answeredAt = $now; }
    }

    public function getReference(): string { return $this->reference; }
    public function getOwner(): ?Utilisateur { return $this->owner; }
    public function getType(): string { return $this->type; }
    public function getTypeLabel(): string { return self::TYPES[$this->type]; }
    public function getDetails(): string { return $this->details; }
    public function getStatus(): string { return $this->status; }
    public function getStatusLabel(): string { return self::STATUSES[$this->status]; }
    public function getRequestedAt(): \DateTimeImmutable { return $this->requestedAt; }
    public function getDueAt(): \DateTimeImmutable { return $this->dueAt; }
    public function getResponse(): ?string { return $this->response; }
    public function getAnsweredAt(): ?\DateTimeImmutable { return $this->answeredAt; }
    public function getHistory(): array { return $this->history; }
}
