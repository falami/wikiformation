<?php
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity]
class PersonalSignature
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\OneToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Utilisateur $owner;
    #[ORM\Column(type: 'text', length: 16777215)]
    private string $image;
    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;
    public function __construct(Utilisateur $owner) { $this->owner = $owner; }
    public function update(string $image): void { $this->image = $image; $this->updatedAt = new \DateTimeImmutable(); }
    public function getImage(): string { return $this->image; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
