<?php
declare(strict_types=1);
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;

/** Immutable metadata only: never store document contents or signing tokens. */
#[ORM\Entity]
#[ORM\Index(columns: ['entite_id', 'occurred_at'], name: 'notification_tenant_date')]
class NotificationEvent
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] public ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] public ?Entite $entite = null;
    #[ORM\ManyToOne, ORM\JoinColumn(onDelete: 'CASCADE')] public ?Session $session = null;
    #[ORM\ManyToOne, ORM\JoinColumn(onDelete: 'CASCADE')] public ?Utilisateur $subject = null;
    #[ORM\ManyToOne, ORM\JoinColumn(onDelete: 'CASCADE')] public ?Entreprise $company = null;
    #[ORM\Column(length: 32)] public string $category = 'documents';
    #[ORM\Column(length: 255)] public string $title = '';
    #[ORM\Column(length: 180)] public string $actor = '';
    #[ORM\Column] public \DateTimeImmutable $occurredAt;
    #[ORM\Column(length: 60)] public string $sourceType = '';
    #[ORM\Column(nullable: true)] public ?int $sourceId = null;
    public function __construct() { $this->occurredAt = new \DateTimeImmutable(); }
}
