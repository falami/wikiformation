<?php

declare(strict_types=1);
namespace App\Entity\Billing;

use App\Entity\{Entite, Utilisateur};
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'billing_learner_year_usage')]
#[ORM\UniqueConstraint(name: 'uniq_learner_entite_year', columns: ['entite_id', 'utilisateur_id', 'year'])]
class LearnerYearUsage
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Entite $entite;
    // Deleting a personal account does not refund already consumed annual capacity.
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')] private ?Utilisateur $utilisateur;
    #[ORM\Column] private int $year;
    public function __construct(Entite $entite, Utilisateur $user, int $year) { $this->entite=$entite; $this->utilisateur=$user; $this->year=$year; }
    public function getId(): ?int { return $this->id; }
    public function getEntite(): Entite { return $this->entite; }
    public function getUtilisateur(): ?Utilisateur { return $this->utilisateur; }
    public function anonymize(): void { $this->utilisateur = null; }
    public function getYear(): int { return $this->year; }
}
