<?php

namespace App\Entity;

use App\Repository\PaiementRepository;
use Doctrine\ORM\Mapping as ORM;
use App\Enum\ModePaiement;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: PaiementRepository::class)]
#[ORM\Table(name: 'paiement')]
#[ORM\Index(name: 'idx_pi', columns: ['stripe_payment_intent_id'])]
class Paiement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'paiements')]
    private ?Facture $facture = null;

    #[ORM\Column]
    #[Assert\PositiveOrZero(message: "Le montant doit être positif.")]
    private int $montantCents = 0;

    #[ORM\Column(length: 3)]
    private string $devise = 'EUR';

    #[ORM\Column(enumType: ModePaiement::class)]
    private ModePaiement $mode; // CB, VIREMENT, CHEQUE, OPCO…

    #[ORM\Column]
    private \DateTimeImmutable $datePaiement;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripePaymentIntentId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $justificatif = null;

    #[ORM\Column(nullable: true)]
    private ?array $meta = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'paiementCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\ManyToOne(inversedBy: 'paiementEntites')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Entite $entite = null;

    #[ORM\ManyToOne(inversedBy: 'paiements')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $payeurUtilisateur = null;

    #[ORM\ManyToOne(inversedBy: 'paiements')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Entreprise $payeurEntreprise = null;

    #[ORM\Column(nullable: true)]
    private ?int $ventilationHtHorsDeboursCents = null;

    #[ORM\Column(nullable: true)]
    private ?int $ventilationTvaHorsDeboursCents = null;

    #[ORM\Column(nullable: true)]
    private ?int $ventilationDeboursCents = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $ventilationSource = null;


    public function __construct()
    {
        $now = new \DateTimeImmutable();

        $this->dateCreation = $now;
        $this->datePaiement = $now;

        // Choisis ton défaut (à adapter si tu préfères null => mais ici c’est non-nullable)
        $this->mode = ModePaiement::VIREMENT;
    }

    public function getId(): ?int
    {
        return $this->id;
    }


    public function getFacture(): ?Facture
    {
        return $this->facture;
    }

    public function setFacture(?Facture $facture): static
    {
        $this->facture = $facture;

        return $this;
    }

    public function getMontantCents(): ?int
    {
        return $this->montantCents;
    }

    public function setMontantCents(int $montantCents): static
    {
        $this->montantCents = $montantCents;

        return $this;
    }

    public function getDevise(): ?string
    {
        return $this->devise;
    }

    public function setDevise(string $devise): static
    {
        $this->devise = $devise;

        return $this;
    }

    public function getDatePaiement(): \DateTimeImmutable
    {
        return $this->datePaiement;
    }

    public function setDatePaiement(\DateTimeImmutable $datePaiement): static
    {
        $this->datePaiement = $datePaiement;

        return $this;
    }

    public function getStripePaymentIntentId(): ?string
    {
        return $this->stripePaymentIntentId;
    }

    public function setStripePaymentIntentId(?string $stripePaymentIntentId): static
    {
        $this->stripePaymentIntentId = $stripePaymentIntentId;

        return $this;
    }

    public function getJustificatif(): ?string
    {
        return $this->justificatif;
    }

    public function setJustificatif(?string $justificatif): static
    {
        $this->justificatif = $justificatif;

        return $this;
    }



    public function getMeta(): ?array
    {
        return $this->meta;
    }

    public function setMeta(?array $meta): static
    {
        $this->meta = $meta;

        return $this;
    }

    public function getMode(): ModePaiement
    {
        return $this->mode;
    }

    public function setMode(ModePaiement $mode): static
    {
        $this->mode = $mode;

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

    public function getCreateur(): ?Utilisateur
    {
        return $this->createur;
    }

    public function setCreateur(?Utilisateur $createur): static
    {
        $this->createur = $createur;

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

    public function getPayeurUtilisateur(): ?Utilisateur
    {
        return $this->payeurUtilisateur;
    }

    public function setPayeurUtilisateur(?Utilisateur $payeurUtilisateur): static
    {
        $this->payeurUtilisateur = $payeurUtilisateur;

        return $this;
    }

    public function getPayeurEntreprise(): ?Entreprise
    {
        return $this->payeurEntreprise;
    }

    public function setPayeurEntreprise(?Entreprise $payeurEntreprise): static
    {
        $this->payeurEntreprise = $payeurEntreprise;

        return $this;
    }



    #[Assert\Expression(
        "!(this.getPayeurUtilisateur() and this.getPayeurEntreprise())",
        message: "Un paiement ne peut pas avoir à la fois un payeur utilisateur ET un payeur entreprise."
    )]
    public function isPayeurExclusive(): bool
    {
        return true;
    }

    // ✅ Bonus : helper pratique
    public function getPayeurLabel(): string
    {
        if ($this->payeurEntreprise) return (string) $this->payeurEntreprise;
        if ($this->payeurUtilisateur) {
            $u = $this->payeurUtilisateur;
            return trim(($u->getPrenom() ?? '') . ' ' . ($u->getNom() ?? '')) ?: ($u->getEmail() ?? '—');
        }
        return '—';
    }

    public function getVentilationHtHorsDeboursCents(): ?int
    {
        return $this->ventilationHtHorsDeboursCents;
    }

    public function setVentilationHtHorsDeboursCents(?int $ventilationHtHorsDeboursCents): static
    {
        $this->ventilationHtHorsDeboursCents = $ventilationHtHorsDeboursCents;

        return $this;
    }

    public function getVentilationTvaHorsDeboursCents(): ?int
    {
        return $this->ventilationTvaHorsDeboursCents;
    }

    public function setVentilationTvaHorsDeboursCents(?int $ventilationTvaHorsDeboursCents): static
    {
        $this->ventilationTvaHorsDeboursCents = $ventilationTvaHorsDeboursCents;

        return $this;
    }

    public function getVentilationDeboursCents(): ?int
    {
        return $this->ventilationDeboursCents;
    }

    public function setVentilationDeboursCents(?int $ventilationDeboursCents): static
    {
        $this->ventilationDeboursCents = $ventilationDeboursCents;

        return $this;
    }

    public function getVentilationSource(): ?string
    {
        return $this->ventilationSource;
    }

    public function setVentilationSource(?string $ventilationSource): static
    {
        $this->ventilationSource = $ventilationSource;

        return $this;
    }


    #[Assert\Callback]
    public function validateVentilation(ExecutionContextInterface $context): void
    {
        // ✅ On ne bloque que si ventilation "manuelle"
        // (sinon, on considère que le snapshot auto peut avoir des écarts d'arrondis / évolutions)
        if (($this->ventilationSource ?? '') !== 'manuel') {
            return;
        }

        // si aucune ventilation => ok
        if (
            $this->ventilationHtHorsDeboursCents === null
            && $this->ventilationTvaHorsDeboursCents === null
            && $this->ventilationDeboursCents === null
        ) {
            return;
        }

        $ht  = (int) ($this->ventilationHtHorsDeboursCents ?? 0);
        $tva = (int) ($this->ventilationTvaHorsDeboursCents ?? 0);
        $deb = (int) ($this->ventilationDeboursCents ?? 0);

        $sum  = $ht + $tva + $deb;
        $paid = (int) ($this->montantCents ?? 0);

        // tolérance 1 centime
        if (abs($sum - $paid) > 1) {
            $context->buildViolation('La ventilation doit correspondre au montant payé (HT + TVA + débours).')
                ->atPath('ventilationHtHorsDeboursCents')
                ->addViolation();
        }
    }
}
