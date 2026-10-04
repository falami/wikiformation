<?php

namespace App\Entity;

use App\Repository\RapportFormateurRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

#[ORM\Entity(repositoryClass: RapportFormateurRepository::class)]
class RapportFormateur
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'rapportFormateurs')]
    private ?Session $session = null;

    #[ORM\ManyToOne(inversedBy: 'rapportFormateurs')]
    private ?Formateur $formateur = null;

    #[ORM\Column(nullable: true)]
    private ?array $criteres = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $commentaires = null;

    #[ORM\Column]
    private \DateTimeImmutable $submittedAt;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'rapportFormateurCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\ManyToOne(inversedBy: 'rapportFormateurEntites')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Entite $entite = null;

    public const PRIORITIES = ['Faible' => 'low', 'Normale' => 'normal', 'Importante' => 'high', 'Urgente' => 'urgent'];
    public const STATUSES = ['À traiter' => 'new', 'En cours' => 'in_progress', 'Traité' => 'resolved', 'Classé sans suite' => 'closed'];

    #[ORM\Column(length: 20, options: ['default' => 'normal'])]
    private string $importance = 'normal';

    #[ORM\Column(length: 20, options: ['default' => 'new'])]
    private string $statutTraitement = 'new';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $actionsRealisees = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $historiqueTraitement = [];

    public function getImportance(): string { return $this->importance; }
    public function setImportance(string $value): static {
        if (!in_array($value, self::PRIORITIES, true)) throw new \InvalidArgumentException('Importance invalide');
        $this->importance = $value; return $this;
    }
    public function getImportanceLabel(): string { return array_search($this->importance, self::PRIORITIES, true) ?: $this->importance; }
    public function getStatutTraitement(): string { return $this->statutTraitement; }
    public function getStatutTraitementLabel(): string { return array_search($this->statutTraitement, self::STATUSES, true) ?: $this->statutTraitement; }
    public function getActionsRealisees(): ?string { return $this->actionsRealisees; }
    public function getHistoriqueTraitement(): array { return $this->historiqueTraitement ?? []; }
    public function traiter(string $status, ?string $actions, Utilisateur $author): void {
        if (!in_array($status, self::STATUSES, true)) throw new \InvalidArgumentException('Statut invalide');
        if ($this->statutTraitement === $status && $this->actionsRealisees === $actions) return;
        $this->historiqueTraitement[] = ['date' => (new \DateTimeImmutable())->format(DATE_ATOM), 'auteur' => trim($author->getPrenom().' '.$author->getNom()), 'auteurId' => $author->getId(), 'statut' => $status, 'actions' => $actions];
        $this->statutTraitement = $status;
        $this->actionsRealisees = $actions;
    }

    public function __construct()
    {
        $this->dateCreation = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSession(): ?Session
    {
        return $this->session;
    }

    public function setSession(?Session $session): static
    {
        $this->session = $session;

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

    public function getCriteres(): ?array
    {
        return $this->criteres;
    }

    public function setCriteres(?array $criteres): static
    {
        $this->criteres = $criteres;

        return $this;
    }

    public function getCommentaires(): ?string
    {
        return $this->commentaires;
    }

    public function setCommentaires(?string $commentaires): static
    {
        $this->commentaires = $commentaires;

        return $this;
    }

    public function getSubmittedAt(): \DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function setSubmittedAt(\DateTimeImmutable $submittedAt): static
    {
        $this->submittedAt = $submittedAt;

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
