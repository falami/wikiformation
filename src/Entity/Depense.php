<?php
// src/Entity/Depense.php

namespace App\Entity;

use App\Repository\DepenseRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: DepenseRepository::class)]
#[ORM\Index(name: 'idx_depense_date', columns: ['date_depense'])]
#[ORM\Index(name: 'idx_depense_entite', columns: ['entite_id'])]
class Depense
{
  #[ORM\Id]
  #[ORM\GeneratedValue]
  #[ORM\Column]
  private ?int $id = null;

  #[ORM\ManyToOne]
  #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
  private ?Entite $entite = null;

  // ✅ qui enregistre dans l'app
  #[ORM\ManyToOne(inversedBy: 'depenseCreateurs')]
  #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
  private ?Utilisateur $createur = null;

  // ✅ qui a réellement payé / fait la dépense (peut être différent)
  #[ORM\ManyToOne(inversedBy: 'depensesPayees')]
  #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
  private ?Utilisateur $payeur = null;

  #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
  private \DateTimeImmutable $dateDepense;

  #[ORM\Column(type: Types::TEXT, nullable: true)]
  #[Assert\NotBlank]
  #[Assert\Length(max: 2000)]
  private ?string $libelle = null;



  // ✅ TVA récupérable ?
  #[ORM\Column(options: ['default' => true])]
  private bool $tvaDeductible = true;

  // ✅ taux de TVA (20 / 10 / 5.5 / 0 ...)
  #[ORM\Column(type: 'float', options: ['default' => 20])]
  private float $tauxTva = 20.0;

  // ✅ montants
  #[ORM\Column(options: ['default' => 0])]
  private int $montantHtCents = 0;

  #[ORM\Column(options: ['default' => 0])]
  private int $montantTvaCents = 0;

  #[ORM\Column(options: ['default' => 0])]
  private int $montantTtcCents = 0;

  #[ORM\Column(length: 3, options: ['default' => 'EUR'])]
  private string $devise = 'EUR';

  // ✅ justificatif (pdf/jpg/png…)
  #[ORM\Column(length: 255, nullable: true)]
  private ?string $justificatifPath = null;

  #[ORM\Column(type: Types::JSON, nullable: true)]
  private ?array $meta = null;

  #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
  private \DateTimeImmutable $dateCreation;

  #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
  private ?\DateTimeImmutable $dateMaj = null;

  #[ORM\ManyToOne(inversedBy: 'depenses')]
  #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
  private ?DepenseCategorie $categorie = null;

  #[ORM\ManyToOne(inversedBy: 'depenses')]
  #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
  private ?DepenseFournisseur $fournisseur = null;

  #[ORM\Column(type: 'float', options: ['default' => 80])]
  private float $tvaDeductiblePct = 80.0;




  public function __construct()
  {
    $this->dateCreation = new \DateTimeImmutable();
    $this->dateDepense  = new \DateTimeImmutable();
  }

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

  public function getCreateur(): ?Utilisateur
  {
    return $this->createur;
  }
  public function setCreateur(?Utilisateur $createur): static
  {
    $this->createur = $createur;
    return $this;
  }

  public function getPayeur(): ?Utilisateur
  {
    return $this->payeur;
  }
  public function setPayeur(?Utilisateur $payeur): static
  {
    $this->payeur = $payeur;
    return $this;
  }

  public function getDateDepense(): \DateTimeImmutable
  {
    return $this->dateDepense;
  }
  public function setDateDepense(\DateTimeImmutable $d): static
  {
    $this->dateDepense = $d;
    return $this;
  }

  public function getLibelle(): ?string
  {
    return $this->libelle;
  }

  public function setLibelle(?string $libelle): self
  {
    $this->libelle = $libelle;
    return $this;
  }

  public function isTvaDeductible(): bool
  {
    return $this->tvaDeductible;
  }
  public function setTvaDeductible(bool $v): static
  {
    $this->tvaDeductible = $v;
    return $this;
  }

  public function getTauxTva(): float
  {
    return $this->tauxTva;
  }
  public function setTauxTva(float $t): static
  {
    $this->tauxTva = max(0, $t);
    return $this;
  }

  public function getMontantHtCents(): int
  {
    return $this->montantHtCents;
  }
  public function setMontantHtCents(int $c): static
  {
    $this->montantHtCents = max(0, $c);
    return $this;
  }

  public function getMontantTvaCents(): int
  {
    return $this->montantTvaCents;
  }
  public function setMontantTvaCents(int $c): static
  {
    $this->montantTvaCents = max(0, $c);
    return $this;
  }

  public function getMontantTtcCents(): int
  {
    return $this->montantTtcCents;
  }
  public function setMontantTtcCents(int $c): static
  {
    $this->montantTtcCents = max(0, $c);
    return $this;
  }

  public function getDevise(): string
  {
    return $this->devise;
  }
  public function setDevise(string $d): static
  {
    $this->devise = $d;
    return $this;
  }

  public function getJustificatifPath(): ?string
  {
    return $this->justificatifPath;
  }
  public function setJustificatifPath(?string $p): static
  {
    $this->justificatifPath = $p;
    return $this;
  }

  public function getMeta(): ?array
  {
    return $this->meta;
  }
  public function setMeta(?array $m): static
  {
    $this->meta = $m;
    return $this;
  }

  public function getDateCreation(): \DateTimeImmutable
  {
    return $this->dateCreation;
  }
  public function getDateMaj(): ?\DateTimeImmutable
  {
    return $this->dateMaj;
  }
  public function touch(): void
  {
    $this->dateMaj = new \DateTimeImmutable();
  }

  public function getCategorie(): ?DepenseCategorie
  {
    return $this->categorie;
  }

  public function setCategorie(?DepenseCategorie $categorie): static
  {
    $this->categorie = $categorie;

    return $this;
  }

  public function getFournisseur(): ?DepenseFournisseur
  {
    return $this->fournisseur;
  }

  public function setFournisseur(?DepenseFournisseur $fournisseur): static
  {
    $this->fournisseur = $fournisseur;

    return $this;
  }

  public function getTvaDeductiblePct(): float
  {
    return $this->tvaDeductiblePct;
  }



  public function setTvaDeductiblePct(?float $pct): static
  {
    // si vide => on remet une valeur par défaut
    if ($pct === null) {
      $pct = 80.0; // ou 100.0 selon ton choix
    }

    $pct = max(0.0, min(100.0, (float) $pct));
    $this->tvaDeductiblePct = $pct;

    return $this;
  }


  /**
   * ✅ TVA réellement déductible (en cents), selon checkbox + pourcentage
   */
  public function getTvaDeductibleCents(): int
  {
    if (!$this->tvaDeductible) return 0;
    $pct = max(0.0, min(100.0, (float)$this->tvaDeductiblePct));
    return (int) round($this->montantTvaCents * ($pct / 100));
  }
}
