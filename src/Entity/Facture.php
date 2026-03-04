<?php

namespace App\Entity;

use App\Repository\FactureRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use App\Enum\FactureStatus;
use App\Entity\Devis;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;


#[ORM\Entity(repositoryClass: FactureRepository::class)]
#[ORM\Table(name: 'facture')]
#[ORM\Index(name: 'idx_facture_numero', columns: ['numero'])]
class Facture

{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * @var Collection<int, Inscription>
     */
    #[ORM\ManyToMany(targetEntity: Inscription::class, inversedBy: 'factures')]
    private Collection $inscriptions;

    #[ORM\ManyToOne(inversedBy: 'factures')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $destinataire = null;

    #[ORM\Column(length: 40, unique: true)]
    private string $numero = ''; // ← évite l’access avant init

    #[ORM\Column]
    private \DateTimeImmutable $dateEmission;

    #[ORM\Column]
    private int $montantTtcCents;

    #[ORM\Column]
    private int $montantHtCents;

    #[ORM\Column]
    private int $montantTvaCents;

    #[ORM\Column(length: 3)]
    private string $devise = 'EUR';

    #[ORM\Column(nullable: true)]
    private ?array $meta = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeInvoiceId = null;


    #[ORM\Column(enumType: FactureStatus::class)]
    private FactureStatus $status = FactureStatus::DUE;

    /**
     * @var Collection<int, LigneFacture>
     */
    #[ORM\OneToMany(mappedBy: 'facture', targetEntity: LigneFacture::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $lignes;

    /**
     * @var Collection<int, Paiement>
     */
    #[ORM\OneToMany(targetEntity: Paiement::class, mappedBy: 'facture')]
    private Collection $paiements;

    /**
     * @var Collection<int, Avoir>
     */
    #[ORM\OneToMany(targetEntity: Avoir::class, mappedBy: 'factureOrigine')]
    private Collection $avoirs;

    #[ORM\ManyToOne(inversedBy: 'factures')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Entite $entite = null;

    #[ORM\ManyToOne(inversedBy: 'factures')]
    private ?Entreprise $entrepriseDestinataire = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $remiseGlobalePourcent = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $remiseGlobaleMontantCents = null;

    #[ORM\ManyToOne(inversedBy: 'factures')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Formation $formation = null;

    #[ORM\OneToOne(mappedBy: 'factureCreee', targetEntity: Devis::class)]
    private ?Devis $devisSource = null;

    /**
     * @var Collection<int, EmailLog>
     */
    #[ORM\OneToMany(targetEntity: EmailLog::class, mappedBy: 'facture')]
    private Collection $emailLogs;

    /**
     * @var Collection<int, ProspectInteraction>
     */
    #[ORM\OneToMany(targetEntity: ProspectInteraction::class, mappedBy: 'facture')]
    private Collection $prospectInteractions;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'factureCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;


    public function __construct()
    {
        $this->dateEmission = new \DateTimeImmutable();
        $this->lignes = new ArrayCollection();
        $this->paiements = new ArrayCollection();
        $this->avoirs = new ArrayCollection();
        $this->inscriptions = new ArrayCollection();
        $this->emailLogs = new ArrayCollection();
        $this->prospectInteractions = new ArrayCollection();
        $this->dateCreation = new \DateTimeImmutable();
    }


    public function getEntrepriseDestinataire(): ?Entreprise
    {
        return $this->entrepriseDestinataire;
    }

    public function setEntrepriseDestinataire(?Entreprise $entreprise): static
    {
        $this->entrepriseDestinataire = $entreprise;
        return $this;
    }

    public function getDestinataireLabel(): string
    {
        if ($this->entrepriseDestinataire) {
            return $this->entrepriseDestinataire->getRaisonSociale() ?: 'Entreprise';
        }

        if ($this->destinataire) {
            $u = $this->destinataire;
            return trim(($u->getPrenom() ?? '') . ' ' . ($u->getNom() ?? '')) ?: ($u->getEmail() ?? '—');
        }

        return '—';
    }

    #[Assert\Callback]
    public function validateDestinataire(ExecutionContextInterface $context): void
    {
        $hasUser = $this->destinataire !== null;
        $hasEnt  = $this->entrepriseDestinataire !== null;

        // 1) au moins un destinataire
        if (!$hasUser && !$hasEnt) {
            $context->buildViolation('Choisis un destinataire : entreprise et/ou personne.')
                ->atPath('entrepriseDestinataire')
                ->addViolation();
            return;
        }

        // 2) entreprise + personne = OK ✅
        // (donc aucune violation si les deux sont remplis)
    }


    #[Assert\Callback]
    public function validateEntrepriseEntite(ExecutionContextInterface $context): void
    {
        if ($this->entrepriseDestinataire && $this->entite && $this->entrepriseDestinataire->getEntite() !== $this->entite) {
            $context->buildViolation('Cette entreprise ne correspond pas à l’entité courante.')
                ->atPath('entrepriseDestinataire')
                ->addViolation();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDestinataire(): ?Utilisateur
    {
        return $this->destinataire;
    }

    public function setDestinataire(?Utilisateur $destinataire): static
    {
        $this->destinataire = $destinataire;

        return $this;
    }

    /**
     * @return Collection<int, Inscription>
     */
    public function getInscriptions(): Collection
    {
        return $this->inscriptions;
    }

    public function addInscription(Inscription $inscription): static
    {
        if (!$this->inscriptions->contains($inscription)) {
            $this->inscriptions->add($inscription);
        }

        return $this;
    }

    public function removeInscription(Inscription $inscription): static
    {
        $this->inscriptions->removeElement($inscription);

        return $this;
    }

    public function getNumero(): string
    {
        return $this->numero;
    }

    public function setNumero(string $numero): static
    {
        $this->numero = $numero;

        return $this;
    }

    public function getDateEmission(): \DateTimeImmutable
    {
        return $this->dateEmission;
    }

    public function setDateEmission(\DateTimeImmutable $dateEmission): static
    {
        $this->dateEmission = $dateEmission;

        return $this;
    }

    public function getMontantTtcCents(): int
    {
        return $this->montantTtcCents;
    }

    public function setMontantTtcCents(int $montantTtcCents): static
    {
        $this->montantTtcCents = $montantTtcCents;

        return $this;
    }

    public function getMontantHtCents(): int
    {
        return $this->montantHtCents;
    }

    public function setMontantHtCents(int $montantHtCents): static
    {
        $this->montantHtCents = $montantHtCents;

        return $this;
    }

    public function getMontantTvaCents(): int
    {
        return $this->montantTvaCents;
    }

    public function setMontantTvaCents(int $montantTvaCents): static
    {
        $this->montantTvaCents = $montantTvaCents;

        return $this;
    }

    public function getDevise(): string
    {
        return $this->devise;
    }

    public function setDevise(string $devise): static
    {
        $this->devise = $devise;

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


    public function getStatus(): FactureStatus
    {
        return $this->status;
    }

    public function setStatus(FactureStatus $status): static
    {
        $this->status = $status;

        return $this;
    }




    public function getStripeInvoiceId(): ?string
    {
        return $this->stripeInvoiceId;
    }

    public function setStripeInvoiceId(?string $stripeInvoiceId): static
    {
        $this->stripeInvoiceId = $stripeInvoiceId;

        return $this;
    }

    /**
     * @return Collection<int, LigneFacture>
     */
    public function getLignes(): Collection
    {
        return $this->lignes;
    }

    public function addLigne(LigneFacture $ligne): static
    {
        if (!$this->lignes->contains($ligne)) {
            $this->lignes->add($ligne);
            $ligne->setFacture($this);
        }

        return $this;
    }

    public function removeLigne(LigneFacture $ligne): static
    {
        if ($this->lignes->removeElement($ligne)) {
            // set the owning side to null (unless already changed)
            if ($ligne->getFacture() === $this) {
                $ligne->setFacture(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Paiement>
     */
    public function getPaiements(): Collection
    {
        return $this->paiements;
    }

    public function addPaiement(Paiement $paiement): static
    {
        if (!$this->paiements->contains($paiement)) {
            $this->paiements->add($paiement);
            $paiement->setFacture($this);
        }

        return $this;
    }

    public function removePaiement(Paiement $paiement): static
    {
        if ($this->paiements->removeElement($paiement)) {
            // set the owning side to null (unless already changed)
            if ($paiement->getFacture() === $this) {
                $paiement->setFacture(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Avoir>
     */
    public function getAvoirs(): Collection
    {
        return $this->avoirs;
    }

    public function addAvoir(Avoir $avoir): static
    {
        if (!$this->avoirs->contains($avoir)) {
            $this->avoirs->add($avoir);
            $avoir->setFactureOrigine($this);
        }

        return $this;
    }

    public function removeAvoir(Avoir $avoir): static
    {
        if ($this->avoirs->removeElement($avoir)) {
            // set the owning side to null (unless already changed)
            if ($avoir->getFactureOrigine() === $this) {
                $avoir->setFactureOrigine(null);
            }
        }

        return $this;
    }

    public function hasNumero(): bool
    {
        return !empty($this->numero ?? '');
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

    public function getRemiseGlobalePourcent(): ?float
    {
        return $this->remiseGlobalePourcent;
    }
    public function setRemiseGlobalePourcent(?float $v): static
    {
        $this->remiseGlobalePourcent = max(0, $v);
        return $this;
    }

    public function getRemiseGlobaleMontantCents(): ?int
    {
        return $this->remiseGlobaleMontantCents;
    }

    public function setRemiseGlobaleMontantCents(?int $cents): self
    {
        $this->remiseGlobaleMontantCents = $cents;
        return $this;
    }

    public function getSousTotalHtNetCents(): int
    {
        $sum = 0;
        foreach ($this->lignes as $l) $sum += $l->getTotalHtNetCents();
        return $sum;
    }

    public function getRemiseGlobaleCents(): int
    {
        $base = $this->getSousTotalHtNetCents();

        $cents = $this->remiseGlobaleMontantCents;
        if ($cents !== null && $cents > 0) {
            return min($cents, $base);
        }

        $pct = $this->remiseGlobalePourcent;
        if ($pct !== null && $pct > 0) {
            return (int) round($base * ($pct / 100));
        }

        return 0;
    }



    #[Assert\Callback]
    public function validateRemise(ExecutionContextInterface $context): void
    {
        $pct   = $this->remiseGlobalePourcent;
        $cents = $this->remiseGlobaleMontantCents;

        if (($pct !== null && $pct > 0) && ($cents !== null && $cents > 0)) {
            $context->buildViolation('Choisis soit une remise en %, soit une remise en € (pas les deux).')
                ->atPath('remiseGlobalePourcent')
                ->addViolation();
        }
    }


    public function getFormation(): ?Formation
    {
        return $this->formation;
    }

    public function setFormation(?Formation $formation): static
    {
        $this->formation = $formation;

        return $this;
    }

    public function getDevisSource(): ?Devis
    {
        return $this->devisSource;
    }

    /**
     * @return Collection<int, EmailLog>
     */
    public function getEmailLogs(): Collection
    {
        return $this->emailLogs;
    }

    public function addEmailLog(EmailLog $emailLog): static
    {
        if (!$this->emailLogs->contains($emailLog)) {
            $this->emailLogs->add($emailLog);
            $emailLog->setFacture($this);
        }

        return $this;
    }

    public function removeEmailLog(EmailLog $emailLog): static
    {
        if ($this->emailLogs->removeElement($emailLog)) {
            // set the owning side to null (unless already changed)
            if ($emailLog->getFacture() === $this) {
                $emailLog->setFacture(null);
            }
        }

        return $this;
    }

    public function setDevisSource(?Devis $devis): static
    {
        $this->devisSource = $devis;
        return $this;
    }

    /**
     * @return Collection<int, ProspectInteraction>
     */
    public function getProspectInteractions(): Collection
    {
        return $this->prospectInteractions;
    }

    public function addProspectInteraction(ProspectInteraction $prospectInteraction): static
    {
        if (!$this->prospectInteractions->contains($prospectInteraction)) {
            $this->prospectInteractions->add($prospectInteraction);
            $prospectInteraction->setFacture($this);
        }

        return $this;
    }

    public function removeProspectInteraction(ProspectInteraction $prospectInteraction): static
    {
        if ($this->prospectInteractions->removeElement($prospectInteraction)) {
            // set the owning side to null (unless already changed)
            if ($prospectInteraction->getFacture() === $this) {
                $prospectInteraction->setFacture(null);
            }
        }

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


    public function getMontantHtHorsDeboursCents(): int
    {
        $sum = 0;
        foreach ($this->lignes as $l) {
            if ($l->isDebours()) continue;
            $sum += $l->getTotalHtNetCents();
        }

        // si tu appliques une remise globale, elle doit s'appliquer sur le HT net (hors débours)
        $sum = max(0, $sum - $this->getRemiseGlobaleCents());

        return $sum;
    }

    public function getMontantTvaHorsDeboursCents(): int
    {
        $tva = 0;

        // On calcule la TVA sur les lignes (hors débours) AVANT remise globale,
        // puis on applique la remise globale proportionnellement via le ratio HT.
        $htAvantRemise = 0;
        foreach ($this->lignes as $l) {
            if ($l->isDebours()) continue;
            $htAvantRemise += $l->getTotalHtNetCents();
            $tva += $l->getTotalTvaCents();
        }

        $htApresRemise = $this->getMontantHtHorsDeboursCents();
        if ($htAvantRemise <= 0) return 0;

        $ratio = $htApresRemise / $htAvantRemise; // 0..1
        return (int) round($tva * $ratio);
    }

    public function getMontantTtcHorsDeboursCents(): int
    {
        return $this->getMontantHtHorsDeboursCents() + $this->getMontantTvaHorsDeboursCents();
    }

    public function getMontantDeboursTtcCents(): int
    {
        $sum = 0;
        foreach ($this->lignes as $l) {
            if (!$l->isDebours()) continue;

            // ✅ débours TTC = HT net uniquement (TVA 0)
            $sum += $l->getTotalHtNetCents();
        }
        return max(0, $sum);
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note ? trim($note) : null;
        return $this;
    }



    public function isPaid(): bool
    {
        return $this->status === FactureStatus::PAID;
    }

    public function isCanceled(): bool
    {
        return $this->status === FactureStatus::CANCELED;
    }

    public function isPartiallyPaid(): bool
    {
        return $this->status === FactureStatus::PARTIALLY_PAID;
    }

    public function isDue(): bool
    {
        return $this->status === FactureStatus::DUE;
    }

    /**
     * ✅ Autorise un paiement (manuel / Stripe)
     */
    public function canBePaid(): bool
    {
        if ($this->isCanceled()) return false;
        if ($this->isPaid()) return false;

        // Montant nul => pas de paiement
        return $this->getTtcTotalCents() > 0;
    }

    /**
     * ✅ TTC total "réel" à payer dans TON modèle :
     * TTC total = TTC hors débours (montantTtcCents) + débours TTC (calculé depuis les lignes)
     *
     * Important : tu as déjà getMontantDeboursTtcCents() et getMontantTtcCents().
     */
    public function getTtcTotalCents(): int
    {
        return max(0, (int)($this->getMontantTtcCents() ?? 0) + (int)($this->getMontantDeboursTtcCents() ?? 0));
    }

    /**
     * ✅ remaining "théorique" si tu as besoin côté Twig rapidement
     * (Attention: si tu veux le montant exact "paid", fais-le dans repo ou controller)
     */
    public function isPayableAmountPositive(): bool
    {
        return $this->getTtcTotalCents() > 0;
    }
}
