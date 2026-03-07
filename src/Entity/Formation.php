<?php

namespace App\Entity;

use App\Repository\FormationRepository;
use App\Enum\NiveauFormation;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use App\Entity\PublicHost;

#[ORM\Entity(repositoryClass: FormationRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_formation_entite_slug', columns: ['entite_id', 'slug'])]
class Formation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 160)]
    #[Assert\NotBlank]
    private ?string $titre = null;

    #[ORM\Column(length: 160)]
    #[Assert\NotBlank]
    private ?string $slug = null;

    #[ORM\Column(enumType: NiveauFormation::class)]
    private NiveauFormation $niveau = NiveauFormation::INITIAL;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Positive]
    private ?int $duree = null;

    #[ORM\Column(nullable: true, options: ['unsigned' => true])]
    #[Assert\Positive]
    private int $prixBaseCents = 99000;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $codeQualiopi = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $conditionPrealable = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $pedagogie = null;

    /**
     * @var Collection<int, Session>
     */
    #[ORM\OneToMany(targetEntity: Session::class, mappedBy: 'formation', orphanRemoval: true)]
    private Collection $sessions;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photoCouverture = null;

    /** @var Collection<int, FormationPhoto> */
    #[ORM\OneToMany(
        mappedBy: 'formation',
        targetEntity: FormationPhoto::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $photos;

    #[ORM\ManyToOne(inversedBy: 'formations')]
    private ?Formateur $formateur = null;

    #[ORM\ManyToOne(inversedBy: 'formations')]
    private ?Engin $engin = null;

    #[ORM\ManyToOne(inversedBy: 'formations')]
    private ?Site $site = null;

    #[ORM\Column(nullable: true, options: ['unsigned' => true])]
    #[Assert\Positive]
    private ?int $prixReduitCents = null;

    #[ORM\Column(length: 140, nullable: true)]
    private ?string $adresse = null;

    #[ORM\Column(length: 140, nullable: true)]
    private ?string $complement = null;

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $codePostal = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $ville = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $departement = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $region = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $pays = null;


    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photoBanniere = null;

    // en bas de la liste des propriétés :
    /** @var Collection<int, FormationContentNode> */
    #[ORM\OneToMany(mappedBy: 'formation', targetEntity: FormationContentNode::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $contentNodes;

    /**
     * @var Collection<int, SupportDocument>
     */
    #[ORM\OneToMany(targetEntity: SupportDocument::class, mappedBy: 'formation')]
    private Collection $supportDocuments;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $objectifs = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $public = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $modalitesPratiques = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $modalitesEvaluation = null;

    /**
     * @var Collection<int, Reservation>
     */
    #[ORM\OneToMany(targetEntity: Reservation::class, mappedBy: 'formation')]
    private Collection $reservations;

    #[ORM\ManyToOne(inversedBy: 'formations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Entite $entite = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isPublic = false;

    #[ORM\ManyToOne(inversedBy: 'formations')]
    private ?SatisfactionTemplate $satisfactionTemplate = null;

    /**
     * @var Collection<int, FormationObjective>
     */
    #[ORM\OneToMany(targetEntity: FormationObjective::class, mappedBy: 'formation')]
    private Collection $objectives;

    #[ORM\ManyToOne(inversedBy: 'formations')]
    private ?FormateurSatisfactionTemplate $formateurSatisfactionTemplate = null;

    // Formation.php

    #[ORM\Column(options: ['default' => true])]
    private bool $financementIndividuel = true;

    #[ORM\Column(options: ['default' => false])]
    private bool $financementCpf = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $financementEntreprise = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $financementOpco = false;


    #[ORM\ManyToOne(inversedBy: 'formations')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Categorie $categorie = null;

    /** @var Collection<int, Devis> */
    #[ORM\OneToMany(mappedBy: 'formation', targetEntity: Devis::class)]
    private Collection $devis;

    /**
     * @var Collection<int, Facture>
     */
    #[ORM\OneToMany(targetEntity: Facture::class, mappedBy: 'formation')]
    private Collection $factures;

    #[ORM\ManyToOne(inversedBy: 'formationCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $note = null;

    #[ORM\ManyToMany(targetEntity: PublicHost::class, inversedBy: 'formations')]
    #[ORM\JoinTable(name: 'formation_public_host')]
    private Collection $publicHosts;

    #[ORM\Column(options: ['default' => false])]
    private bool $excludeFromGlobalCatalogue = false;


    public function __construct()
    {
        $this->sessions = new ArrayCollection();
        $this->photos   = new ArrayCollection();
        $this->contentNodes = new ArrayCollection();
        $this->supportDocuments = new ArrayCollection();
        $this->reservations = new ArrayCollection();
        $this->objectives = new ArrayCollection();
        $this->devis = new ArrayCollection();
        $this->factures = new ArrayCollection();
        $this->dateCreation = new \DateTimeImmutable();
        $this->publicHosts = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitre(): ?string
    {
        return $this->titre;
    }

    public function setTitre(string $titre): static
    {
        $this->titre = $titre;

        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getDuree(): ?int
    {
        return $this->duree;
    }

    public function setDuree(?int $duree): static
    {
        $this->duree = $duree;

        return $this;
    }

    public function getPrixBaseCents(): int
    {
        return $this->prixBaseCents;
    }
    public function setPrixBaseCents(int $prixBaseCents): static
    {
        $this->prixBaseCents = $prixBaseCents;
        return $this;
    }

    public function getPrixBaseEuros(): float
    {
        return $this->prixBaseCents / 100;
    }


    public function getPrixReduitEuros(): ?float
    {
        return $this->prixReduitCents !== null ? $this->prixReduitCents / 100 : null;
    }


    public function getCodeQualiopi(): ?string
    {
        return $this->codeQualiopi;
    }

    public function setCodeQualiopi(?string $codeQualiopi): static
    {
        $this->codeQualiopi = $codeQualiopi;

        return $this;
    }

    public function getConditionPrealable(): ?string
    {
        return $this->conditionPrealable;
    }

    public function setConditionPrealable(?string $conditionPrealable): static
    {
        $this->conditionPrealable = $conditionPrealable;

        return $this;
    }

    public function getPedagogie(): ?string
    {
        return $this->pedagogie;
    }

    public function setPedagogie(?string $pedagogie): static
    {
        $this->pedagogie = $pedagogie;

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
            $session->setFormation($this);
        }

        return $this;
    }

    public function removeSession(Session $session): static
    {
        if ($this->sessions->removeElement($session)) {
            // set the owning side to null (unless already changed)
            if ($session->getFormation() === $this) {
                $session->setFormation(null);
            }
        }

        return $this;
    }

    public function getNiveau(): NiveauFormation
    {
        return $this->niveau;
    }

    public function setNiveau(NiveauFormation $niveau): static
    {
        $this->niveau = $niveau;
        return $this;
    }

    public function getPhotoCouverture(): ?string
    {
        return $this->photoCouverture;
    }

    public function setPhotoCouverture(?string $photoCouverture): static
    {
        $this->photoCouverture = $photoCouverture;

        return $this;
    }

    /** @return Collection<int, FormationPhoto> */
    public function getPhotos(): Collection
    {
        return $this->photos;
    }

    public function addPhoto(FormationPhoto $p): static
    {
        if (!$this->photos->contains($p)) {
            $this->photos->add($p);
            $p->setFormation($this);
        }
        return $this;
    }
    public function removePhoto(FormationPhoto $p): static
    {
        if ($this->photos->removeElement($p) && $p->getFormation() === $this) {
            $p->setFormation(null);
        }
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

    public function getEngin(): ?Engin
    {
        return $this->engin;
    }

    public function setEngin(?Engin $engin): static
    {
        $this->engin = $engin;

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

    public function getPrixReduitCents(): ?int
    {
        return $this->prixReduitCents;
    }

    public function setPrixReduitCents(?int $prixReduitCents): static
    {
        $this->prixReduitCents = $prixReduitCents;

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

    public function getCodePostal(): ?string
    {
        return $this->codePostal;
    }

    public function setCodePostal(?string $codePostal): static
    {
        $this->codePostal = $codePostal;

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

    public function getDepartement(): ?string
    {
        return $this->departement;
    }

    public function setDepartement(?string $departement): static
    {
        $this->departement = $departement;

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

    public function getPays(): ?string
    {
        return $this->pays;
    }

    public function setPays(?string $pays): static
    {
        $this->pays = $pays;

        return $this;
    }

    public function getPhotoBanniere(): ?string
    {
        return $this->photoBanniere;
    }

    public function setPhotoBanniere(?string $photoBanniere): static
    {
        $this->photoBanniere = $photoBanniere;

        return $this;
    }

    /** @return Collection<int, FormationContentNode> */
    public function getContentNodes(): Collection
    {
        return $this->contentNodes;
    }
    public function addContentNode(FormationContentNode $n): static
    {
        if (!$this->contentNodes->contains($n)) {
            $this->contentNodes->add($n);
            $n->setFormation($this);
        }
        return $this;
    }
    public function removeContentNode(FormationContentNode $n): static
    {
        if ($this->contentNodes->removeElement($n) && $n->getFormation() === $this) {
            $n->setFormation(null);
        }
        return $this;
    }

    /**
     * @return Collection<int, SupportDocument>
     */
    public function getSupportDocuments(): Collection
    {
        return $this->supportDocuments;
    }

    public function addSupportDocument(SupportDocument $supportDocument): static
    {
        if (!$this->supportDocuments->contains($supportDocument)) {
            $this->supportDocuments->add($supportDocument);
            $supportDocument->setFormation($this);
        }

        return $this;
    }

    public function removeSupportDocument(SupportDocument $supportDocument): static
    {
        if ($this->supportDocuments->removeElement($supportDocument)) {
            // set the owning side to null (unless already changed)
            if ($supportDocument->getFormation() === $this) {
                $supportDocument->setFormation(null);
            }
        }

        return $this;
    }

    public function getObjectifs(): ?string
    {
        return $this->objectifs;
    }

    public function setObjectifs(?string $objectifs): static
    {
        $this->objectifs = $objectifs;

        return $this;
    }

    public function getPublic(): ?string
    {
        return $this->public;
    }

    public function setPublic(?string $public): static
    {
        $this->public = $public;

        return $this;
    }

    public function getModalitesPratiques(): ?string
    {
        return $this->modalitesPratiques;
    }

    public function setModalitesPratiques(?string $modalitesPratiques): static
    {
        $this->modalitesPratiques = $modalitesPratiques;

        return $this;
    }

    public function getModalitesEvaluation(): ?string
    {
        return $this->modalitesEvaluation;
    }

    public function setModalitesEvaluation(?string $modalitesEvaluation): static
    {
        $this->modalitesEvaluation = $modalitesEvaluation;

        return $this;
    }

    /**
     * @return Collection<int, Reservation>
     */
    public function getReservations(): Collection
    {
        return $this->reservations;
    }

    public function addReservation(Reservation $reservation): static
    {
        if (!$this->reservations->contains($reservation)) {
            $this->reservations->add($reservation);
            $reservation->setFormation($this);
        }

        return $this;
    }

    public function removeReservation(Reservation $reservation): static
    {
        if ($this->reservations->removeElement($reservation)) {
            // set the owning side to null (unless already changed)
            if ($reservation->getFormation() === $this) {
                $reservation->setFormation(null);
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


    public function isPublic(): bool
    {
        return $this->isPublic;
    }

    public function setIsPublic(bool $isPublic): static
    {
        $this->isPublic = $isPublic;
        return $this;
    }

    public function getSatisfactionTemplate(): ?SatisfactionTemplate
    {
        return $this->satisfactionTemplate;
    }

    public function setSatisfactionTemplate(?SatisfactionTemplate $satisfactionTemplate): static
    {
        $this->satisfactionTemplate = $satisfactionTemplate;

        return $this;
    }

    public function __toString(): string
    {
        return (string) ($this->titre . ' ' . $this->niveau?->value . ' ' . $this->duree . 'j');
    }

    /**
     * @return Collection<int, FormationObjective>
     */
    public function getObjectives(): Collection
    {
        return $this->objectives;
    }

    public function addObjective(FormationObjective $objective): static
    {
        if (!$this->objectives->contains($objective)) {
            $this->objectives->add($objective);
            $objective->setFormation($this);
        }

        return $this;
    }

    public function removeObjective(FormationObjective $objective): static
    {
        if ($this->objectives->removeElement($objective)) {
            // set the owning side to null (unless already changed)
            if ($objective->getFormation() === $this) {
                $objective->setFormation(null);
            }
        }

        return $this;
    }

    public function getFormateurSatisfactionTemplate(): ?FormateurSatisfactionTemplate
    {
        return $this->formateurSatisfactionTemplate;
    }

    public function setFormateurSatisfactionTemplate(?FormateurSatisfactionTemplate $formateurSatisfactionTemplate): static
    {
        $this->formateurSatisfactionTemplate = $formateurSatisfactionTemplate;

        return $this;
    }


    // getters/setters
    public function isFinancementIndividuel(): bool
    {
        return $this->financementIndividuel;
    }
    public function setFinancementIndividuel(bool $v): static
    {
        $this->financementIndividuel = $v;
        return $this;
    }

    public function isFinancementCpf(): bool
    {
        return $this->financementCpf;
    }
    public function setFinancementCpf(bool $v): static
    {
        $this->financementCpf = $v;
        return $this;
    }

    public function isFinancementEntreprise(): bool
    {
        return $this->financementEntreprise;
    }
    public function setFinancementEntreprise(bool $v): static
    {
        $this->financementEntreprise = $v;
        return $this;
    }

    public function isFinancementOpco(): bool
    {
        return $this->financementOpco;
    }
    public function setFinancementOpco(bool $v): static
    {
        $this->financementOpco = $v;
        return $this;
    }

    #[Assert\Callback]
    public function validateFinancements(ExecutionContextInterface $context): void
    {
        if (
            !$this->financementIndividuel &&
            !$this->financementCpf &&
            !$this->financementEntreprise &&
            !$this->financementOpco
        ) {
            $context->buildViolation('Sélectionne au moins un mode de financement.')
                ->atPath('financementIndividuel')
                ->addViolation();
        }
    }


    public function getCategorie(): ?Categorie
    {
        return $this->categorie;
    }
    public function setCategorie(?Categorie $categorie): static
    {
        $this->categorie = $categorie;
        return $this;
    }

    /** @return Collection<int, Devis> */
    public function getDevis(): Collection
    {
        return $this->devis;
    }

    public function addDevi(Devis $devis): static
    {
        if (!$this->devis->contains($devis)) {
            $this->devis->add($devis);
            $devis->setFormation($this);
        }
        return $this;
    }

    public function removeDevi(Devis $devis): static
    {
        if ($this->devis->removeElement($devis)) {
            if ($devis->getFormation() === $this) {
                $devis->setFormation(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, Facture>
     */
    public function getFactures(): Collection
    {
        return $this->factures;
    }

    public function addFacture(Facture $facture): static
    {
        if (!$this->factures->contains($facture)) {
            $this->factures->add($facture);
            $facture->setFormation($this);
        }

        return $this;
    }

    public function removeFacture(Facture $facture): static
    {
        if ($this->factures->removeElement($facture)) {
            // set the owning side to null (unless already changed)
            if ($facture->getFormation() === $this) {
                $facture->setFormation(null);
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

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }


    /**
     * @return Collection<int, PublicHost>
     */
    public function getPublicHosts(): Collection
    {
        return $this->publicHosts;
    }

    public function addPublicHost(PublicHost $publicHost): static
    {
        if (!$this->publicHosts->contains($publicHost)) {
            $this->publicHosts->add($publicHost);
            $publicHost->addFormation($this);
        }

        return $this;
    }

    public function removePublicHost(PublicHost $publicHost): static
    {
        if ($this->publicHosts->removeElement($publicHost)) {
            $publicHost->removeFormation($this);
        }

        return $this;
    }

    public function isExcludeFromGlobalCatalogue(): bool
    {
        return $this->excludeFromGlobalCatalogue;
    }

    public function setExcludeFromGlobalCatalogue(bool $excludeFromGlobalCatalogue): static
    {
        $this->excludeFromGlobalCatalogue = $excludeFromGlobalCatalogue;

        return $this;
    }
}
