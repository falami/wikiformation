<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** Modèle mis à disposition des formateurs d'un seul organisme. */
#[ORM\Entity]
class DocumentFormateur
{
    public const CATEGORIES = [
        'Émargement' => 'emargement',
        'Appréciation formateur' => 'formateur',
        'Appréciation stagiaire' => 'stagiaire',
        'Autre document' => 'autre',
    ];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Entite $entite = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank, Assert\Length(max: 180)]
    private string $titre = '';

    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 3000)]
    private ?string $description = null;

    #[ORM\Column(length: 30)]
    #[Assert\Choice(choices: ['emargement', 'formateur', 'stagiaire', 'autre'])]
    private string $categorie = 'autre';

    #[ORM\Column]
    private bool $publie = true;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Version, ORM\Column(type: 'integer')]
    private int $lockVersion = 1;

    /** @var Collection<int, DocumentFormateurVersion> */
    #[ORM\OneToMany(mappedBy: 'document', targetEntity: DocumentFormateurVersion::class, cascade: ['persist'])]
    #[ORM\OrderBy(['numero' => 'DESC'])]
    private Collection $versions;

    public function __construct()
    {
        $this->versions = new ArrayCollection();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getEntite(): ?Entite { return $this->entite; }
    public function setEntite(Entite $entite): static { $this->entite = $entite; return $this; }
    public function getTitre(): string { return $this->titre; }
    public function setTitre(string $titre): static { $this->titre = trim($titre); return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }
    public function getCategorie(): string { return $this->categorie; }
    public function setCategorie(string $categorie): static { $this->categorie = $categorie; return $this; }
    public function getCategorieLabel(): string { return array_search($this->categorie, self::CATEGORIES, true) ?: 'Autre document'; }
    public function isPublie(): bool { return $this->publie; }
    public function setPublie(bool $publie): static { $this->publie = $publie; return $this; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }
    public function getLockVersion(): int { return $this->lockVersion; }
    /** @return Collection<int, DocumentFormateurVersion> */
    public function getVersions(): Collection { return $this->versions; }
    public function addVersion(DocumentFormateurVersion $version): static
    {
        if (!$this->versions->contains($version)) {
            $this->versions->add($version);
            $version->setDocument($this);
        }
        $this->touch();
        return $this;
    }
    public function getCurrentVersion(): ?DocumentFormateurVersion
    {
        $latest = null;
        foreach ($this->versions as $version) {
            if (!$latest || $version->getNumero() > $latest->getNumero()) $latest = $version;
        }
        return $latest;
    }
}
