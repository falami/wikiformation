<?php
// src/Entity/Inscription.php

namespace App\Entity;

use App\Repository\InscriptionRepository;
use App\Enum\StatusInscription;
use App\Enum\PieceType;
use App\Enum\ModeFinancement;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InscriptionRepository::class)]
#[ORM\Table(name: 'inscription')]
#[ORM\UniqueConstraint(name: 'uniq_session_stagiaire', columns: ['session_id', 'stagiaire_id'])]
class Inscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'inscriptions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Session $session = null;

    #[ORM\ManyToOne(inversedBy: 'inscriptions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Utilisateur $stagiaire = null;

    #[ORM\Column(enumType: StatusInscription::class)]
    private StatusInscription $status = StatusInscription::PREINSCRIT;

    #[ORM\Column(type: 'integer', nullable: true, options: ['unsigned' => true])]
    private ?int $montantDuCents = null;

    #[ORM\Column(type: 'integer', nullable: true, options: ['unsigned' => true])]
    private ?int $montantRegleCents = null;

    #[ORM\Column(nullable: true)]
    private ?float $tauxAssiduite = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $reussi = false;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $meta = null;

    #[ORM\OneToOne(mappedBy: 'inscription', cascade: ['persist', 'remove'])]
    private ?DossierInscription $dossier = null;


    #[ORM\OneToOne(mappedBy: 'inscription', targetEntity: Attestation::class, cascade: ['persist', 'remove'])]
    private ?Attestation $attestation = null;

    /**
     * @var Collection<int, PieceDossier>
     */
    #[ORM\OneToMany(mappedBy: 'inscription', targetEntity: PieceDossier::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $pieces;


    #[ORM\Column(enumType: ModeFinancement::class)]
    private ModeFinancement $modeFinancement = ModeFinancement::INDIVIDUEL;

    #[ORM\ManyToOne(inversedBy: 'inscriptions')]
    private ?Entreprise $entreprise = null;

    /**
     * @var Collection<int, ConventionContrat>
     */
    #[ORM\ManyToMany(targetEntity: ConventionContrat::class, mappedBy: 'inscriptions')]
    private Collection $conventionContrats;

    /**
     * @var Collection<int, PositioningAttempt>
     */
    #[ORM\OneToMany(targetEntity: PositioningAttempt::class, mappedBy: 'inscription', orphanRemoval: true)]
    private Collection $positioningAttempts;

    /**
     * @var Collection<int, PositioningAssignment>
     */
    #[ORM\OneToMany(targetEntity: PositioningAssignment::class, mappedBy: 'inscription', orphanRemoval: true)]
    private Collection $positioningAssignments;

    /**
     * @var Collection<int, Devis>
     */
    #[ORM\ManyToMany(targetEntity: Devis::class, mappedBy: 'inscriptions')]
    private Collection $devis;



    /**
     * @var Collection<int, Facture>
     */
    #[ORM\ManyToMany(targetEntity: Facture::class, mappedBy: 'inscriptions')]
    private Collection $factures;

    /**
     * @var Collection<int, QcmAssignment>
     */
    #[ORM\OneToMany(
        targetEntity: QcmAssignment::class,
        mappedBy: 'inscription',
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    private Collection $qcmAssignments;

    #[ORM\OneToOne(mappedBy: 'inscription', targetEntity: ContratStagiaire::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private ?ContratStagiaire $contratStagiaire = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'inscriptionCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\ManyToOne(inversedBy: 'inscriptionEntites')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Entite $entite = null;

    /**
     * @var Collection<int, EntrepriseDocument>
     */
    #[ORM\OneToMany(targetEntity: EntrepriseDocument::class, mappedBy: 'inscription')]
    private Collection $entrepriseDocuments;



   #[ORM\OneToMany(targetEntity: SatisfactionAssignment::class, mappedBy: 'inscription', cascade: ['persist'])]
    private Collection $satisfactionAssignments;

    public function __construct()
    {
        $this->factures = new ArrayCollection();
        $this->pieces = new ArrayCollection();
        $this->conventionContrats = new ArrayCollection();
        $this->positioningAttempts = new ArrayCollection();
        $this->positioningAssignments = new ArrayCollection();
        $this->devis = new ArrayCollection();
        $this->qcmAssignments = new ArrayCollection();
        $this->dateCreation = new \DateTimeImmutable();
        $this->entrepriseDocuments = new ArrayCollection();
        $this->satisfactionAssignments = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSession(): ?Session
    {
        return $this->session;
    }
    public function setSession(?Session $session): static
    {
        $this->session = $session;
        return $this;
    }

    public function getStagiaire(): ?Utilisateur
    {
        return $this->stagiaire;
    }
    public function setStagiaire(?Utilisateur $stagiaire): static
    {
        $this->stagiaire = $stagiaire;
        return $this;
    }

    public function getStatus(): StatusInscription
    {
        return $this->status;
    }
    public function setStatus(StatusInscription $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getMontantDuCents(): ?int
    {
        return $this->montantDuCents;
    }
    public function setMontantDuCents(?int $v): static
    {
        $this->montantDuCents = $v;
        return $this;
    }

    public function getMontantRegleCents(): ?int
    {
        return $this->montantRegleCents;
    }
    public function setMontantRegleCents(?int $v): static
    {
        $this->montantRegleCents = $v;
        return $this;
    }

    public function getTauxAssiduite(): ?float
    {
        return $this->tauxAssiduite;
    }
    public function setTauxAssiduite(?float $v): static
    {
        $this->tauxAssiduite = $v;
        return $this;
    }

    public function isReussi(): bool
    {
        return $this->reussi;
    }
    public function setReussi(bool $v): static
    {
        $this->reussi = $v;
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

    public function getDossier(): ?DossierInscription
    {
        return $this->dossier;
    }
    public function setDossier(DossierInscription $dossier): static
    {
        if ($dossier->getInscription() !== $this) {
            $dossier->setInscription($this);
        }
        $this->dossier = $dossier;
        return $this;
    }

    public function getAttestation(): ?Attestation
    {
        return $this->attestation;
    }
    public function setAttestation(Attestation $attestation): static
    {
        if ($attestation->getInscription() !== $this) {
            $attestation->setInscription($this);
        }
        $this->attestation = $attestation;
        return $this;
    }

    public function getModeFinancement(): ModeFinancement
    {
        return $this->modeFinancement;
    }
    public function setModeFinancement(ModeFinancement $m): self
    {
        $this->modeFinancement = $m;
        return $this;
    }

    public function getEntreprise(): ?Entreprise
    {
        return $this->entreprise;
    }
    public function setEntreprise(?Entreprise $e): static
    {
        $this->entreprise = $e;
        return $this;
    }

    /** @return Collection<int, Facture> */
    public function getFactures(): Collection
    {
        return $this->factures;
    }
    public function addFacture(Facture $f): static
    {
        if (!$this->factures->contains($f)) {
            $this->factures->add($f);
            $f->addInscription($this);
        }
        return $this;
    }
    public function removeFacture(Facture $f): static
    {
        $this->factures->removeElement($f);
        return $this;
    }

    /** @return Collection<int, PieceDossier> */
    public function getPieces(): Collection
    {
        return $this->pieces;
    }
    public function addPiece(PieceDossier $piece): static
    {
        if (!$this->pieces->contains($piece)) {
            $this->pieces->add($piece);
            $piece->setInscription($this);
        }
        return $this;
    }
    public function removePiece(PieceDossier $piece): static
    {
        $this->pieces->removeElement($piece);
        return $this;
    }

    /** @return Collection<int, ConventionContrat> */
    public function getConventionContrats(): Collection
    {
        return $this->conventionContrats;
    }
    public function addConventionContrat(ConventionContrat $cc): static
    {
        if (!$this->conventionContrats->contains($cc)) {
            $this->conventionContrats->add($cc);
            $cc->addInscription($this);
        }
        return $this;
    }
    public function removeConventionContrat(ConventionContrat $cc): static
    {
        $this->conventionContrats->removeElement($cc);
        $cc->removeInscription($this);
        return $this;
    }

    public function getPiecesManquantes(): array
    {
        $required = $this->getSession()?->getPiecesObligatoires() ?? [];
        if (!$required) return [];

        $requiredEnums = [];
        foreach ($required as $r) {
            if ($r instanceof PieceType) $requiredEnums[] = $r;
            else if ($enum = PieceType::tryFrom((string)$r)) $requiredEnums[] = $enum;
        }

        $dossier = $this->getDossier();
        if (!$dossier) return $requiredEnums;

        $present = [];
        foreach ($dossier->getPieces() as $p) {
            if (!$p instanceof PieceDossier) continue;
            if (!$p->getType()) continue;
            if ($p->isValide()) {
                $present[$p->getType()->value] = true;
            }
        }

        $missing = [];
        foreach ($requiredEnums as $req) {
            if (!isset($present[$req->value])) $missing[] = $req;
        }
        return $missing;
    }

    public function isDossierComplet(): bool
    {
        $required = $this->getSession()?->getPiecesObligatoires() ?? [];
        if (!$required) return true;
        return \count($this->getPiecesManquantes()) === 0;
    }

    /** @return Collection<int, PositioningAttempt> */
    public function getPositioningAttempts(): Collection
    {
        return $this->positioningAttempts;
    }
    public function addPositioningAttempt(PositioningAttempt $a): static
    {
        if (!$this->positioningAttempts->contains($a)) {
            $this->positioningAttempts->add($a);
            $a->setInscription($this);
        }
        return $this;
    }
    public function removePositioningAttempt(PositioningAttempt $a): static
    {
        // NOT NULL côté attempt => pas de setInscription(null)
        $this->positioningAttempts->removeElement($a);
        return $this;
    }

    /** @return Collection<int, PositioningAssignment> */
    public function getPositioningAssignments(): Collection
    {
        return $this->positioningAssignments;
    }
    public function addPositioningAssignment(PositioningAssignment $a): static
    {
        if (!$this->positioningAssignments->contains($a)) {
            $this->positioningAssignments->add($a);
            $a->setInscription($this);
        }
        return $this;
    }
    public function removePositioningAssignment(PositioningAssignment $a): static
    {
        $this->positioningAssignments->removeElement($a);
        return $this;
    }

    /**
     * @return Collection<int, Devis>
     */
    public function getDevis(): Collection
    {
        return $this->devis;
    }

    public function addDevi(Devis $devi): static
    {
        if (!$this->devis->contains($devi)) {
            $this->devis->add($devi);
            $devi->addInscription($this);
        }

        return $this;
    }

    public function removeDevi(Devis $devi): static
    {
        if ($this->devis->removeElement($devi)) {
            $devi->removeInscription($this);
        }

        return $this;
    }

    /**
     * @return Collection<int, QcmAssignment>
     */
    public function getQcmAssignments(): Collection
    {
        return $this->qcmAssignments;
    }

    public function addQcmAssignment(QcmAssignment $qcmAssignment): static
    {
        if (!$this->qcmAssignments->contains($qcmAssignment)) {
            $this->qcmAssignments->add($qcmAssignment);
            $qcmAssignment->setInscription($this);
        }

        return $this;
    }

    public function removeQcmAssignment(QcmAssignment $qcmAssignment): static
    {
        $this->qcmAssignments->removeElement($qcmAssignment);
        return $this;
    }


    public function getContratStagiaire(): ?ContratStagiaire
    {
        return $this->contratStagiaire;
    }

    public function setContratStagiaire(?ContratStagiaire $c): static
    {
        $this->contratStagiaire = $c;
        if ($c && $c->getInscription() !== $this) {
            $c->setInscription($this);
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
     * @return Collection<int, EntrepriseDocument>
     */
    public function getEntrepriseDocuments(): Collection
    {
        return $this->entrepriseDocuments;
    }

    public function addEntrepriseDocument(EntrepriseDocument $entrepriseDocument): static
    {
        if (!$this->entrepriseDocuments->contains($entrepriseDocument)) {
            $this->entrepriseDocuments->add($entrepriseDocument);
            $entrepriseDocument->setInscription($this);
        }

        return $this;
    }

    public function removeEntrepriseDocument(EntrepriseDocument $entrepriseDocument): static
    {
        if ($this->entrepriseDocuments->removeElement($entrepriseDocument)) {
            // set the owning side to null (unless already changed)
            if ($entrepriseDocument->getInscription() === $this) {
                $entrepriseDocument->setInscription(null);
            }
        }

        return $this;
    }


    /** @return Collection<int, SatisfactionAssignment> */
    public function getSatisfactionAssignments(): Collection
    {
        return $this->satisfactionAssignments;
    }

    public function addSatisfactionAssignment(SatisfactionAssignment $a): static
    {
        if (!$this->satisfactionAssignments->contains($a)) {
            $this->satisfactionAssignments->add($a);
            $a->setInscription($this);
        }
        return $this;
    }

    public function removeSatisfactionAssignment(SatisfactionAssignment $a): static
    {
        $this->satisfactionAssignments->removeElement($a);
        return $this;
    }
}
