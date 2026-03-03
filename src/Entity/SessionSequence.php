<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'session_sequence')]
#[ORM\UniqueConstraint(name: 'uniq_session_sequence', columns: ['entite_id', 'year'])]
class SessionSequence
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

  public function getId(): ?int
  {
    return $this->id;
  }

  public function getEntite(): ?Entite
  {
    return $this->entite;
  }
  public function setEntite(Entite $entite): self
  {
    $this->entite = $entite;
    return $this;
  }

  public function getYear(): int
  {
    return $this->year;
  }
  public function setYear(int $year): self
  {
    $this->year = $year;
    return $this;
  }

  public function getLast(): int
  {
    return $this->last;
  }
  public function setLast(int $last): self
  {
    $this->last = $last;
    return $this;
  }
}
