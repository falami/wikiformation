<?php

namespace App\Entity;

use App\Repository\ProspectInteractionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Enum\InteractionChannel;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: ProspectInteractionRepository::class)]
#[ORM\Index(columns: ['occurred_at'])]
class ProspectInteraction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'interactions')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Prospect $prospect = null;



    #[ORM\Column(enumType: InteractionChannel::class)]
    private InteractionChannel $channel = InteractionChannel::EMAIL;

    #[ORM\Column(length: 120)]
    private string $title = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $content = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    #[ORM\ManyToOne(inversedBy: 'prospectInteractions')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $actor = null;

    #[ORM\ManyToOne(inversedBy: 'prospectInteractions')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?EmailLog $emailLog = null;

    #[ORM\ManyToOne(inversedBy: 'prospectInteractions', targetEntity: Devis::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Devis $devis = null;

    #[ORM\ManyToOne(inversedBy: 'prospectInteractions')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Facture $facture = null;

    #[ORM\ManyToOne(inversedBy: 'prospectInteractionsUtilisateurs')]
    private ?Utilisateur $utilisateur = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'prospectInteractionCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\ManyToOne(inversedBy: 'prospectInteractionEntites')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Entite $entite = null;

    #[ORM\ManyToOne(inversedBy: 'prospectInteractions')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Prospect $actorProspect = null;


    public function __construct()
    {
        $this->occurredAt = new \DateTimeImmutable();
        $this->dateCreation = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProspect(): ?Prospect
    {
        return $this->prospect;
    }

    public function setProspect(?Prospect $prospect): static
    {
        $this->prospect = $prospect;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(?string $content): static
    {
        $this->content = $content;

        return $this;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function setOccurredAt(\DateTimeImmutable $occurredAt): static
    {
        $this->occurredAt = $occurredAt;

        return $this;
    }

    public function getActor(): ?Utilisateur
    {
        return $this->actor;
    }

    public function setActor(?Utilisateur $actor): static
    {
        $this->actor = $actor;

        return $this;
    }

    public function getChannel(): InteractionChannel
    {
        return $this->channel;
    }

    public function setChannel(InteractionChannel $source): static
    {
        $this->channel = $source;

        return $this;
    }

    public function getEmailLog(): ?EmailLog
    {
        return $this->emailLog;
    }

    public function setEmailLog(?EmailLog $emailLog): static
    {
        $this->emailLog = $emailLog;

        return $this;
    }

    public function getDevis(): ?Devis
    {
        return $this->devis;
    }

    public function setDevis(?Devis $devis): static
    {
        $this->devis = $devis;

        return $this;
    }

    public function getFacture(): ?Facture
    {
        return $this->facture;
    }

    public function setFacture(?Facture $facture): static
    {
        $this->facture = $facture;

        return $this;
    }

    public function getUtilisateur(): ?Utilisateur
    {
        return $this->utilisateur;
    }

    public function setUtilisateur(?Utilisateur $utilisateur): static
    {
        $this->utilisateur = $utilisateur;

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

    public function getActorProspect(): ?Prospect
    {
        return $this->actorProspect;
    }

    public function setActorProspect(?Prospect $actorProspect): static
    {
        $this->actorProspect = $actorProspect;

        return $this;
    }

    public function getActorLabel(): string
    {
        if ($this->actor) {
            $n = trim(($this->actor->getPrenom() ?? '') . ' ' . ($this->actor->getNom() ?? ''));
            return $n !== '' ? $n : ($this->actor->getEmail() ?? 'Utilisateur #' . $this->actor->getId());
        }
        if ($this->actorProspect) {
            return $this->actorProspect->getFullName();
        }
        return '—';
    }

    #[Assert\Callback]
    public function validateActor(ExecutionContextInterface $context): void
    {
        if ($this->actor && $this->actorProspect) {
            $context->buildViolation('Choisis soit un utilisateur, soit un prospect (pas les deux).')
                ->atPath('actor')
                ->addViolation();
        }
    }
}
