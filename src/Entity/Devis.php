<?php

namespace App\Entity;

use App\Repository\DevisRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Enum\DevisStatus;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: DevisRepository::class)]
class Devis
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40, unique: true, nullable: true)]
    private ?string $numero = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $dateEmission;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateValidite = null;

    #[ORM\Column]
    private int $montantHtCents = 0;

    #[ORM\Column]
    private int $montantTvaCents = 0;

    #[ORM\Column]
    private int $montantTtcCents = 0;

    #[ORM\Column(length: 3)]
    private string $devise = 'EUR';

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $meta = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $pdfPath = null;

    #[ORM\ManyToOne(inversedBy: 'devis')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $destinataire = null;


    #[ORM\ManyToOne(inversedBy: 'devis')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Entite $entite = null;

    /**
     * @var Collection<int, LigneDevis>
     */
    #[ORM\OneToMany(
        mappedBy: 'devis',
        targetEntity: LigneDevis::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    private Collection $lignes;


    #[ORM\Column(enumType: DevisStatus::class)]
    private DevisStatus $status = DevisStatus::DRAFT;



    #[ORM\OneToOne(targetEntity: Facture::class, inversedBy: 'devisSource')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Facture $factureCreee = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Entreprise $entrepriseDestinataire = null;

    #[ORM\ManyToOne(inversedBy: 'devis')]
    private ?Prospect $prospect = null;

    #[ORM\ManyToOne(inversedBy: 'devis')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Formation $formation = null;

    /**
     * @var Collection<int, Inscription>
     */
    #[ORM\ManyToMany(targetEntity: Inscription::class, inversedBy: 'devis')]
    private Collection $inscriptions;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $remiseGlobalePourcent = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $remiseGlobaleMontantCents = null;

    /**
     * @var Collection<int, ProspectInteraction>
     */
    #[ORM\OneToMany(
        targetEntity: ProspectInteraction::class,
        mappedBy: 'devis',
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    private Collection $prospectInteractions;

    /**
     * @var Collection<int, EmailLog>
     */
    #[ORM\OneToMany(targetEntity: EmailLog::class, mappedBy: 'devis')]
    private Collection $emailLogs;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'devisCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    public function __construct()
    {
        $this->dateCreation = new \DateTimeImmutable();
        $this->dateEmission = new \DateTimeImmutable();
        $this->lignes = new ArrayCollection();
        $this->inscriptions = new ArrayCollection();
        $this->prospectInteractions = new ArrayCollection();
        $this->emailLogs = new ArrayCollection();
    }


    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumero(): ?string
    {
        return $this->numero;
    }

    public function setNumero(?string $numero): static
    {
        $this->numero = $numero;
        return $this;
    }

    public function getDateEmission(): ?\DateTimeImmutable
    {
        return $this->dateEmission;
    }

    public function setDateEmission(\DateTimeImmutable $dateEmission): static
    {
        $this->dateEmission = $dateEmission;

        return $this;
    }

    public function getDateValidite(): ?\DateTimeImmutable
    {
        return $this->dateValidite;
    }

    public function setDateValidite(?\DateTimeImmutable $dateValidite): static
    {
        $this->dateValidite = $dateValidite;

        return $this;
    }

    public function getMontantHtCents(): ?int
    {
        return $this->montantHtCents;
    }

    public function setMontantHtCents(int $montantHtCents): static
    {
        $this->montantHtCents = $montantHtCents;

        return $this;
    }

    public function getMontantTvaCents(): ?int
    {
        return $this->montantTvaCents;
    }

    public function setMontantTvaCents(int $montantTvaCents): static
    {
        $this->montantTvaCents = $montantTvaCents;

        return $this;
    }

    public function getMontantTtcCents(): ?int
    {
        return $this->montantTtcCents;
    }

    public function setMontantTtcCents(int $montantTtcCents): static
    {
        $this->montantTtcCents = $montantTtcCents;

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

    public function getMeta(): ?array
    {
        return $this->meta;
    }

    public function setMeta(?array $meta): static
    {
        $this->meta = $meta;

        return $this;
    }

    public function getPdfPath(): ?string
    {
        return $this->pdfPath;
    }

    public function setPdfPath(?string $pdfPath): static
    {
        $this->pdfPath = $pdfPath;

        return $this;
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

    public function getEntite(): ?Entite
    {
        return $this->entite;
    }

    public function setEntite(?Entite $entite): static
    {
        $this->entite = $entite;

        return $this;
    }

    /**
     * @return Collection<int, LigneDevis>
     */
    public function getLignes(): Collection
    {
        return $this->lignes;
    }

    public function addLigne(LigneDevis $ligne): static
    {
        if (!$this->lignes->contains($ligne)) {
            $this->lignes->add($ligne);
            $ligne->setDevis($this);
        }
        return $this;
    }

    public function removeLigne(LigneDevis $ligne): static
    {
        if ($this->lignes->removeElement($ligne)) {
            if ($ligne->getDevis() === $this) {
                $ligne->setDevis(null);
            }
        }
        return $this;
    }


    public function getStatus(): DevisStatus
    {
        return $this->status;
    }
    public function setStatus(DevisStatus $status): static
    {
        $this->status = $status;
        return $this;
    }


    public function getFactureCreee(): ?Facture
    {
        return $this->factureCreee;
    }
    public function setFactureCreee(?Facture $f): static
    {
        $this->factureCreee = $f;
        return $this;
    }

    public function getEntrepriseDestinataire(): ?Entreprise
    {
        return $this->entrepriseDestinataire;
    }

    public function setEntrepriseDestinataire(?Entreprise $e): static
    {
        $this->entrepriseDestinataire = $e;
        return $this;
    }

    public function getProspect(): ?Prospect
    {
        return $this->prospect;
    }

    public function setProspect(?Prospect $prospect): static
    {
        $this->prospect = $prospect;

        return $this;
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


    #[Assert\Callback]
    public function validateDestinataire(ExecutionContextInterface $context): void
    {
        $hasUser     = $this->destinataire !== null;
        $hasEnt      = $this->entrepriseDestinataire !== null;
        $hasProspect = $this->prospect !== null;

        // 1) au moins un destinataire
        if (!$hasUser && !$hasEnt && !$hasProspect) {
            $context->buildViolation('Choisis un destinataire : prospect, entreprise et/ou personne.')
                ->atPath('entrepriseDestinataire')
                ->addViolation();
            return;
        }

        // 2) prospect exclusif
        if ($hasProspect && ($hasUser || $hasEnt)) {
            $context->buildViolation('Un prospect ne peut pas être combiné avec une entreprise ou une personne.')
                ->atPath('prospect')
                ->addViolation();
        }

        // 3) entreprise + personne = OK (et personne seule = OK)
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
            $prospectInteraction->setDevis($this);
        }

        return $this;
    }

    public function removeProspectInteraction(ProspectInteraction $prospectInteraction): static
    {
        if ($this->prospectInteractions->removeElement($prospectInteraction)) {
            // set the owning side to null (unless already changed)
            if ($prospectInteraction->getDevis() === $this) {
                $prospectInteraction->setDevis(null);
            }
        }

        return $this;
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
            $emailLog->setDevis($this);
        }

        return $this;
    }

    public function removeEmailLog(EmailLog $emailLog): static
    {
        if ($this->emailLogs->removeElement($emailLog)) {
            // set the owning side to null (unless already changed)
            if ($emailLog->getDevis() === $this) {
                $emailLog->setDevis(null);
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
}
