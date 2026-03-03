<?php

namespace App\Entity;

use App\Repository\ProspectRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Enum\ProspectStatus;
use App\Enum\ProspectSource;

#[ORM\Entity(repositoryClass: ProspectRepository::class)]
#[ORM\HasLifecycleCallbacks] // ✅ AJOUTE ÇA
#[ORM\Index(columns: ['status'])]
#[ORM\Index(columns: ['next_action_at'])]
#[ORM\Index(columns: ['created_at'])]
class Prospect
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'prospects')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Entite $entite = null;

    #[ORM\ManyToOne(inversedBy: 'prospects')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $linkedUser = null;

    #[ORM\Column(length: 100)]
    private string $prenom = '';

    #[ORM\Column(length: 100)]
    private string $nom = '';

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $telephone = null;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $societe = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $poste = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $ville = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $pays = null;

    #[ORM\Column(enumType: ProspectStatus::class)]
    private ProspectStatus $status = ProspectStatus::NEW;

    #[ORM\Column(enumType: ProspectSource::class)]
    private ProspectSource $source = ProspectSource::OTHER;

    #[ORM\Column(options: ['unsigned' => true])]
    private ?int $score = 0;

    #[ORM\Column(nullable: true, options: ['unsigned' => true])]
    private ?int $estimatedValueCents = null;

    #[ORM\Column(length: 3)]
    private string $devise = 'EUR';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $nextActionAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    /**
     * @var Collection<int, Devis>
     */
    #[ORM\OneToMany(targetEntity: Devis::class, mappedBy: 'prospect')]
    private Collection $devis;

    /**
     * @var Collection<int, ProspectInteraction>
     */
    #[ORM\OneToMany(mappedBy: 'prospect', targetEntity: ProspectInteraction::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['occurredAt' => 'DESC'])]
    private Collection $interactions;

    /**
     * @var Collection<int, EmailLog>
     */
    #[ORM\OneToMany(targetEntity: EmailLog::class, mappedBy: 'prospect')]
    private Collection $emailLogs;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $adresse = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $complement = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $codePostal = null;

    #[ORM\Column(length: 15, nullable: true)]
    private ?string $civilite = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $region = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $departement = null;


    #[ORM\Column(length: 14, nullable: true)]
    private ?string $siret = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $emailFacturation = null;

    #[ORM\ManyToOne(inversedBy: 'prospects')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Entreprise $linkedEntreprise = null;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $convertedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $raisonSociale = null;

    #[ORM\ManyToOne(inversedBy: 'prospectCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    /**
     * @var Collection<int, ProspectInteraction>
     */
    #[ORM\OneToMany(targetEntity: ProspectInteraction::class, mappedBy: 'actorProspect')]
    private Collection $prospectInteractions;


    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->devis = new ArrayCollection();
        $this->interactions = new ArrayCollection();
        $this->emailLogs = new ArrayCollection();
        $this->dateCreation = new \DateTimeImmutable();
        $this->prospectInteractions = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now');
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getLinkedUser(): ?Utilisateur
    {
        return $this->linkedUser;
    }

    public function setLinkedUser(?Utilisateur $linkedUser): static
    {
        $this->linkedUser = $linkedUser;

        return $this;
    }

    public function getPrenom(): string
    {
        return $this->prenom;
    }

    public function setPrenom(string $prenom): static
    {
        $this->prenom = $prenom;

        return $this;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(?string $telephone): static
    {
        $this->telephone = $telephone;

        return $this;
    }

    public function getSociete(): ?string
    {
        return $this->societe;
    }

    public function setSociete(?string $societe): static
    {
        $this->societe = $societe;

        return $this;
    }

    public function getPoste(): ?string
    {
        return $this->poste;
    }

    public function setPoste(?string $poste): static
    {
        $this->poste = $poste;

        return $this;
    }

    public function getVille(): ?string
    {
        return $this->ville;
    }

    public function setVille(?string $ville): static
    {
        $this->ville = $ville;

        return $this;
    }

    public function getPays(): ?string
    {
        return $this->pays;
    }

    public function setPays(?string $pays): static
    {
        $this->pays = $pays;

        return $this;
    }

    public function getScore(): ?int
    {
        return $this->score;
    }

    public function setScore(?int $score): static
    {
        $this->score = $score;

        return $this;
    }

    public function getEstimatedValueCents(): ?int
    {
        return $this->estimatedValueCents;
    }

    public function setEstimatedValueCents(?int $estimatedValueCents): static
    {
        $this->estimatedValueCents = $estimatedValueCents;

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

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

        return $this;
    }

    public function getNextActionAt(): ?\DateTimeImmutable
    {
        return $this->nextActionAt;
    }

    public function setNextActionAt(?\DateTimeImmutable $nextActionAt): static
    {
        $this->nextActionAt = $nextActionAt;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function isActive(): ?bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    /**
     * @return Collection<int, Devis>
     */
    public function getDevis(): Collection
    {
        return $this->devis;
    }

    public function addDevis(Devis $devis): static
    {
        if (!$this->devis->contains($devis)) {
            $this->devis->add($devis);
            $devis->setProspect($this);
        }
        return $this;
    }

    public function removeDevis(Devis $devis): static
    {
        if ($this->devis->removeElement($devis)) {
            if ($devis->getProspect() === $this) {
                $devis->setProspect(null);
            }
        }
        return $this;
    }


    /**
     * @return Collection<int, ProspectInteraction>
     */
    public function getInteractions(): Collection
    {
        return $this->interactions;
    }

    public function addInteraction(ProspectInteraction $interaction): static
    {
        if (!$this->interactions->contains($interaction)) {
            $this->interactions->add($interaction);
            $interaction->setProspect($this);
        }

        return $this;
    }

    public function removeInteraction(ProspectInteraction $interaction): static
    {
        if ($this->interactions->removeElement($interaction)) {
            // set the owning side to null (unless already changed)
            if ($interaction->getProspect() === $this) {
                $interaction->setProspect(null);
            }
        }

        return $this;
    }


    public function getStatus(): ProspectStatus
    {
        return $this->status;
    }

    public function setStatus(ProspectStatus $status): static
    {
        $this->status = $status;

        return $this;
    }


    public function getSource(): ProspectSource
    {
        return $this->source;
    }

    public function setSource(ProspectSource $source): static
    {
        $this->source = $source;

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
            $emailLog->setProspect($this);
        }

        return $this;
    }

    public function removeEmailLog(EmailLog $emailLog): static
    {
        if ($this->emailLogs->removeElement($emailLog)) {
            // set the owning side to null (unless already changed)
            if ($emailLog->getProspect() === $this) {
                $emailLog->setProspect(null);
            }
        }

        return $this;
    }


    public function getFullName(): string
    {
        $n = trim(($this->prenom ?? '') . ' ' . ($this->nom ?? ''));
        return $n !== '' ? $n : ('Prospect #' . ($this->id ?? ''));
    }

    public function getAdresse(): ?string
    {
        return $this->adresse;
    }

    public function setAdresse(?string $adresse): static
    {
        $this->adresse = $adresse;

        return $this;
    }

    public function getComplement(): ?string
    {
        return $this->complement;
    }

    public function setComplement(?string $complement): static
    {
        $this->complement = $complement;

        return $this;
    }

    public function getCodePostal(): ?string
    {
        return $this->codePostal;
    }

    public function setCodePostal(?string $codePostal): static
    {
        $this->codePostal = $codePostal;

        return $this;
    }

    public function getCivilite(): ?string
    {
        return $this->civilite;
    }

    public function setCivilite(?string $civilite): static
    {
        $this->civilite = $civilite;

        return $this;
    }

    public function getRegion(): ?string
    {
        return $this->region;
    }

    public function setRegion(?string $region): static
    {
        $this->region = $region;

        return $this;
    }

    public function getDepartement(): ?string
    {
        return $this->departement;
    }

    public function setDepartement(?string $departement): static
    {
        $this->departement = $departement;

        return $this;
    }

    public function getSiret(): ?string
    {
        return $this->siret;
    }

    public function setSiret(?string $siret): static
    {
        $this->siret = $siret;

        return $this;
    }

    public function getEmailFacturation(): ?string
    {
        return $this->emailFacturation;
    }

    public function setEmailFacturation(?string $emailFacturation): static
    {
        $this->emailFacturation = $emailFacturation;

        return $this;
    }

    public function getLinkedEntreprise(): ?Entreprise
    {
        return $this->linkedEntreprise;
    }

    public function setLinkedEntreprise(?Entreprise $linkedEntreprise): static
    {
        $this->linkedEntreprise = $linkedEntreprise;

        return $this;
    }

    public function getConvertedAt(): ?\DateTimeImmutable
    {
        return $this->convertedAt;
    }

    public function setConvertedAt(?\DateTimeImmutable $convertedAt): static
    {
        $this->convertedAt = $convertedAt;

        return $this;
    }

    public function markConverted(): void
    {
        $this->setIsActive(false);
        $this->setStatus(ProspectStatus::CONVERTED); // on ajoute ce status
        $this->convertedAt = new \DateTimeImmutable();
        $this->touch();
    }

    public function getRaisonSociale(): ?string
    {
        return $this->raisonSociale;
    }

    public function setRaisonSociale(?string $raisonSociale): static
    {
        $this->raisonSociale = $raisonSociale;

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
            $prospectInteraction->setActorProspect($this);
        }

        return $this;
    }

    public function removeProspectInteraction(ProspectInteraction $prospectInteraction): static
    {
        if ($this->prospectInteractions->removeElement($prospectInteraction)) {
            // set the owning side to null (unless already changed)
            if ($prospectInteraction->getActorProspect() === $this) {
                $prospectInteraction->setActorProspect(null);
            }
        }

        return $this;
    }
}
