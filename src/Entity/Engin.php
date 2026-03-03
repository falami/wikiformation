<?php

namespace App\Entity;

use App\Repository\EnginRepository;
use App\Enum\EnginType;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: EnginRepository::class)]
class Engin
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'engins')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Site $site = null;

    #[ORM\Column(length: 140)]
    #[Assert\NotBlank]
    private ?string $nom = null;

    #[ORM\Column(enumType: EnginType::class)]
    private EnginType $type = EnginType::CHARGEUR;

    #[ORM\Column(nullable: true, options: ['unsigned' => true])]
    private ?int $capaciteCouchage = 8;

    #[ORM\Column(nullable: true, options: ['unsigned' => true])]
    private ?int $capacite = 12;

    #[ORM\Column(nullable: true)]
    private ?int $cabine = null;

    #[ORM\Column(nullable: true)]
    private ?int $douche = null;

    #[ORM\Column(nullable: true)]
    private ?int $chambre = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $caracteristique = null;


    #[ORM\Column(nullable: true)]
    private ?int $annee = 2025;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $longueurHt = 17.65;

    #[ORM\Column(nullable: true)]
    private ?int $personnes = 10;

    #[ORM\Column(nullable: true)]
    private ?int $cabinesDoubles = 5;

    #[ORM\Column(nullable: true)]
    private ?int $couchagesCarres = 0;

    #[ORM\Column(nullable: true)]
    private ?int $couchagesRecommandes = 12;

    #[ORM\Column(nullable: true)]
    private ?bool $dessalinisateur = true;

    #[ORM\Column(nullable: true)]
    private ?bool $panneauxSolaires = true;

    #[ORM\Column(nullable: true)]
    private ?bool $refrigerateur = true;

    #[ORM\Column(nullable: true)]
    private ?bool $prise = true;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $largeurMax = 9.06;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $reservoirFuel = '1200 L';

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $tirantEau = '1.47 m';

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $reservoirEau = '1260 L';

    #[ORM\Column(nullable: true)]
    private ?bool $propulseurEtrave = true;

    #[ORM\Column(nullable: true)]
    private ?bool $gps = true;

    /**
     * @var Collection<int, Formateur>
     */
    #[ORM\ManyToMany(targetEntity: Formateur::class, mappedBy: 'qualificationEngins')]
    private Collection $formateurQualifications;

    /**
     * @var Collection<int, Session>
     */
    #[ORM\OneToMany(targetEntity: Session::class, mappedBy: 'engin')]
    private Collection $sessions;

    /**
     * @var Collection<int, Formation>
     */
    #[ORM\OneToMany(targetEntity: Formation::class, mappedBy: 'engin')]
    private Collection $formations;



    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photoCouverture = null;


    /** @var Collection<int, EnginPhoto> */
    #[ORM\OneToMany(mappedBy: 'engin', targetEntity: EnginPhoto::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $photos;


    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photoBanniere = null;

    #[ORM\ManyToOne(inversedBy: 'engins')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Entite $entite = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'enginCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    public function __construct()
    {
        $this->formateurQualifications = new ArrayCollection();
        $this->sessions = new ArrayCollection();
        $this->formations = new ArrayCollection();
        $this->photos   = new ArrayCollection();
        $this->dateCreation = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;

        return $this;
    }

    public function getCapaciteCouchage(): ?int
    {
        return $this->capaciteCouchage;
    }

    public function setCapaciteCouchage(?int $capaciteCouchage): static
    {
        $this->capaciteCouchage = $capaciteCouchage;

        return $this;
    }

    public function getCapacite(): ?int
    {
        return $this->capacite;
    }

    public function setCapacite(?int $capacite): static
    {
        $this->capacite = $capacite;

        return $this;
    }

    public function getCabine(): ?int
    {
        return $this->cabine;
    }

    public function setCabine(?int $cabine): static
    {
        $this->cabine = $cabine;

        return $this;
    }

    public function getDouche(): ?int
    {
        return $this->douche;
    }

    public function setDouche(?int $douche): static
    {
        $this->douche = $douche;

        return $this;
    }

    public function getChambre(): ?int
    {
        return $this->chambre;
    }

    public function setChambre(?int $chambre): static
    {
        $this->chambre = $chambre;

        return $this;
    }

    public function getCaracteristique(): ?string
    {
        return $this->caracteristique;
    }

    public function setCaracteristique(?string $caracteristique): static
    {
        $this->caracteristique = $caracteristique;

        return $this;
    }

    /**
     * @return Collection<int, Formateur>
     */
    public function getFormateurQualifications(): Collection
    {
        return $this->formateurQualifications;
    }

    public function addFormateurQualification(Formateur $formateurQualification): static
    {
        if (!$this->formateurQualifications->contains($formateurQualification)) {
            $this->formateurQualifications->add($formateurQualification);
            $formateurQualification->addQualificationEngins($this);
        }

        return $this;
    }

    public function removeFormateurQualification(Formateur $formateurQualification): static
    {
        if ($this->formateurQualifications->removeElement($formateurQualification)) {
            $formateurQualification->removeQualificationEngins($this);
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
            $session->setEngin($this);
        }

        return $this;
    }

    public function removeSession(Session $session): static
    {
        if ($this->sessions->removeElement($session)) {
            // set the owning side to null (unless already changed)
            if ($session->getEngin() === $this) {
                $session->setEngin(null);
            }
        }

        return $this;
    }

    public function getType(): EnginType
    {
        return $this->type;
    }

    public function setType(EnginType $type): static
    {
        $this->type = $type;
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
            $formation->setEngin($this);
        }

        return $this;
    }

    public function removeFormation(Formation $formation): static
    {
        if ($this->formations->removeElement($formation)) {
            // set the owning side to null (unless already changed)
            if ($formation->getEngin() === $this) {
                $formation->setEngin(null);
            }
        }

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

    /** @return Collection<int, EnginPhoto> */
    public function getPhotos(): Collection
    {
        return $this->photos;
    }

    public function addPhoto(EnginPhoto $p): static
    {
        if (!$this->photos->contains($p)) {
            $this->photos->add($p);
            $p->setEngin($this);
        }
        return $this;
    }
    public function removePhoto(EnginPhoto $p): static
    {
        if ($this->photos->removeElement($p) && $p->getEngin() === $this) {
            $p->setEngin(null);
        }
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

    public function getAnnee(): ?int
    {
        return $this->annee;
    }
    public function setAnnee(?int $annee): static
    {
        $this->annee = $annee;
        return $this;
    }

    public function getLongueurHt(): ?float
    {
        return $this->longueurHt;
    }
    public function setLongueurHt(?float $longueurHt): static
    {
        $this->longueurHt = $longueurHt;
        return $this;
    }

    public function getPersonnes(): ?int
    {
        return $this->personnes;
    }
    public function setPersonnes(?int $personnes): static
    {
        $this->personnes = $personnes;
        return $this;
    }

    public function getCabinesDoubles(): ?int
    {
        return $this->cabinesDoubles;
    }
    public function setCabinesDoubles(?int $cabinesDoubles): static
    {
        $this->cabinesDoubles = $cabinesDoubles;
        return $this;
    }

    public function getCouchagesCarres(): ?int
    {
        return $this->couchagesCarres;
    }
    public function setCouchagesCarres(?int $couchagesCarres): static
    {
        $this->couchagesCarres = $couchagesCarres;
        return $this;
    }

    public function getCouchagesRecommandes(): ?int
    {
        return $this->couchagesRecommandes;
    }
    public function setCouchagesRecommandes(?int $couchagesRecommandes): static
    {
        $this->couchagesRecommandes = $couchagesRecommandes;
        return $this;
    }

    public function isDessalinisateur(): ?bool
    {
        return $this->dessalinisateur;
    }
    public function setDessalinisateur(?bool $dessalinisateur): static
    {
        $this->dessalinisateur = $dessalinisateur;
        return $this;
    }

    public function isPanneauxSolaires(): ?bool
    {
        return $this->panneauxSolaires;
    }
    public function setPanneauxSolaires(?bool $panneauxSolaires): static
    {
        $this->panneauxSolaires = $panneauxSolaires;
        return $this;
    }

    public function isRefrigerateur(): ?bool
    {
        return $this->refrigerateur;
    }
    public function setRefrigerateur(?bool $refrigerateur): static
    {
        $this->refrigerateur = $refrigerateur;
        return $this;
    }

    public function isPrise(): ?bool
    {
        return $this->prise;
    }
    public function setPrise(?bool $prise): static
    {
        $this->prise = $prise;
        return $this;
    }

    public function getLargeurMax(): ?float
    {
        return $this->largeurMax;
    }
    public function setLargeurMax(?float $largeurMax): static
    {
        $this->largeurMax = $largeurMax;
        return $this;
    }

    public function getReservoirFuel(): ?string
    {
        return $this->reservoirFuel;
    }
    public function setReservoirFuel(?string $reservoirFuel): static
    {
        $this->reservoirFuel = $reservoirFuel;
        return $this;
    }

    public function getTirantEau(): ?string
    {
        return $this->tirantEau;
    }
    public function setTirantEau(?string $tirantEau): static
    {
        $this->tirantEau = $tirantEau;
        return $this;
    }

    public function getReservoirEau(): ?string
    {
        return $this->reservoirEau;
    }
    public function setReservoirEau(?string $reservoirEau): static
    {
        $this->reservoirEau = $reservoirEau;
        return $this;
    }

    public function isPropulseurEtrave(): ?bool
    {
        return $this->propulseurEtrave;
    }
    public function setPropulseurEtrave(?bool $propulseurEtrave): static
    {
        $this->propulseurEtrave = $propulseurEtrave;
        return $this;
    }

    public function isGps(): ?bool
    {
        return $this->gps;
    }
    public function setGps(?bool $gps): static
    {
        $this->gps = $gps;
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
