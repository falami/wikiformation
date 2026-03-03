<?php

namespace App\Entity;

use App\Repository\SiteRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: SiteRepository::class)]
class Site
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 140)]
    #[Assert\NotBlank]
    private ?string $nom = null;

    #[ORM\Column(length: 140, unique: true)]
    #[Assert\NotBlank]
    private ?string $slug = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $adresse = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $complement = null;

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $codePostal = null;

    #[ORM\Column(length: 140, nullable: true)]
    private ?string $ville = null;

    #[ORM\Column(length: 140, nullable: true)]
    private ?string $departement = null;

    #[ORM\Column(length: 140, nullable: true)]
    private ?string $region = null;

    #[ORM\Column(length: 140, nullable: true, options: ['default' => 'France'])]
    private ?string $pays = null;

    #[ORM\Column(nullable: true)]
    private ?float $latitude = null;

    #[ORM\Column(nullable: true)]
    private ?float $longitude = null;

    #[ORM\Column(length: 64, options: ['default' => 'Europe/Paris'])]
    private ?string $timezone = 'Europe/Paris';

    /**
     * @var Collection<int, Engin>
     */
    #[ORM\OneToMany(targetEntity: Engin::class, mappedBy: 'site', cascade: ['persist'], orphanRemoval: true)]
    private Collection $engins;

    /**
     * @var Collection<int, Formateur>
     */
    #[ORM\ManyToMany(targetEntity: Formateur::class, mappedBy: 'sitePreferes')]
    private Collection $sitePreferesFormateur;

    /**
     * @var Collection<int, Session>
     */
    #[ORM\OneToMany(targetEntity: Session::class, mappedBy: 'site', orphanRemoval: true)]
    private Collection $sessions;

    /**
     * @var Collection<int, Formation>
     */
    #[ORM\OneToMany(targetEntity: Formation::class, mappedBy: 'site')]
    private Collection $formations;

    #[ORM\ManyToOne(inversedBy: 'sites')]
    private Entite $entite;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'siteCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $googlePlaceId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $formattedAddress = null;

    public function __construct()
    {
        $this->engins = new ArrayCollection();
        $this->sitePreferesFormateur = new ArrayCollection();
        $this->sessions = new ArrayCollection();
        $this->formations = new ArrayCollection();
        $this->dateCreation = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;

        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): static
    {
        $this->slug = $slug;

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

    public function getLatitude(): ?float
    {
        return $this->latitude;
    }

    public function setLatitude(?float $latitude): static
    {
        $this->latitude = $latitude;

        return $this;
    }

    public function getLongitude(): ?float
    {
        return $this->longitude;
    }

    public function setLongitude(?float $longitude): static
    {
        $this->longitude = $longitude;

        return $this;
    }

    public function getTimezone(): ?string
    {
        return $this->timezone;
    }

    public function setTimezone(?string $timezone): static
    {
        $this->timezone = $timezone;

        return $this;
    }

    /**
     * @return Collection<int, Engin>
     */
    public function getEngins(): Collection
    {
        return $this->engins;
    }

    public function addEngins(Engin $engins): static
    {
        if (!$this->engins->contains($engins)) {
            $this->engins->add($engins);
            $engins->setSite($this);
        }

        return $this;
    }

    public function removeEngins(Engin $engins): static
    {
        if ($this->engins->removeElement($engins)) {
            // set the owning side to null (unless already changed)
            if ($engins->getSite() === $this) {
                $engins->setSite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Formateur>
     */
    public function getSitePreferesFormateur(): Collection
    {
        return $this->sitePreferesFormateur;
    }

    public function addSitePreferesFormateur(Formateur $sitePreferesFormateur): static
    {
        if (!$this->sitePreferesFormateur->contains($sitePreferesFormateur)) {
            $this->sitePreferesFormateur->add($sitePreferesFormateur);
            $sitePreferesFormateur->addSitePrefere($this);
        }

        return $this;
    }

    public function removeSitePreferesFormateur(Formateur $sitePreferesFormateur): static
    {
        if ($this->sitePreferesFormateur->removeElement($sitePreferesFormateur)) {
            $sitePreferesFormateur->removeSitePrefere($this);
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
            $session->setSite($this);
        }

        return $this;
    }

    public function removeSession(Session $session): static
    {
        if ($this->sessions->removeElement($session)) {
            // set the owning side to null (unless already changed)
            if ($session->getSite() === $this) {
                $session->setSite(null);
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
            $formation->setSite($this);
        }

        return $this;
    }

    public function removeFormation(Formation $formation): static
    {
        if ($this->formations->removeElement($formation)) {
            // set the owning side to null (unless already changed)
            if ($formation->getSite() === $this) {
                $formation->setSite(null);
            }
        }

        return $this;
    }

    public function getEntite(): Entite
    {
        return $this->entite;
    }

    public function setEntite(Entite $entite): static
    {
        $this->entite = $entite;

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

    public function getGooglePlaceId(): ?string
    {
        return $this->googlePlaceId;
    }

    public function setGooglePlaceId(?string $googlePlaceId): static
    {
        $this->googlePlaceId = $googlePlaceId;

        return $this;
    }

    public function getFormattedAddress(): ?string
    {
        return $this->formattedAddress;
    }

    public function setFormattedAddress(?string $formattedAddress): static
    {
        $this->formattedAddress = $formattedAddress;

        return $this;
    }
}
