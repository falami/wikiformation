<?php

namespace App\Entity;

use App\Repository\DevisSequenceRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DevisSequenceRepository::class)]
#[ORM\Table(name: 'devis_sequence')]
#[ORM\UniqueConstraint(name: 'uniq_devis_seq_entite_year', columns: ['entite_id', 'year'])]

class DevisSequence
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'devisSequences')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Entite $entite = null;

    #[ORM\Column]
    private ?int $year = null;

    #[ORM\Column]
    private ?int $last = null;


    public function __construct() {}

    public function getId(): ?int
    {
        return $this->id;
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

    public function getYear(): ?int
    {
        return $this->year;
    }

    public function setYear(int $year): static
    {
        $this->year = $year;

        return $this;
    }

    public function getLast(): ?int
    {
        return $this->last;
    }

    public function setLast(int $last): static
    {
        $this->last = $last;

        return $this;
    }
}
