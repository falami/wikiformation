<?php

namespace App\Entity\Billing;

use App\Entity\Entite;
use App\Repository\Billing\EntiteUsageYearRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EntiteUsageYearRepository::class)]
#[ORM\Table(name: 'billing_entite_usage_year')]
#[ORM\UniqueConstraint(name: 'uniq_entite_year', columns: ['entite_id', 'year'])]
class EntiteUsageYear
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'entiteUsageYears')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Entite $entite;

    #[ORM\Column(options: ['unsigned' => true])]
    private int $year;

    #[ORM\Column(options: ['unsigned' => true])]
    private int $apprenantsCount = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;


    public function __construct(Entite $entite, int $year)
    {
        $this->entite = $entite;
        $this->year = $year;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function increment(int $by = 1): void
    {
        $this->apprenantsCount += $by;
        $this->updatedAt = new \DateTimeImmutable();
    }


    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEntite(): Entite
    {
        return $this->entite;
    }

    public function setEntite(Entite $entite): static
    {
        $this->entite = $entite;

        return $this;
    }

    public function getYear(): int
    {
        return $this->year;
    }

    public function setYear(int $year): static
    {
        $this->year = $year;

        return $this;
    }

    public function getApprenantsCount(): int
    {
        return $this->apprenantsCount;
    }

    public function setApprenantsCount(int $apprenantsCount): static
    {
        $this->apprenantsCount = $apprenantsCount;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
