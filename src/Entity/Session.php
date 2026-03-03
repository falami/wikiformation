<?php
// src/Entity/Session.php

namespace App\Entity;

use App\Entity\SessionPiece;
use App\Repository\SessionRepository;
use App\Enum\StatusSession;
use App\Enum\TypeFinancement;
use App\Enum\PieceType;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use App\Entity\Formateur; // en haut du fichier si pas déjà présent
use Symfony\Component\Validator\Context\ExecutionContextInterface;


#[ORM\Entity(repositoryClass: SessionRepository::class)]
#[ORM\Table(name: 'session')]
#[ORM\UniqueConstraint(name: 'uniq_session_code', columns: ['code'])]
class Session
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'sessions')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Formation $formation = null;

    #[ORM\ManyToOne(inversedBy: 'sessions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Site $site = null;

    #[ORM\ManyToOne(inversedBy: 'sessions')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Engin $engin = null;

    #[ORM\ManyToOne(inversedBy: 'sessions')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Formateur $formateur = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private ?string $code = null;

    #[ORM\Column(nullable: false, options: ['unsigned' => true])]
    #[Assert\Positive]
    private int $capacite = 8;

    #[ORM\Column(enumType: StatusSession::class)]
    private StatusSession $status = StatusSession::DRAFT;

    #[ORM\Column(nullable: true, options: ['unsigned' => true])]
    private ?int $montantCents = null;

    // 🖥️ Équipements pédagogiques
    #[ORM\Column(options: ['default' => true])]
    private bool $equipOrdinateurFormateur = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $equipVideoprojecteurEcran = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $equipInternetStable = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $equipTableauPaperboard = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $equipMarqueursSupportsImprimes = true;

    // 🪑 Salle et confort
    #[ORM\Column(options: ['default' => true])]
    private bool $salleAdapteeTailleGroupe = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $salleTablesChaisesErgo = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $salleLumiereChauffageClim = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $salleEauCafe = true;


    /**
     * @var Collection<int, Reservation>
     */
    #[ORM\OneToMany(targetEntity: Reservation::class, mappedBy: 'session', orphanRemoval: true)]
    private Collection $reservations;

    /**
     * @var Collection<int, Emargement>
     */
    #[ORM\OneToMany(targetEntity: Emargement::class, mappedBy: 'session', orphanRemoval: true)]
    private Collection $emargements;

    /**
     * @var Collection<int, SupportDocument>
     */
    #[ORM\OneToMany(targetEntity: SupportDocument::class, mappedBy: 'session')]
    private Collection $supportDocuments;

    /**
     * @var Collection<int, SupportAssignSession>
     */
    #[ORM\OneToMany(targetEntity: SupportAssignSession::class, mappedBy: 'session')]
    private Collection $supportAssignSessions;

    /**
     * @var Collection<int, SessionJour>
     */
    #[ORM\OneToMany(mappedBy: 'session', targetEntity: SessionJour::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['dateDebut' => 'ASC'])]
    #[Assert\Valid]
    private Collection $jours;

    /**
     * @var Collection<int, Inscription>
     */
    #[ORM\OneToMany(targetEntity: Inscription::class, mappedBy: 'session', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $inscriptions;

    /**
     * @var Collection<int, QuestionnaireSatisfaction>
     */
    #[ORM\OneToMany(
        targetEntity: QuestionnaireSatisfaction::class,
        mappedBy: 'session',
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    private Collection $questionnaireSatisfactions;


    /**
     * @var Collection<int, RapportFormateur>
     */
    #[ORM\OneToMany(targetEntity: RapportFormateur::class, mappedBy: 'session')]
    private Collection $rapportFormateurs;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $piecesObligatoires = null;

    #[ORM\ManyToOne(inversedBy: 'sessions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Entite $entite = null;

    /**
     * @var Collection<int, ContratFormateur>
     */
    #[ORM\OneToMany(targetEntity: ContratFormateur::class, mappedBy: 'session')]
    private Collection $contratFormateurs;

    /**
     * @var Collection<int, ConventionContrat>
     */
    #[ORM\OneToMany(targetEntity: ConventionContrat::class, mappedBy: 'session')]
    private Collection $conventionContrats;

    /**
     * @var Collection<int, SessionPositioning>
     */
    #[ORM\OneToMany(
        targetEntity: SessionPositioning::class,
        mappedBy: 'session',
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    private Collection $sessionPositionings;


    /**
     * @var Collection<int, PositioningAttempt>
     */
    #[ORM\OneToMany(targetEntity: PositioningAttempt::class, mappedBy: 'session', orphanRemoval: true)]
    private Collection $positioningAttempts;

    /**
     * ✅ NOUVEAU : pour inversedBy="positioningAssignments" dans PositioningAssignment
     * @var Collection<int, PositioningAssignment>
     */
    #[ORM\OneToMany(targetEntity: PositioningAssignment::class, mappedBy: 'session', orphanRemoval: true)]
    private Collection $positioningAssignments;

    /**
     * @var Collection<int, SatisfactionAssignment>
     */
    #[ORM\OneToMany(targetEntity: SatisfactionAssignment::class, mappedBy: 'session')]
    private Collection $satisfactionAssignments;

    /**
     * @var Collection<int, FormateurSatisfactionAssignment>
     */
    #[ORM\OneToMany(targetEntity: FormateurSatisfactionAssignment::class, mappedBy: 'session')]
    private Collection $formateurSatisfactionAssignments;

    /**
     * @var Collection<int, QcmAssignment>
     */
    #[ORM\OneToMany(
        targetEntity: QcmAssignment::class,
        mappedBy: 'session',
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    private Collection $qcmAssignments;

    #[ORM\ManyToOne(inversedBy: 'sessionCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;


    #[ORM\Column(enumType: TypeFinancement::class)]
    private TypeFinancement $typeFinancement = TypeFinancement::OPCO;


    /**
     * @var Collection<int, SessionPiece>
     */
    #[ORM\OneToMany(mappedBy: 'session', targetEntity: SessionPiece::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['uploadedAt' => 'DESC'])]
    private Collection $pieces;



    #[ORM\Column(length: 255, nullable: true)]
    private ?string $formationIntituleLibre = null;

    /**
     * @var Collection<int, EntrepriseDocument>
     */
    #[ORM\OneToMany(targetEntity: EntrepriseDocument::class, mappedBy: 'session')]
    private Collection $entrepriseDocuments;


    #[ORM\ManyToOne(targetEntity: Entreprise::class, inversedBy: 'sessions')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Entreprise $organismeFormation = null;






    public function __construct()
    {
        $this->reservations = new ArrayCollection();
        $this->emargements = new ArrayCollection();
        $this->supportDocuments = new ArrayCollection();
        $this->supportAssignSessions = new ArrayCollection();
        $this->jours = new ArrayCollection();
        $this->inscriptions = new ArrayCollection();
        $this->questionnaireSatisfactions = new ArrayCollection();
        $this->rapportFormateurs = new ArrayCollection();
        $this->contratFormateurs = new ArrayCollection();
        $this->conventionContrats = new ArrayCollection();
        $this->sessionPositionings = new ArrayCollection();
        $this->positioningAttempts = new ArrayCollection();
        $this->positioningAssignments = new ArrayCollection();
        $this->satisfactionAssignments = new ArrayCollection();
        $this->formateurSatisfactionAssignments = new ArrayCollection();
        $this->qcmAssignments = new ArrayCollection();
        $this->dateCreation = new \DateTimeImmutable();
        $this->pieces = new ArrayCollection();
        $this->entrepriseDocuments = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getSite(): ?Site
    {
        return $this->site;
    }
    public function setSite(?Site $site): static
    {
        $this->site = $site;
        return $this;
    }

    public function getEngin(): ?Engin
    {
        return $this->engin;
    }
    public function setEngin(?Engin $engin): static
    {
        $this->engin = $engin;
        return $this;
    }

    public function getFormateur(): ?Formateur
    {
        return $this->formateur;
    }
    public function setFormateur(?Formateur $formateur): static
    {
        $this->formateur = $formateur;
        return $this;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }
    public function setCode(string $code): static
    {
        $this->code = $code;
        return $this;
    }

    public function getCapacite(): int
    {
        return $this->capacite;
    }
    public function setCapacite(int $capacite): static
    {
        $this->capacite = $capacite;
        return $this;
    }

    public function getMontantCents(): ?int
    {
        return $this->montantCents;
    }
    public function setMontantCents(?int $montantCents): static
    {
        $this->montantCents = $montantCents;
        return $this;
    }

    public function getTarifEffectifCents(): int
    {
        return $this->montantCents ?? $this->formation?->getPrixBaseCents() ?? 0;
    }

    public function getStatus(): StatusSession
    {
        return $this->status;
    }
    public function setStatus(StatusSession $status): static
    {
        $this->status = $status;
        return $this;
    }

    /** @return Collection<int, Reservation> */
    public function getReservations(): Collection
    {
        return $this->reservations;
    }
    public function addReservation(Reservation $r): static
    {
        if (!$this->reservations->contains($r)) {
            $this->reservations->add($r);
            $r->setSession($this);
        }
        return $this;
    }
    public function removeReservation(Reservation $r): static
    {
        $this->reservations->removeElement($r);
        return $this;
    }

    /** @return Collection<int, Emargement> */
    public function getEmargements(): Collection
    {
        return $this->emargements;
    }
    public function addEmargement(Emargement $e): static
    {
        if (!$this->emargements->contains($e)) {
            $this->emargements->add($e);
            $e->setSession($this);
        }
        return $this;
    }
    public function removeEmargement(Emargement $e): static
    {
        $this->emargements->removeElement($e);
        return $this;
    }

    /** @return Collection<int, SupportDocument> */
    public function getSupportDocuments(): Collection
    {
        return $this->supportDocuments;
    }
    public function addSupportDocument(SupportDocument $sd): static
    {
        if (!$this->supportDocuments->contains($sd)) {
            $this->supportDocuments->add($sd);
            $sd->setSession($this);
        }
        return $this;
    }
    public function removeSupportDocument(SupportDocument $sd): static
    {
        $this->supportDocuments->removeElement($sd);
        return $this;
    }

    /** @return Collection<int, SupportAssignSession> */
    public function getSupportAssignSessions(): Collection
    {
        return $this->supportAssignSessions;
    }
    public function addSupportAssignSession(SupportAssignSession $sas): static
    {
        if (!$this->supportAssignSessions->contains($sas)) {
            $this->supportAssignSessions->add($sas);
            $sas->setSession($this);
        }
        return $this;
    }
    public function removeSupportAssignSession(SupportAssignSession $sas): static
    {
        $this->supportAssignSessions->removeElement($sas);
        return $this;
    }

    /** @return Collection<int, SessionJour> */
    public function getJours(): Collection
    {
        return $this->jours;
    }
    public function addJour(SessionJour $j): self
    {
        if (!$this->jours->contains($j)) {
            $this->jours->add($j);
            $j->setSession($this);
        }
        return $this;
    }
    public function removeJour(SessionJour $j): self
    {
        $this->jours->removeElement($j);
        return $this;
    }

    /** @return Collection<int, Inscription> */
    public function getInscriptions(): Collection
    {
        return $this->inscriptions;
    }
    public function addInscription(Inscription $i): static
    {
        if (!$this->inscriptions->contains($i)) {
            $this->inscriptions->add($i);
            $i->setSession($this);
        }
        return $this;
    }
    public function removeInscription(Inscription $i): static
    {
        $this->inscriptions->removeElement($i);
        return $this;
    }

    /** @return Collection<int, QuestionnaireSatisfaction> */
    public function getQuestionnaireSatisfactions(): Collection
    {
        return $this->questionnaireSatisfactions;
    }
    public function addQuestionnaireSatisfaction(QuestionnaireSatisfaction $qs): static
    {
        if (!$this->questionnaireSatisfactions->contains($qs)) {
            $this->questionnaireSatisfactions->add($qs);
            $qs->setSession($this);
        }
        return $this;
    }
    public function removeQuestionnaireSatisfaction(QuestionnaireSatisfaction $qs): static
    {
        $this->questionnaireSatisfactions->removeElement($qs);
        return $this;
    }

    /** @return Collection<int, RapportFormateur> */
    public function getRapportFormateurs(): Collection
    {
        return $this->rapportFormateurs;
    }
    public function addRapportFormateur(RapportFormateur $rf): static
    {
        if (!$this->rapportFormateurs->contains($rf)) {
            $this->rapportFormateurs->add($rf);
            $rf->setSession($this);
        }
        return $this;
    }
    public function removeRapportFormateur(RapportFormateur $rf): static
    {
        $this->rapportFormateurs->removeElement($rf);
        return $this;
    }

    public function getDateDebut(): ?\DateTimeImmutable
    {
        if ($this->jours->isEmpty()) return null;
        $min = null;
        foreach ($this->jours as $jour) {
            /** @var SessionJour $jour */
            $d = $jour->getDateDebut();
            if ($d !== null && ($min === null || $d < $min)) $min = $d;
        }
        return $min;
    }

    public function getDateFin(): ?\DateTimeImmutable
    {
        if ($this->jours->isEmpty()) return null;
        $max = null;
        foreach ($this->jours as $jour) {
            /** @var SessionJour $jour */
            $d = $jour->getDateFin();
            if ($d !== null && ($max === null || $d > $max)) $max = $d;
        }
        return $max;
    }


    public function getPiecesObligatoires(): array
    {
        return $this->piecesObligatoires ?? [];
    }

    /** @param array<string|PieceType> $pieces */
    public function setPiecesObligatoires(array $pieces): static
    {
        $values = [];
        foreach ($pieces as $p) {
            if ($p instanceof PieceType) $values[] = $p->value;
            elseif (is_string($p)) $values[] = $p;
        }
        $this->piecesObligatoires = array_values(array_unique($values));
        return $this;
    }

    /** @return PieceType[] */
    public function getPiecesObligatoiresEnums(): array
    {
        $out = [];
        foreach ($this->getPiecesObligatoires() as $val) {
            if ($enum = PieceType::tryFrom($val)) $out[] = $enum;
        }
        return $out;
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

    /** @return Collection<int, ContratFormateur> */
    public function getContratFormateurs(): Collection
    {
        return $this->contratFormateurs;
    }
    public function addContratFormateur(ContratFormateur $cf): static
    {
        if (!$this->contratFormateurs->contains($cf)) {
            $this->contratFormateurs->add($cf);
            $cf->setSession($this);
        }
        return $this;
    }
    public function removeContratFormateur(ContratFormateur $cf): static
    {
        $this->contratFormateurs->removeElement($cf);
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
            $cc->setSession($this);
        }
        return $this;
    }
    public function removeConventionContrat(ConventionContrat $cc): static
    {
        $this->conventionContrats->removeElement($cc);
        return $this;
    }

    /** @return Collection<int, SessionPositioning> */
    public function getSessionPositionings(): Collection
    {
        return $this->sessionPositionings;
    }
    public function addSessionPositioning(SessionPositioning $sp): static
    {
        if (!$this->sessionPositionings->contains($sp)) {
            $this->sessionPositionings->add($sp);
            $sp->setSession($this);
        }
        return $this;
    }
    public function removeSessionPositioning(SessionPositioning $sp): static
    {
        $this->sessionPositionings->removeElement($sp);
        return $this;
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
            $a->setSession($this);
        }
        return $this;
    }
    public function removePositioningAttempt(PositioningAttempt $a): static
    {
        // NOT NULL côté attempt => pas de setSession(null)
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
            $a->setSession($this);
        }
        return $this;
    }
    public function removePositioningAssignment(PositioningAssignment $a): static
    {
        $this->positioningAssignments->removeElement($a);
        return $this;
    }



    public function getNombreJoursPourFormateur(Formateur $formateur): int
    {
        $jours = 0;

        foreach ($this->getJours() as $jour) {
            // Si SessionJour gère un formateur par jour
            if (method_exists($jour, 'getFormateur')) {
                $jf = $jour->getFormateur();

                // Jour attribué explicitement à ce formateur
                if ($jf && $jf->getId() === $formateur->getId()) {
                    $jours++;
                    continue;
                }

                // Si un formateur est défini sur le jour MAIS différent => ne pas compter
                if ($jf && $jf->getId() !== $formateur->getId()) {
                    continue;
                }
            }

            // Sinon, on retombe sur le formateur "référent" de la session
            if ($this->getFormateur() && $this->getFormateur()->getId() === $formateur->getId()) {
                $jours++;
            }
        }

        return $jours;
    }

    public function getNombreHeuresPourFormateur(Formateur $formateur): float
    {
        $seconds = 0;

        foreach ($this->getJours() as $jour) {
            $debut = $jour->getDateDebut();
            $fin   = $jour->getDateFin();

            if (!$debut || !$fin) {
                continue;
            }

            // Même logique que pour les jours
            if (method_exists($jour, 'getFormateur')) {
                $jf = $jour->getFormateur();

                if ($jf && $jf->getId() === $formateur->getId()) {
                    $seconds += max(0, $fin->getTimestamp() - $debut->getTimestamp());
                    continue;
                }

                if ($jf && $jf->getId() !== $formateur->getId()) {
                    continue;
                }
            }

            if ($this->getFormateur() && $this->getFormateur()->getId() === $formateur->getId()) {
                $seconds += max(0, $fin->getTimestamp() - $debut->getTimestamp());
            }
        }

        return round($seconds / 3600, 2);
    }

    /**
     * @return Collection<int, SatisfactionAssignment>
     */
    public function getSatisfactionAssignments(): Collection
    {
        return $this->satisfactionAssignments;
    }

    public function addSatisfactionAssignment(SatisfactionAssignment $satisfactionAssignment): static
    {
        if (!$this->satisfactionAssignments->contains($satisfactionAssignment)) {
            $this->satisfactionAssignments->add($satisfactionAssignment);
            $satisfactionAssignment->setSession($this);
        }

        return $this;
    }

    public function removeSatisfactionAssignment(SatisfactionAssignment $satisfactionAssignment): static
    {
        if ($this->satisfactionAssignments->removeElement($satisfactionAssignment)) {
            // set the owning side to null (unless already changed)
            if ($satisfactionAssignment->getSession() === $this) {
                $satisfactionAssignment->setSession(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, FormateurSatisfactionAssignment>
     */
    public function getFormateurSatisfactionAssignments(): Collection
    {
        return $this->formateurSatisfactionAssignments;
    }

    public function addFormateurSatisfactionAssignment(FormateurSatisfactionAssignment $formateurSatisfactionAssignment): static
    {
        if (!$this->formateurSatisfactionAssignments->contains($formateurSatisfactionAssignment)) {
            $this->formateurSatisfactionAssignments->add($formateurSatisfactionAssignment);
            $formateurSatisfactionAssignment->setSession($this);
        }

        return $this;
    }

    public function removeFormateurSatisfactionAssignment(FormateurSatisfactionAssignment $formateurSatisfactionAssignment): static
    {
        if ($this->formateurSatisfactionAssignments->removeElement($formateurSatisfactionAssignment)) {
            // set the owning side to null (unless already changed)
            if ($formateurSatisfactionAssignment->getSession() === $this) {
                $formateurSatisfactionAssignment->setSession(null);
            }
        }

        return $this;
    }


    public function getLabel(): string
    {
        $ville = $this->site?->getVille() ?: $this->site?->getNom() ?: 'Site ?';
        $formation = $this->formation?->getTitre() ?: 'Formation ?';

        $d1 = $this->getDateDebut();
        $d2 = $this->getDateFin();

        $dates = '';
        if ($d1 || $d2) {
            $dates = sprintf(
                ' • %s → %s',
                $d1?->format('d/m/Y') ?? '…',
                $d2?->format('d/m/Y') ?? '…'
            );
        }

        return sprintf('%s • %s%s', $ville, $formation, $dates);
    }

    public function isEquipOrdinateurFormateur(): bool
    {
        return $this->equipOrdinateurFormateur;
    }
    public function setEquipOrdinateurFormateur(bool $v): static
    {
        $this->equipOrdinateurFormateur = $v;
        return $this;
    }

    public function isEquipVideoprojecteurEcran(): bool
    {
        return $this->equipVideoprojecteurEcran;
    }
    public function setEquipVideoprojecteurEcran(bool $v): static
    {
        $this->equipVideoprojecteurEcran = $v;
        return $this;
    }

    public function isEquipInternetStable(): bool
    {
        return $this->equipInternetStable;
    }
    public function setEquipInternetStable(bool $v): static
    {
        $this->equipInternetStable = $v;
        return $this;
    }

    public function isEquipTableauPaperboard(): bool
    {
        return $this->equipTableauPaperboard;
    }
    public function setEquipTableauPaperboard(bool $v): static
    {
        $this->equipTableauPaperboard = $v;
        return $this;
    }

    public function isEquipMarqueursSupportsImprimes(): bool
    {
        return $this->equipMarqueursSupportsImprimes;
    }
    public function setEquipMarqueursSupportsImprimes(bool $v): static
    {
        $this->equipMarqueursSupportsImprimes = $v;
        return $this;
    }

    public function isSalleAdapteeTailleGroupe(): bool
    {
        return $this->salleAdapteeTailleGroupe;
    }
    public function setSalleAdapteeTailleGroupe(bool $v): static
    {
        $this->salleAdapteeTailleGroupe = $v;
        return $this;
    }

    public function isSalleTablesChaisesErgo(): bool
    {
        return $this->salleTablesChaisesErgo;
    }
    public function setSalleTablesChaisesErgo(bool $v): static
    {
        $this->salleTablesChaisesErgo = $v;
        return $this;
    }

    public function isSalleLumiereChauffageClim(): bool
    {
        return $this->salleLumiereChauffageClim;
    }
    public function setSalleLumiereChauffageClim(bool $v): static
    {
        $this->salleLumiereChauffageClim = $v;
        return $this;
    }

    public function isSalleEauCafe(): bool
    {
        return $this->salleEauCafe;
    }
    public function setSalleEauCafe(bool $v): static
    {
        $this->salleEauCafe = $v;
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
            $qcmAssignment->setSession($this);
        }

        return $this;
    }

    public function removeQcmAssignment(QcmAssignment $qcmAssignment): static
    {
        $this->qcmAssignments->removeElement($qcmAssignment);
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

    public function getDateCreation(): ?\DateTimeImmutable
    {
        return $this->dateCreation;
    }

    public function setDateCreation(\DateTimeImmutable $dateCreation): static
    {
        $this->dateCreation = $dateCreation;

        return $this;
    }


    public function getTypeFinancement(): TypeFinancement
    {
        return $this->typeFinancement;
    }
    public function setTypeFinancement(TypeFinancement $typeFinancement): static
    {
        $this->typeFinancement = $typeFinancement;
        return $this;
    }

    /** @return Collection<int, SessionPiece> */
    public function getPieces(): Collection
    {
        return $this->pieces;
    }

    public function addPiece(SessionPiece $piece): self
    {
        if (!$this->pieces->contains($piece)) {
            $this->pieces->add($piece);
            $piece->setSession($this);
        }
        return $this;
    }

    public function removePiece(SessionPiece $piece): self
    {
        if ($this->pieces->removeElement($piece)) {
            if ($piece->getSession() === $this) {
                $piece->setSession(null);
            }
        }
        return $this;
    }

    public function getFormationIntituleLibre(): ?string
    {
        return $this->formationIntituleLibre;
    }

    public function setFormationIntituleLibre(?string $v): static
    {
        $this->formationIntituleLibre = $v ? trim($v) : null;
        return $this;
    }

    public function getFormationLabel(): string
    {
        // ✅ partout dans l’app tu utilises ça pour afficher
        if ($this->typeFinancement === TypeFinancement::OF) {
            return $this->formationIntituleLibre ?: '—';
        }
        return $this->formation?->getTitre() ?: '—';
    }

    #[Assert\Callback]
    public function validateFormationAccordingToFinancement(ExecutionContextInterface $context): void
    {
        if ($this->typeFinancement === TypeFinancement::OF) {
            // Sous-traitance OF => PAS de Formation entity, mais intitulé obligatoire
            if ($this->formation !== null) {
                $context->buildViolation('En mode "Organisme de formation", ne rattache pas une formation interne.')
                    ->atPath('formation')
                    ->addViolation();
            }
            if (!$this->formationIntituleLibre) {
                $context->buildViolation('Renseigne l’intitulé de la formation (champ libre) pour la sous-traitance.')
                    ->atPath('formationIntituleLibre')
                    ->addViolation();
            }
        } else {
            // Tous les autres financements => formation obligatoire, champ libre vide
            if ($this->formation === null) {
                $context->buildViolation('Sélectionne une formation.')
                    ->atPath('formation')
                    ->addViolation();
            }
            if ($this->formationIntituleLibre) {
                $context->buildViolation('Le champ "intitulé libre" doit rester vide hors sous-traitance.')
                    ->atPath('formationIntituleLibre')
                    ->addViolation();
            }
        }
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
            $entrepriseDocument->setSession($this);
        }

        return $this;
    }

    public function removeEntrepriseDocument(EntrepriseDocument $entrepriseDocument): static
    {
        if ($this->entrepriseDocuments->removeElement($entrepriseDocument)) {
            // set the owning side to null (unless already changed)
            if ($entrepriseDocument->getSession() === $this) {
                $entrepriseDocument->setSession(null);
            }
        }

        return $this;
    }

    public function getOrganismeFormation(): ?Entreprise
    {
        return $this->organismeFormation;
    }

    public function setOrganismeFormation(?Entreprise $organismeFormation): static
    {
        $this->organismeFormation = $organismeFormation;
        return $this;
    }
}
