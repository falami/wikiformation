<?php
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity]
class SessionStartReminderDelivery
{
    #[ORM\Id]
    #[ORM\Column(length: 120)]
    public string $id;
    #[ORM\Column(length: 20)]
    public string $status = 'pending';
    #[ORM\Column]
    public \DateTimeImmutable $createdAt;
    public function __construct(string $id) { $this->id = $id; $this->createdAt = new \DateTimeImmutable(); }
}
