<?php

namespace App\Entity;

use App\Repository\ConventionContratRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: ConventionContratRepository::class)]
#[ORM\Table(name: 'convention_contrat')]
#[ORM\Index(name: 'idx_convention_numero', columns: ['numero'])]
#[ORM\Index(name: 'idx_convention_devis', columns: ['devis_id'])]
#[ORM\Index(name: 'idx_conv_entite_session_entreprise', columns: ['entite_id', 'session_id', 'entreprise_id'])]
#[ORM\Index(name: 'idx_conv_entite_session_stagiaire', columns: ['entite_id', 'session_id', 'stagiaire_id'])]
#[ORM\UniqueConstraint(
    name: 'uniq_convention_numero',
    columns: ['numero']
)]
class ConventionContrat
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dateSignatureStagiaire = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dateSignatureEntreprise = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dateSignatureOf = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $conditionsFinancieres = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $pdfPath = null;

    #[ORM\ManyToOne(inversedBy: 'conventionContrats')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Entite $entite = null;

    #[ORM\ManyToOne(inversedBy: 'conventionContrats')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'Sélectionnez une session.')]
    private ?Session $session = null;

    #[ORM\ManyToOne(inversedBy: 'conventions')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Devis $devis = null;

    // ✅ devient nullable (cas individuel)
    #[ORM\ManyToOne(inversedBy: 'conventionContrats')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Entreprise $entreprise = null;

    // ✅ destinataire individuel (cas sans entreprise)
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $stagiaire = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $signatureDataUrlStagiaire = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $signatureDataUrlEntreprise = null;

    /**
     * ✅ Inscriptions couvertes par cette convention
     * @var Collection<int, Inscription>
     */
    #[ORM\ManyToMany(targetEntity: Inscription::class, inversedBy: 'conventionContrats')]
    #[ORM\JoinTable(name: 'convention_contrat_inscription')]
    private Collection $inscriptions;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'conventionContratCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\Column(length: 40, unique: true)]
    private string $numero = '';

    public function __construct()
    {
        $this->dateCreation = new \DateTimeImmutable();
        $this->inscriptions = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDateSignatureStagiaire(): ?\DateTimeImmutable
    {
        return $this->dateSignatureStagiaire;
    }

    public function setDateSignatureStagiaire(?\DateTimeImmutable $dateSignatureStagiaire): static
    {
        $this->dateSignatureStagiaire = $dateSignatureStagiaire;
        return $this;
    }

    public function getDateSignatureEntreprise(): ?\DateTimeImmutable
    {
        return $this->dateSignatureEntreprise;
    }

    public function setDateSignatureEntreprise(?\DateTimeImmutable $dateSignatureEntreprise): static
    {
        $this->dateSignatureEntreprise = $dateSignatureEntreprise;
        return $this;
    }

    public function getDateSignatureOf(): ?\DateTimeImmutable
    {
        return $this->dateSignatureOf;
    }

    public function setDateSignatureOf(?\DateTimeImmutable $dateSignatureOf): static
    {
        $this->dateSignatureOf = $dateSignatureOf;
        return $this;
    }

    public function getConditionsFinancieres(): ?string
    {
        return $this->conditionsFinancieres;
    }

    public function setConditionsFinancieres(?string $conditionsFinancieres): static
    {
        $this->conditionsFinancieres = $conditionsFinancieres;
        return $this;
    }

    public function getPdfPath(): ?string
    {
        return $this->pdfPath;
    }

    public function setPdfPath(?string $pdfPath): static
    {
        $this->pdfPath = $pdfPath;
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

    public function getSession(): ?Session
    {
        return $this->session;
    }

    public function setSession(?Session $session): static
    {
        if ($this->session === $session) {
            return $this;
        }

        $previous = $this->session;
        $this->session = $session;
        $previous?->removeConventionContrat($this);
        $session?->addConventionContrat($this);

        return $this;
    }

    public function getDevis(): ?Devis
    {
        return $this->devis;
    }

    public function setDevis(?Devis $devis): static
    {
        if ($this->devis === $devis) {
            return $this;
        }

        $previous = $this->devis;
        $this->devis = $devis;
        $previous?->removeConvention($this);
        $devis?->addConvention($this);

        return $this;
    }

    public function getEntreprise(): ?Entreprise
    {
        return $this->entreprise;
    }

    public function setEntreprise(?Entreprise $entreprise): static
    {
        $this->entreprise = $entreprise;
        return $this;
    }

    public function getStagiaire(): ?Utilisateur
    {
        return $this->stagiaire;
    }

    public function setStagiaire(?Utilisateur $stagiaire): static
    {
        $this->stagiaire = $stagiaire;
        return $this;
    }

    public function getSignatureDataUrlStagiaire(): ?string
    {
        return $this->signatureDataUrlStagiaire;
    }

    public function setSignatureDataUrlStagiaire(?string $signatureDataUrlStagiaire): static
    {
        $this->signatureDataUrlStagiaire = $signatureDataUrlStagiaire;
        return $this;
    }

    public function getSignatureDataUrlEntreprise(): ?string
    {
        return $this->signatureDataUrlEntreprise;
    }

    public function setSignatureDataUrlEntreprise(?string $signatureDataUrlEntreprise): static
    {
        $this->signatureDataUrlEntreprise = $signatureDataUrlEntreprise;
        return $this;
    }

    public function isSignedByStagiaire(): bool
    {
        return null !== $this->dateSignatureStagiaire;
    }

    public function isSignedByEntreprise(): bool
    {
        return null !== $this->dateSignatureEntreprise;
    }

    public function isSignedByOf(): bool
    {
        return null !== $this->dateSignatureOf;
    }

    public function isSigned(): bool
    {
        return $this->isSignedByStagiaire()
            || $this->isSignedByEntreprise()
            || $this->isSignedByOf()
            || !empty($this->signatureDataUrlStagiaire)
            || !empty($this->signatureDataUrlEntreprise);
    }

    public function getDestinataireLabel(): string
    {
        if ($this->entreprise) {
            return $this->entreprise->getRaisonSociale() ?? 'Entreprise';
        }
        if ($this->stagiaire) {
            return trim(($this->stagiaire->getPrenom() ?? '') . ' ' . ($this->stagiaire->getNom() ?? '')) ?: 'Stagiaire';
        }
        return '—';
    }

    public function isEntrepriseConvention(): bool
    {
        return null !== $this->entreprise;
    }

    public function isIndividuelleConvention(): bool
    {
        return null !== $this->stagiaire;
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
            $inscription->addConventionContrat($this);
        }
        return $this;
    }

    public function removeInscription(Inscription $inscription): static
    {
        if ($this->inscriptions->removeElement($inscription)) {
            $inscription->removeConventionContrat($this);
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

    public function getNumero(): string
    {
        return $this->numero;
    }

    public function setNumero(string $numero): static
    {
        $this->numero = $numero;
        return $this;
    }

    public function hasNumero(): bool
    {
        return !empty($this->numero ?? '');
    }

    #[Assert\Callback]
    public function validateCoherence(ExecutionContextInterface $context): void
    {
        if (($this->entreprise === null) === ($this->stagiaire === null)) {
            $context->buildViolation('Choisissez une entreprise ou un stagiaire comme destinataire de la convention.')
                ->atPath('entreprise')->addViolation();
        }

        if ($this->session && $this->session->getEntite() !== $this->entite) {
            $context->buildViolation('La session doit appartenir au même organisme que la convention.')
                ->atPath('session')->addViolation();
        }

        if ($this->entreprise && $this->entreprise->getEntite() !== $this->entite) {
            $context->buildViolation('L’entreprise doit appartenir au même organisme que la convention.')
                ->atPath('entreprise')->addViolation();
        }

        foreach ($this->inscriptions as $inscription) {
            if ($inscription->getSession() !== $this->session || $inscription->getEntite() !== $this->entite) {
                $context->buildViolation('Toutes les inscriptions doivent appartenir à la session et à l’organisme de la convention.')
                    ->atPath('inscriptions')->addViolation();
                break;
            }

            if (($this->entreprise && $inscription->getEntreprise() !== $this->entreprise)
                || ($this->stagiaire && $inscription->getStagiaire() !== $this->stagiaire)) {
                $context->buildViolation('Les inscriptions doivent correspondre au destinataire de la convention.')
                    ->atPath('inscriptions')->addViolation();
                break;
            }
        }

        if (!$this->devis) {
            return;
        }

        if ($this->devis->getEntite() !== $this->entite) {
            $context->buildViolation('Le devis doit appartenir au même organisme que la convention.')
                ->atPath('devis')->addViolation();
        }

        if ($this->devis->getFormation() && $this->session
            && $this->devis->getFormation() !== $this->session->getFormation()) {
            $context->buildViolation('La session doit correspondre à la formation du devis.')
                ->atPath('session')->addViolation();
        }

        $entrepriseDevis = $this->devis->getEntrepriseDestinataire();
        if (($entrepriseDevis && $entrepriseDevis !== $this->entreprise)
            || (!$entrepriseDevis && $this->devis->getDestinataire() !== $this->stagiaire)) {
            $context->buildViolation('Le destinataire de la convention doit correspondre à celui du devis.')
                ->atPath('devis')->addViolation();
        }
    }
}
