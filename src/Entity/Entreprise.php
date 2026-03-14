<?php

namespace App\Entity;

use App\Repository\EntrepriseRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EntrepriseRepository::class)]
#[ORM\Table(name: 'entreprise')]
#[ORM\UniqueConstraint(name: 'uniq_entite_raison', columns: ['entite_id', 'raison_sociale'])]
class Entreprise
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'raison_sociale', length: 255)]
    private ?string $raisonSociale = null;

    #[ORM\Column(length: 14, nullable: true)]
    private ?string $siret = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $emailFacturation = null;

    #[ORM\ManyToOne(targetEntity: Entite::class, inversedBy: 'entreprises')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Entite $entite = null;

    /**
     * @var Collection<int, Inscription>
     */
    #[ORM\OneToMany(mappedBy: 'entreprise', targetEntity: Inscription::class)]
    private Collection $inscriptions;

    /**
     * @var Collection<int, ConventionContrat>
     */
    #[ORM\OneToMany(mappedBy: 'entreprise', targetEntity: ConventionContrat::class)]
    private Collection $conventionContrats;

    /**
     * @var Collection<int, Facture>
     */
    #[ORM\OneToMany(targetEntity: Facture::class, mappedBy: 'entrepriseDestinataire')]
    private Collection $factures;

    /**
     * @var Collection<int, Utilisateur>
     */
    #[ORM\OneToMany(targetEntity: Utilisateur::class, mappedBy: 'entreprise')]
    private Collection $utilisateurs;

    /**
     * @var Collection<int, Prospect>
     */
    #[ORM\OneToMany(targetEntity: Prospect::class, mappedBy: 'linkedEntreprise')]
    private Collection $prospects;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $adresse = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $complement = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $codePostal = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $ville = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $departement = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $region = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $pays = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $numeroTVA = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'entrepriseCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    /**
     * @var Collection<int, Paiement>
     */
    #[ORM\OneToMany(targetEntity: Paiement::class, mappedBy: 'payeurEntreprise')]
    private Collection $paiements;

    /**
     * @var Collection<int, EntrepriseDocument>
     */
    #[ORM\OneToMany(targetEntity: EntrepriseDocument::class, mappedBy: 'entreprise')]
    private Collection $entrepriseDocuments;

    /**
     * @var Collection<int, Session>
     */
    #[ORM\OneToMany(targetEntity: Session::class, mappedBy: 'organismeFormation')]
    private Collection $sessions;

    #[ORM\ManyToOne(inversedBy: 'entreprises')]
    private ?Utilisateur $representant = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logo = null;

    public function __construct()
    {
        $this->inscriptions = new ArrayCollection();
        $this->conventionContrats = new ArrayCollection();
        $this->factures = new ArrayCollection();
        $this->utilisateurs = new ArrayCollection();
        $this->prospects = new ArrayCollection();
        $this->dateCreation = new \DateTimeImmutable();
        $this->paiements = new ArrayCollection();
        $this->entrepriseDocuments = new ArrayCollection();
        $this->sessions = new ArrayCollection();
    }

    public function __toString(): string
    {
        return (string) ($this->raisonSociale ?: 'Entreprise');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRaisonSociale(): ?string
    {
        return $this->raisonSociale;
    }

    public function setRaisonSociale(?string $raisonSociale): static
    {
        $this->raisonSociale = $raisonSociale ? trim($raisonSociale) : null;
        return $this;
    }

    public function getSiret(): ?string
    {
        return $this->siret;
    }

    public function setSiret(?string $siret): static
    {
        $siret = $siret ? preg_replace('/\s+/', '', $siret) : null;
        $this->siret = $siret ?: null;
        return $this;
    }

    public function getEmailFacturation(): ?string
    {
        return $this->emailFacturation;
    }

    public function setEmailFacturation(?string $emailFacturation): static
    {
        $this->emailFacturation = $emailFacturation ? trim($emailFacturation) : null;
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
            $inscription->setEntreprise($this);
        }

        return $this;
    }

    public function removeInscription(Inscription $inscription): static
    {
        if ($this->inscriptions->removeElement($inscription)) {
            if ($inscription->getEntreprise() === $this) {
                $inscription->setEntreprise(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ConventionContrat>
     */
    public function getConventionContrats(): Collection
    {
        return $this->conventionContrats;
    }

    public function addConventionContrat(ConventionContrat $conventionContrat): static
    {
        if (!$this->conventionContrats->contains($conventionContrat)) {
            $this->conventionContrats->add($conventionContrat);
            $conventionContrat->setEntreprise($this);
        }

        return $this;
    }

    public function removeConventionContrat(ConventionContrat $conventionContrat): static
    {
        if ($this->conventionContrats->removeElement($conventionContrat)) {
            if ($conventionContrat->getEntreprise() === $this) {
                $conventionContrat->setEntreprise(null);
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
            $facture->setEntrepriseDestinataire($this);
        }

        return $this;
    }

    public function removeFacture(Facture $facture): static
    {
        if ($this->factures->removeElement($facture)) {
            // set the owning side to null (unless already changed)
            if ($facture->getEntrepriseDestinataire() === $this) {
                $facture->setEntrepriseDestinataire(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Utilisateur>
     */
    public function getUtilisateurs(): Collection
    {
        return $this->utilisateurs;
    }

    public function addUtilisateur(Utilisateur $utilisateur): static
    {
        if (!$this->utilisateurs->contains($utilisateur)) {
            $this->utilisateurs->add($utilisateur);
            $utilisateur->setEntreprise($this);
        }

        return $this;
    }

    public function removeUtilisateur(Utilisateur $utilisateur): static
    {
        if ($this->utilisateurs->removeElement($utilisateur)) {
            // set the owning side to null (unless already changed)
            if ($utilisateur->getEntreprise() === $this) {
                $utilisateur->setEntreprise(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Prospect>
     */
    public function getProspects(): Collection
    {
        return $this->prospects;
    }

    public function addProspect(Prospect $prospect): static
    {
        if (!$this->prospects->contains($prospect)) {
            $this->prospects->add($prospect);
            $prospect->setLinkedEntreprise($this);
        }

        return $this;
    }

    public function removeProspect(Prospect $prospect): static
    {
        if ($this->prospects->removeElement($prospect)) {
            // set the owning side to null (unless already changed)
            if ($prospect->getLinkedEntreprise() === $this) {
                $prospect->setLinkedEntreprise(null);
            }
        }

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

    public function getNumeroTVA(): ?string
    {
        return $this->numeroTVA;
    }

    public function setNumeroTVA(?string $numeroTVA): static
    {
        $this->numeroTVA = $numeroTVA;

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
            $paiement->setPayeurEntreprise($this);
        }

        return $this;
    }

    public function removePaiement(Paiement $paiement): static
    {
        if ($this->paiements->removeElement($paiement)) {
            // set the owning side to null (unless already changed)
            if ($paiement->getPayeurEntreprise() === $this) {
                $paiement->setPayeurEntreprise(null);
            }
        }

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
            $entrepriseDocument->setEntreprise($this);
        }

        return $this;
    }

    public function removeEntrepriseDocument(EntrepriseDocument $entrepriseDocument): static
    {
        if ($this->entrepriseDocuments->removeElement($entrepriseDocument)) {
            // set the owning side to null (unless already changed)
            if ($entrepriseDocument->getEntreprise() === $this) {
                $entrepriseDocument->setEntreprise(null);
            }
        }

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
            $session->setOrganismeFormation($this);
        }

        return $this;
    }

    public function removeSession(Session $session): static
    {
        if ($this->sessions->removeElement($session)) {
            // set the owning side to null (unless already changed)
            if ($session->getOrganismeFormation() === $this) {
                $session->setOrganismeFormation(null);
            }
        }

        return $this;
    }

    public function getRepresentant(): ?Utilisateur
    {
        return $this->representant;
    }

    public function setRepresentant(?Utilisateur $representant): static
    {
        $this->representant = $representant;

        return $this;
    }

    public function getLogo(): ?string
    {
        return $this->logo;
    }

    public function setLogo(?string $logo): static
    {
        $this->logo = $logo;

        return $this;
    }
}
