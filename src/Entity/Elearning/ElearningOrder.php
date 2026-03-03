<?php

namespace App\Entity\Elearning;

use App\Entity\Entite;
use App\Entity\Utilisateur;
use App\Repository\Elearning\ElearningOrderRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use App\Enum\OrderStatus;

#[ORM\Entity(repositoryClass: ElearningOrderRepository::class)]
#[ORM\Table(name: 'elearning_order')]
class ElearningOrder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40, unique: true)]
    private string $reference = '';

    #[ORM\Column(enumType: OrderStatus::class)]
    private OrderStatus $status = OrderStatus::DRAFT;

    #[ORM\ManyToOne(inversedBy: 'elearningOrders')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $buyer = null;

    #[ORM\Column(options: ['unsigned' => true])]
    private int $totalCents = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;


    #[ORM\ManyToOne(inversedBy: 'elearningOrders')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Entite $entite = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    /** @var Collection<int, ElearningOrderItem> */
    #[ORM\OneToMany(mappedBy: 'newOrder', targetEntity: ElearningOrderItem::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $items;

    /**
     * @var Collection<int, ElearningEnrollment>
     */
    #[ORM\OneToMany(targetEntity: ElearningEnrollment::class, mappedBy: 'newOrder')]
    private Collection $elearningEnrollments;

    public function __construct()
    {
        $this->dateCreation = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
        $this->items = new ArrayCollection();
        $this->elearningEnrollments = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function setReference(string $reference): static
    {
        $this->reference = $reference;

        return $this;
    }

    public function getBuyer(): ?Utilisateur
    {
        return $this->buyer;
    }

    public function setBuyer(?Utilisateur $buyer): static
    {
        $this->buyer = $buyer;

        return $this;
    }

    public function getTotalCents(): int
    {
        return $this->totalCents;
    }

    public function setTotalCents(int $totalCents): static
    {
        $this->totalCents = $totalCents;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function setPaidAt(?\DateTimeImmutable $paidAt): static
    {
        $this->paidAt = $paidAt;

        return $this;
    }

    public function getEntite(): ?Entite
    {
        return $this->entite;
    }

    public function setEntite(?Entite $entite): static
    {
        $this->entite = $entite;

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

    /**
     * @return Collection<int, ElearningOrderItem>
     */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(ElearningOrderItem $item): static
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setNewOrder($this);
        }

        return $this;
    }

    public function removeItem(ElearningOrderItem $item): static
    {
        if ($this->items->removeElement($item)) {
            // set the owning side to null (unless already changed)
            if ($item->getNewOrder() === $this) {
                $item->setNewOrder(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ElearningEnrollment>
     */
    public function getElearningEnrollments(): Collection
    {
        return $this->elearningEnrollments;
    }

    public function addElearningEnrollment(ElearningEnrollment $elearningEnrollment): static
    {
        if (!$this->elearningEnrollments->contains($elearningEnrollment)) {
            $this->elearningEnrollments->add($elearningEnrollment);
            $elearningEnrollment->setNewOrder($this);
        }

        return $this;
    }

    public function removeElearningEnrollment(ElearningEnrollment $elearningEnrollment): static
    {
        if ($this->elearningEnrollments->removeElement($elearningEnrollment)) {
            // set the owning side to null (unless already changed)
            if ($elearningEnrollment->getNewOrder() === $this) {
                $elearningEnrollment->setNewOrder(null);
            }
        }

        return $this;
    }


    public function getStatus(): OrderStatus
    {
        return $this->status;
    }
    public function setStatus(OrderStatus $s): self
    {
        $this->status = $s;
        return $this;
    }
}
