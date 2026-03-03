<?php

namespace App\Entity;

use App\Repository\EmailLogRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EmailLogRepository::class)]
#[ORM\Index(columns: ['sent_at'])]
class EmailLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'emailLogs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Entite $entite = null;

    #[ORM\ManyToOne(inversedBy: 'emailLogs')]
    private ?Prospect $prospect = null;

    #[ORM\ManyToOne(inversedBy: 'emailLogs')]
    private ?EmailTemplate $template = null;

    #[ORM\Column(length: 180)]
    private string $toEmail = '';

    #[ORM\Column(length: 200)]
    private string $subject = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $bodyHtmlSnapshot = null;

    #[ORM\Column(length: 20)]
    private string $status = 'SENT';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column]
    private \DateTimeImmutable $sentAt;

    /**
     * @var Collection<int, ProspectInteraction>
     */
    #[ORM\OneToMany(targetEntity: ProspectInteraction::class, mappedBy: 'emailLog')]
    private Collection $prospectInteractions;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $idemKey = null;

    #[ORM\ManyToOne(inversedBy: 'emailLogs')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Devis $devis = null;

    #[ORM\ManyToOne(inversedBy: 'emailLogs')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Facture $facture = null;


    #[ORM\ManyToOne(inversedBy: 'sentEmailLogs')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $actor = null;

    #[ORM\ManyToOne(inversedBy: 'receivedEmailLogs')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $toUser = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'emailLogCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;


    public function __construct()
    {
        $this->sentAt = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
        $this->prospectInteractions = new ArrayCollection();
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

    public function getProspect(): ?Prospect
    {
        return $this->prospect;
    }

    public function setProspect(?Prospect $prospect): static
    {
        $this->prospect = $prospect;

        return $this;
    }

    public function getTemplate(): ?EmailTemplate
    {
        return $this->template;
    }

    public function setTemplate(?EmailTemplate $template): static
    {
        $this->template = $template;

        return $this;
    }

    public function getToEmail(): string
    {
        return $this->toEmail;
    }

    public function setToEmail(string $toEmail): static
    {
        $this->toEmail = $toEmail;

        return $this;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function setSubject(string $subject): static
    {
        $this->subject = $subject;

        return $this;
    }

    public function getBodyHtmlSnapshot(): ?string
    {
        return $this->bodyHtmlSnapshot;
    }

    public function setBodyHtmlSnapshot(?string $bodyHtmlSnapshot): static
    {
        $this->bodyHtmlSnapshot = $bodyHtmlSnapshot;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): static
    {
        $this->errorMessage = $errorMessage;

        return $this;
    }

    public function getSentAt(): \DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function setSentAt(\DateTimeImmutable $sentAt): static
    {
        $this->sentAt = $sentAt;

        return $this;
    }

    /**
     * @return Collection<int, ProspectInteraction>
     */
    public function getProspectInteractions(): Collection
    {
        return $this->prospectInteractions;
    }

    public function addProspectInteraction(ProspectInteraction $prospectInteraction): static
    {
        if (!$this->prospectInteractions->contains($prospectInteraction)) {
            $this->prospectInteractions->add($prospectInteraction);
            $prospectInteraction->setEmailLog($this);
        }

        return $this;
    }

    public function removeProspectInteraction(ProspectInteraction $prospectInteraction): static
    {
        if ($this->prospectInteractions->removeElement($prospectInteraction)) {
            // set the owning side to null (unless already changed)
            if ($prospectInteraction->getEmailLog() === $this) {
                $prospectInteraction->setEmailLog(null);
            }
        }

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getIdemKey(): ?string
    {
        return $this->idemKey;
    }

    public function setIdemKey(?string $idemKey): static
    {
        $this->idemKey = $idemKey;

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

    public function getActor(): ?Utilisateur
    {
        return $this->actor;
    }

    public function setActor(?Utilisateur $actor): static
    {
        $this->actor = $actor;

        return $this;
    }

    public function getToUser(): ?Utilisateur
    {
        return $this->toUser;
    }

    public function setToUser(?Utilisateur $toUser): static
    {
        $this->toUser = $toUser;

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
