<?php

declare (strict_types=1);
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
/** An explicit, revocable assignment; membership and target must share a tenant. */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_dossier_delegation', columns: ['membership_id', 'module', 'record_id'])]
class DossierDelegation
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?UtilisateurEntite $membership = null;
    #[ORM\Column(length: 30)]
    private string $module = '';
    #[ORM\Column]
    private int $recordId = 0;
    #[ORM\Column(length: 12)]
    private string $accessLevel = 'read';
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $assignedBy = null;
    #[ORM\Column]
    private \DateTimeImmutable $assignedAt;
    public function __construct()
    {
        $this->assignedAt = new \DateTimeImmutable();
    }
    public function getId(): ?int
    {
        return $this->id;
    }
    public function getMembership(): ?UtilisateurEntite
    {
        return $this->membership;
    }
    public function setMembership(UtilisateurEntite $value): self
    {
        $this->membership = $value;
        return $this;
    }
    public function getModule(): string
    {
        return $this->module;
    }
    public function setModule(string $value): self
    {
        $this->module = $value;
        return $this;
    }
    public function getRecordId(): int
    {
        return $this->recordId;
    }
    public function setRecordId(int $value): self
    {
        $this->recordId = $value;
        return $this;
    }
    public function getAccessLevel(): string
    {
        return $this->accessLevel;
    }
    public function setAccessLevel(string $value): self
    {
        if (!in_array($value, ['read', 'edit'], true)) {
            throw new \InvalidArgumentException();
        }
        $this->accessLevel = $value;
        return $this;
    }
    public function getAssignedBy(): ?Utilisateur
    {
        return $this->assignedBy;
    }
    public function setAssignedBy(Utilisateur $value): self
    {
        $this->assignedBy = $value;
        return $this;
    }
    public function getAssignedAt(): \DateTimeImmutable
    {
        return $this->assignedAt;
    }
}
