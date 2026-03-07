<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PublicHostRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use App\Entity\Formation;
use App\Entity\Entite;

#[ORM\Entity(repositoryClass: PublicHostRepository::class)]
#[ORM\Table(name: 'public_host')]
#[ORM\UniqueConstraint(name: 'uniq_public_host_host', columns: ['host'])]
class PublicHost
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 190)]
    private string $host;

    #[ORM\Column(length: 120)]
    private string $name = 'Wikiformation';

    #[ORM\Column(length: 190, nullable: true)]
    private ?string $logoPath = null;

    #[ORM\Column(length: 20)]
    private string $primaryColor = '#233342';

    #[ORM\Column(length: 20)]
    private string $secondaryColor = '#ffc107';

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $tertiaryColor = '#F0F0F0';

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $quaternaryColor = '#000000';

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $catalogueEnabled = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $calendarEnabled = true;

    #[ORM\Column(options: ['default' => false])]
    private bool $elearningEnabled = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $shopEnabled = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $restrictToAssignedFormations = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $showAllPublicFormations = false;

    #[ORM\ManyToMany(targetEntity: Formation::class, mappedBy: 'publicHosts')]
    private Collection $formations;

    #[ORM\ManyToOne(inversedBy: 'publicHosts')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Entite $entite = null;

    public function __construct()
    {
        $this->formations = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->name . ' (' . $this->host . ')';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function setHost(string $host): static
    {
        $this->host = mb_strtolower(trim($host));

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = trim($name);

        return $this;
    }

    public function getLogoPath(): ?string
    {
        return $this->logoPath;
    }

    public function setLogoPath(?string $logoPath): static
    {
        $this->logoPath = $logoPath ? trim($logoPath) : null;

        return $this;
    }

    public function getPrimaryColor(): string
    {
        return $this->primaryColor;
    }

    public function setPrimaryColor(string $primaryColor): static
    {
        $this->primaryColor = trim($primaryColor);

        return $this;
    }

    public function getSecondaryColor(): string
    {
        return $this->secondaryColor;
    }

    public function setSecondaryColor(string $secondaryColor): static
    {
        $this->secondaryColor = trim($secondaryColor);

        return $this;
    }

    public function getTertiaryColor(): ?string
    {
        return $this->tertiaryColor;
    }

    public function setTertiaryColor(?string $tertiaryColor): static
    {
        $this->tertiaryColor = $tertiaryColor ? trim($tertiaryColor) : null;

        return $this;
    }

    public function getQuaternaryColor(): ?string
    {
        return $this->quaternaryColor;
    }

    public function setQuaternaryColor(?string $quaternaryColor): static
    {
        $this->quaternaryColor = $quaternaryColor ? trim($quaternaryColor) : null;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function isCatalogueEnabled(): bool
    {
        return $this->catalogueEnabled;
    }

    public function setCatalogueEnabled(bool $catalogueEnabled): static
    {
        $this->catalogueEnabled = $catalogueEnabled;

        return $this;
    }

    public function isCalendarEnabled(): bool
    {
        return $this->calendarEnabled;
    }

    public function setCalendarEnabled(bool $calendarEnabled): static
    {
        $this->calendarEnabled = $calendarEnabled;

        return $this;
    }

    public function isElearningEnabled(): bool
    {
        return $this->elearningEnabled;
    }

    public function setElearningEnabled(bool $elearningEnabled): static
    {
        $this->elearningEnabled = $elearningEnabled;

        return $this;
    }

    public function isShopEnabled(): bool
    {
        return $this->shopEnabled;
    }

    public function setShopEnabled(bool $shopEnabled): static
    {
        $this->shopEnabled = $shopEnabled;

        return $this;
    }

    public function isRestrictToAssignedFormations(): bool
    {
        return $this->restrictToAssignedFormations;
    }

    public function setRestrictToAssignedFormations(bool $restrictToAssignedFormations): static
    {
        $this->restrictToAssignedFormations = $restrictToAssignedFormations;

        return $this;
    }

    public function isShowAllPublicFormations(): bool
    {
        return $this->showAllPublicFormations;
    }

    public function setShowAllPublicFormations(bool $showAllPublicFormations): static
    {
        $this->showAllPublicFormations = $showAllPublicFormations;

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
            $formation->addPublicHost($this);
        }

        return $this;
    }

    public function removeFormation(Formation $formation): static
    {
        if ($this->formations->removeElement($formation)) {
            $formation->removePublicHost($this);
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
}