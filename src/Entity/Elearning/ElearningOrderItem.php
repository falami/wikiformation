<?php

namespace App\Entity\Elearning;

use App\Repository\Elearning\ElearningOrderItemRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ElearningOrderItemRepository::class)]
class ElearningOrderItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;


    #[ORM\Column(options: ['unsigned' => true])]
    private int $unitPriceCents = 0;

    #[ORM\Column(options: ['unsigned' => true])]
    private int $qty = 1;

    #[ORM\Column(options: ['unsigned' => true])]
    private int $lineTotalCents = 0;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ElearningOrder $newOrder = null;

    #[ORM\ManyToOne(inversedBy: 'elearningOrderItems')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?ElearningCourse $course = null;



    public function __construct()
    {
        $this->dateCreation = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUnitPriceCents(): int
    {
        return $this->unitPriceCents;
    }

    public function setUnitPriceCents(int $unitPriceCents): static
    {
        $this->unitPriceCents = $unitPriceCents;

        return $this;
    }

    public function getQty(): int
    {
        return $this->qty;
    }

    public function setQty(int $qty): static
    {
        $this->qty = $qty;

        return $this;
    }

    public function getLineTotalCents(): int
    {
        return $this->lineTotalCents;
    }

    public function setLineTotalCents(int $lineTotalCents): static
    {
        $this->lineTotalCents = $lineTotalCents;

        return $this;
    }
    public function getDateCreation(): ?\DateTimeImmutable
    {
        return $this->dateCreation;
    }

    public function setDateCreation(\DateTimeImmutable $dateCreation): static
    {
        $this->dateCreation = $dateCreation;

        return $this;
    }

    public function getNewOrder(): ?ElearningOrder
    {
        return $this->newOrder;
    }

    public function setNewOrder(?ElearningOrder $newOrder): static
    {
        $this->newOrder = $newOrder;

        return $this;
    }

    public function getCourse(): ?ElearningCourse
    {
        return $this->course;
    }

    public function setCourse(?ElearningCourse $course): static
    {
        $this->course = $course;

        return $this;
    }
}
