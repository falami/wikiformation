<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Schema mapping for the per-user states written atomically by NotificationController. */
#[ORM\Entity]
#[ORM\Table(name: 'notification_receipt')]
class NotificationReceipt
{
    #[ORM\Id, ORM\ManyToOne(targetEntity: Entite::class)]
    #[ORM\JoinColumn(name: 'entite_id', nullable: false, onDelete: 'CASCADE')]
    public ?Entite $entite = null;

    #[ORM\Id, ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(name: 'utilisateur_id', nullable: false, onDelete: 'CASCADE')]
    public ?Utilisateur $utilisateur = null;

    #[ORM\Id, ORM\Column(name: 'item_key', length: 190)]
    public string $itemKey = '';

    #[ORM\Column(name: 'read_at', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $readAt = null;

    #[ORM\Column(options: ['default' => false])]
    public bool $archived = false;
}
