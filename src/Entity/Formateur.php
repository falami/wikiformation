<?php

namespace App\Entity;

use App\Repository\FormateurRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: FormateurRepository::class)]
class Formateur
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'formateur')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Utilisateur $utilisateur = null;

    /**
     * @var Collection<int, Engin>
     */
    #[ORM\ManyToMany(targetEntity: Engin::class, inversedBy: 'formateurQualifications')]
    #[ORM\JoinTable(name: 'formateur_engin_qualification')]
    private Collection $qualificationEngins;

    /**
     * @var Collection<int, Site>
     */
    #[ORM\ManyToMany(targetEntity: Site::class, inversedBy: 'sitePreferesFormateur')]
    #[ORM\JoinTable(name: 'formateur_sites')]
    private Collection $sitePreferes;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $certifications = null;

    /**
     * @var Collection<int, Session>
     */
    #[ORM\OneToMany(targetEntity: Session::class, mappedBy: 'formateur')]
    private Collection $sessions;

    /**
     * @var Collection<int, Formation>
     */
    #[ORM\OneToMany(targetEntity: Formation::class, mappedBy: 'formateur')]
    private Collection $formations;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo = null;

    /**
     * @var Collection<int, ContratFormateur>
     */
    #[ORM\OneToMany(targetEntity: ContratFormateur::class, mappedBy: 'formateur')]
    private Collection $contratFormateurs;

    #[ORM\ManyToOne(inversedBy: 'formateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Entite $entite = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private ?bool $assujettiTva = null;

    #[ORM\Column(type: 'float', nullable: true)]
    #[Assert\GreaterThanOrEqual(0)]
    private ?float $tauxTvaParDefaut = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $numeroTvaIntra = null;

    #[ORM\Column(nullable: true)]
    private ?int $tauxHoraireCents = null;

    #[ORM\Column(nullable: true)]
    private ?int $tauxJournalierCents = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $modeRemuneration = null;

    /**
     * @var Collection<int, SessionJour>
     */
    #[ORM\OneToMany(targetEntity: SessionJour::class, mappedBy: 'formateur')]
    private Collection $sessionJours;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $signatureDataUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $signatureImagePath = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $adresse = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $complement = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $ville = null;

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $codePostal = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $pays = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $siret = null;


    #[ORM\OneToMany(mappedBy: 'formateur', targetEntity: RapportFormateur::class, orphanRemoval: true)]
    private Collection $rapportFormateurs;



    /**
     * @var Collection<int, PositioningAttempt>
     */
    #[ORM\OneToMany(mappedBy: 'assignedFormateur', targetEntity: PositioningAttempt::class)]
    private Collection $positioningAttempts;

    #[ORM\ManyToOne(inversedBy: 'formateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $dateCreation = null;


    public function __construct()
    {
        $this->qualificationEngins = new ArrayCollection();
        $this->sitePreferes = new ArrayCollection();
        $this->sessions = new ArrayCollection();
        $this->formations = new ArrayCollection();
        $this->contratFormateurs = new ArrayCollection();
        $this->sessionJours = new ArrayCollection();
        $this->rapportFormateurs = new ArrayCollection();
        $this->positioningAttempts = new ArrayCollection();
        $this->dateCreation = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUtilisateur(): ?Utilisateur
    {
        return $this->utilisateur;
    }

    public function setUtilisateur(Utilisateur $utilisateur): static
    {
        $this->utilisateur = $utilisateur;

        return $this;
    }

    /**
     * @return Collection<int, Engin>
     */
    public function getQualificationEngins(): Collection
    {
        return $this->qualificationEngins;
    }

    public function addQualificationEngins(Engin $qualificationEngins): static
    {
        if (!$this->qualificationEngins->contains($qualificationEngins)) {
            $this->qualificationEngins->add($qualificationEngins);
        }

        return $this;
    }

    public function removeQualificationEngins(Engin $qualificationEngins): static
    {
        $this->qualificationEngins->removeElement($qualificationEngins);

        return $this;
    }

    /**
     * @return Collection<int, Site>
     */
    public function getSitePreferes(): Collection
    {
        return $this->sitePreferes;
    }

    public function addSitePrefere(Site $sitePrefere): static
    {
        if (!$this->sitePreferes->contains($sitePrefere)) {
            $this->sitePreferes->add($sitePrefere);
        }

        return $this;
    }

    public function removeSitePrefere(Site $sitePrefere): static
    {
        $this->sitePreferes->removeElement($sitePrefere);

        return $this;
    }

    public function getCertifications(): ?string
    {
        return $this->certifications;
    }

    public function setCertifications(?string $certifications): static
    {
        $this->certifications = $certifications;

        return $this;
    }

    /**
     * @return Collection<int, Session>
     */
    public function getSessions(): Collection
    {
        return $this->sessions;
    }

    public function addSession(Session $session): static
    {
        if (!$this->sessions->contains($session)) {
            $this->sessions->add($session);
            $session->setFormateur($this);
        }

        return $this;
    }

    public function removeSession(Session $session): static
    {
        if ($this->sessions->removeElement($session)) {
            // set the owning side to null (unless already changed)
            if ($session->getFormateur() === $this) {
                $session->setFormateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Formation>
     */
    public function getFormations(): Collection
    {
        return $this->formations;
    }

    public function addFormation(Formation $formation): static
    {
        if (!$this->formations->contains($formation)) {
            $this->formations->add($formation);
            $formation->setFormateur($this);
        }

        return $this;
    }

    public function removeFormation(Formation $formation): static
    {
        if ($this->formations->removeElement($formation)) {
            // set the owning side to null (unless already changed)
            if ($formation->getFormateur() === $this) {
                $formation->setFormateur(null);
            }
        }

        return $this;
    }

    public function getPhoto(): ?string
    {
        return $this->photo;
    }

    public function setPhoto(?string $photo): static
    {
        $this->photo = $photo;

        return $this;
    }

    /**
     * @return Collection<int, ContratFormateur>
     */
    public function getContratFormateurs(): Collection
    {
        return $this->contratFormateurs;
    }

    public function addContratFormateur(ContratFormateur $contratFormateur): static
    {
        if (!$this->contratFormateurs->contains($contratFormateur)) {
            $this->contratFormateurs->add($contratFormateur);
            $contratFormateur->setFormateur($this);
        }

        return $this;
    }

    public function removeContratFormateur(ContratFormateur $contratFormateur): static
    {
        if ($this->contratFormateurs->removeElement($contratFormateur)) {
            // set the owning side to null (unless already changed)
            if ($contratFormateur->getFormateur() === $this) {
                $contratFormateur->setFormateur(null);
            }
        }

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

    public function isAssujettiTva(): ?bool
    {
        return $this->assujettiTva;
    }

    public function setAssujettiTva(bool $assujettiTva): static
    {
        $this->assujettiTva = $assujettiTva;

        return $this;
    }

    public function getTauxTvaParDefaut(): ?float
    {
        return $this->tauxTvaParDefaut;
    }

    public function setTauxTvaParDefaut(?float $tauxTvaParDefaut): static
    {
        $this->tauxTvaParDefaut = $tauxTvaParDefaut;

        return $this;
    }

    public function getNumeroTvaIntra(): ?string
    {
        return $this->numeroTvaIntra;
    }

    public function setNumeroTvaIntra(?string $numeroTvaIntra): static
    {
        $this->numeroTvaIntra = $numeroTvaIntra;

        return $this;
    }

    public function getTauxHoraireCents(): ?int
    {
        return $this->tauxHoraireCents;
    }

    public function setTauxHoraireCents(?int $tauxHoraireCents): static
    {
        $this->tauxHoraireCents = $tauxHoraireCents;

        return $this;
    }

    public function getTauxJournalierCents(): ?int
    {
        return $this->tauxJournalierCents;
    }

    public function setTauxJournalierCents(?int $tauxJournalierCents): static
    {
        $this->tauxJournalierCents = $tauxJournalierCents;

        return $this;
    }

    public function getModeRemuneration(): ?string
    {
        return $this->modeRemuneration;
    }

    public function setModeRemuneration(?string $modeRemuneration): static
    {
        $this->modeRemuneration = $modeRemuneration;

        return $this;
    }

    /**
     * @return Collection<int, SessionJour>
     */
    public function getSessionJours(): Collection
    {
        return $this->sessionJours;
    }

    public function addSessionJour(SessionJour $sessionJour): static
    {
        if (!$this->sessionJours->contains($sessionJour)) {
            $this->sessionJours->add($sessionJour);
            $sessionJour->setFormateur($this);
        }

        return $this;
    }

    public function removeSessionJour(SessionJour $sessionJour): static
    {
        if ($this->sessionJours->removeElement($sessionJour)) {
            // set the owning side to null (unless already changed)
            if ($sessionJour->getFormateur() === $this) {
                $sessionJour->setFormateur(null);
            }
        }

        return $this;
    }

    public function getSignatureDataUrl(): ?string
    {
        return $this->signatureDataUrl;
    }

    public function setSignatureDataUrl(?string $signatureDataUrl): static
    {
        $this->signatureDataUrl = $signatureDataUrl;

        return $this;
    }

    public function getSignatureImagePath(): ?string
    {
        return $this->signatureImagePath;
    }

    public function setSignatureImagePath(?string $signatureImagePath): static
    {
        $this->signatureImagePath = $signatureImagePath;

        return $this;
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

    public function getVille(): ?string
    {
        return $this->ville;
    }

    public function setVille(?string $ville): static
    {
        $this->ville = $ville;

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

    public function getPays(): ?string
    {
        return $this->pays;
    }

    public function setPays(?string $pays): static
    {
        $this->pays = $pays;

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


    public function getRapportFormateurs(): Collection
    {
        return $this->rapportFormateurs;
    }

    public function addRapportFormateur(RapportFormateur $rapport): static
    {
        if (!$this->rapportFormateurs->contains($rapport)) {
            $this->rapportFormateurs->add($rapport);
            $rapport->setFormateur($this);
        }
        return $this;
    }

    public function removeRapportFormateur(RapportFormateur $rapport): static
    {
        if ($this->rapportFormateurs->removeElement($rapport)) {
            if ($rapport->getFormateur() === $this) {
                $rapport->setFormateur(null);
            }
        }
        return $this;
    }

    /** @return Collection<int, PositioningAttempt> */
    public function getPositioningAttempts(): Collection
    {
        return $this->positioningAttempts;
    }

    public function addPositioningAttempt(PositioningAttempt $attempt): static
    {
        if (!$this->positioningAttempts->contains($attempt)) {
            $this->positioningAttempts->add($attempt);
            $attempt->setAssignedFormateur($this);
        }
        return $this;
    }

    public function removePositioningAttempt(PositioningAttempt $attempt): static
    {
        if ($this->positioningAttempts->removeElement($attempt)) {
            if ($attempt->getAssignedFormateur() === $this) {
                $attempt->setAssignedFormateur(null);
            }
        }
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
}
