<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'contrat_formateur_sequence')]
#[ORM\UniqueConstraint(name: 'uniq_contrat_formateur_sequence', columns: ['entite_id', 'year'])]
class ContratFormateurSequence
{
  #[ORM\Id]
  #[ORM\GeneratedValue]
  #[ORM\Column]
  private ?int $id = null;

  #[ORM\ManyToOne]
  #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
  private ?Entite $entite = null;

  #[ORM\Column(type: 'integer')]
  private int $year;

  #[ORM\Column(type: 'integer')]
  private int $last = 0;

  public function __construct() {}

  public function getId(): ?int
  {
    return $this->id;
  }

  public function getEntite(): ?Entite
  {
    return $this->entite;
  }
  public function setEntite(Entite $e): self
  {
    $this->entite = $e;
    return $this;
  }

  public function getYear(): int
  {
    return $this->year;
  }
  public function setYear(int $y): self
  {
    $this->year = $y;
    return $this;
  }

  public function getLast(): int
  {
    return $this->last;
  }
  public function setLast(int $v): self
  {
    $this->last = $v;
    return $this;
  }
}
