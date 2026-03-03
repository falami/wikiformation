<?php

namespace App\Entity;

use App\Repository\SupportAssetRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: SupportAssetRepository::class)]
#[ORM\Table(name: 'support_asset')]
class SupportAsset
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'supportAssets')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Entite $entite = null;

    #[ORM\ManyToOne(inversedBy: 'supportAssets')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Utilisateur $uploadedBy = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    private ?string $titre = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private ?string $filename = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $originalName = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $mimeType = null;

    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true], nullable: true)]
    private ?int $sizeBytes = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $uploadedAt = null;

    /**
     * @var Collection<int, SupportAssignSession>
     */
    #[ORM\OneToMany(targetEntity: SupportAssignSession::class, mappedBy: 'asset')]
    private Collection $supportAssignSessions;

    /**
     * @var Collection<int, SupportAssignUser>
     */
    #[ORM\OneToMany(targetEntity: SupportAssignUser::class, mappedBy: 'asset')]
    private Collection $supportAssignUsers;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'supportAssetCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;


    public function __construct()
    {
        $this->uploadedAt = new \DateTimeImmutable();
        $this->supportAssignSessions = new ArrayCollection();
        $this->supportAssignUsers = new ArrayCollection();
        $this->dateCreation = new \DateTimeImmutable();
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

    public function getUploadedBy(): ?Utilisateur
    {
        return $this->uploadedBy;
    }

    public function setUploadedBy(?Utilisateur $uploadedBy): static
    {
        $this->uploadedBy = $uploadedBy;

        return $this;
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

    public function getFilename(): ?string
    {
        return $this->filename;
    }

    public function setFilename(string $filename): static
    {
        $this->filename = $filename;

        return $this;
    }

    public function getOriginalName(): ?string
    {
        return $this->originalName;
    }

    public function setOriginalName(?string $originalName): static
    {
        $this->originalName = $originalName;

        return $this;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function setMimeType(?string $mimeType): static
    {
        $this->mimeType = $mimeType;

        return $this;
    }

    public function getSizeBytes(): ?int
    {
        return $this->sizeBytes;
    }

    public function setSizeBytes(?int $sizeBytes): static
    {
        $this->sizeBytes = $sizeBytes;

        return $this;
    }

    public function getUploadedAt(): ?\DateTimeImmutable
    {
        return $this->uploadedAt;
    }

    public function setUploadedAt(\DateTimeImmutable $uploadedAt): static
    {
        $this->uploadedAt = $uploadedAt;

        return $this;
    }

    /**
     * @return Collection<int, SupportAssignSession>
     */
    public function getSupportAssignSessions(): Collection
    {
        return $this->supportAssignSessions;
    }

    public function addSupportAssignSession(SupportAssignSession $supportAssignSession): static
    {
        if (!$this->supportAssignSessions->contains($supportAssignSession)) {
            $this->supportAssignSessions->add($supportAssignSession);
            $supportAssignSession->setAsset($this);
        }

        return $this;
    }

    public function removeSupportAssignSession(SupportAssignSession $supportAssignSession): static
    {
        if ($this->supportAssignSessions->removeElement($supportAssignSession)) {
            // set the owning side to null (unless already changed)
            if ($supportAssignSession->getAsset() === $this) {
                $supportAssignSession->setAsset(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, SupportAssignUser>
     */
    public function getSupportAssignUsers(): Collection
    {
        return $this->supportAssignUsers;
    }

    public function addSupportAssignUser(SupportAssignUser $supportAssignUser): static
    {
        if (!$this->supportAssignUsers->contains($supportAssignUser)) {
            $this->supportAssignUsers->add($supportAssignUser);
            $supportAssignUser->setAsset($this);
        }

        return $this;
    }

    public function removeSupportAssignUser(SupportAssignUser $supportAssignUser): static
    {
        if ($this->supportAssignUsers->removeElement($supportAssignUser)) {
            // set the owning side to null (unless already changed)
            if ($supportAssignUser->getAsset() === $this) {
                $supportAssignUser->setAsset(null);
            }
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
}
