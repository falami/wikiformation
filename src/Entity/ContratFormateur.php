<?php

namespace App\Entity;

use App\Repository\ContratFormateurRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Enum\ContratFormateurStatus;

#[ORM\Entity(repositoryClass: ContratFormateurRepository::class)]
#[ORM\Table(name: 'contrat_formateur')]
#[ORM\UniqueConstraint(
    name: 'uniq_contrat_session_formateur',
    columns: ['session_id', 'formateur_id']
)]
class ContratFormateur
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;


    #[ORM\ManyToOne(inversedBy: 'contratFormateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Entite $entite = null;

    #[ORM\ManyToOne(inversedBy: 'contratFormateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Formateur $formateur = null;

    #[ORM\Column(length: 40, unique: true)]
    private string $numero = '';

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\Column(enumType: ContratFormateurStatus::class)]
    private ContratFormateurStatus $status = ContratFormateurStatus::BROUILLON;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $conditionsGenerales = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $conditionsParticulieres = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $signatureDataUrl = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $signatureAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $signatureIp = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $pdfPath = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $signatureUserAgent = null;

    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    private int $montantPrevuCents = 0;

    #[ORM\Column(type: 'integer', options: ['unsigned' => true, 'default' => 0])]
    private int $fraisMissionCents = 0;

    #[ORM\ManyToOne(inversedBy: 'contratFormateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Session $session = null;

    #[ORM\Column(nullable: true)]
    private ?bool $assujettiTva = false;

    #[ORM\Column(nullable: true)]
    private ?float $tauxTva = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $numeroTvaIntra = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $clauseEmploi = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $clauseEngagement = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $clauseObjet = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $clauseObligations = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $clauseNonConcurrence = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $clauseInexecution = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $clauseAssurance = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $clauseFinContrat = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $clauseProprieteIntellectuelle = null;

    #[ORM\ManyToOne(inversedBy: 'contratFormateurCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $signatureOrganismePath = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $signatureOrganismeAt = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $signatureOrganismeIp = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $signatureOrganismeNom = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $signatureOrganismeFonction = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $signatureOrganismeUserAgent = null;

    // audit (qui a configuré la signature)
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $signatureOrganismePar = null;


    public function __construct()
    {
        // Optionnel mais propre si tu veux être béton de chez béton :
        $this->status             = ContratFormateurStatus::BROUILLON;
        $this->numero             = '';
        $this->montantPrevuCents  = 0;
        $this->fraisMissionCents  = 0;
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

    public function getFormateur(): ?Formateur
    {
        return $this->formateur;
    }

    public function setFormateur(?Formateur $formateur): static
    {
        $this->formateur = $formateur;

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

    public function getDateCreation(): ?\DateTimeImmutable
    {
        return $this->dateCreation;
    }

    public function setDateCreation(\DateTimeImmutable $dateCreation): static
    {
        $this->dateCreation = $dateCreation;

        return $this;
    }


    public function getConditionsGenerales(): ?string
    {
        return $this->conditionsGenerales;
    }

    public function setConditionsGenerales(?string $conditionsGenerales): static
    {
        $this->conditionsGenerales = $conditionsGenerales;

        return $this;
    }

    public function getConditionsParticulieres(): ?string
    {
        return $this->conditionsParticulieres;
    }

    public function setConditionsParticulieres(?string $conditionsParticulieres): static
    {
        $this->conditionsParticulieres = $conditionsParticulieres;

        return $this;
    }

    public function getSignatureDataUrl(): ?string
    {
        return $this->signatureDataUrl;
    }

    public function setSignatureDataUrl(?string $signatureDataUrl): static
    {
        $this->signatureDataUrl = $signatureDataUrl;

        return $this;
    }

    public function getSignatureAt(): ?\DateTimeImmutable
    {
        return $this->signatureAt;
    }

    public function setSignatureAt(?\DateTimeImmutable $signatureAt): static
    {
        $this->signatureAt = $signatureAt;

        return $this;
    }

    public function getSignatureIp(): ?string
    {
        return $this->signatureIp;
    }

    public function setSignatureIp(?string $signatureIp): static
    {
        $this->signatureIp = $signatureIp;

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

    public function getSignatureUserAgent(): ?string
    {
        return $this->signatureUserAgent;
    }

    public function setSignatureUserAgent(?string $signatureUserAgent): static
    {
        $this->signatureUserAgent = $signatureUserAgent;

        return $this;
    }


    public function getStatus(): ContratFormateurStatus
    {
        return $this->status;
    }

    public function setStatus(ContratFormateurStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getMontantPrevuCents(): int
    {
        return $this->montantPrevuCents;
    }

    public function setMontantPrevuCents(int $montantPrevuCents): static
    {
        $this->montantPrevuCents = $montantPrevuCents;

        return $this;
    }

    public function getFraisMissionCents(): int
    {
        return $this->fraisMissionCents;
    }

    public function setFraisMissionCents(int $fraisMissionCents): static
    {
        $this->fraisMissionCents = $fraisMissionCents;

        return $this;
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

    public function isAssujettiTva(): ?bool
    {
        return $this->assujettiTva;
    }

    public function setAssujettiTva(?bool $assujettiTva): static
    {
        $this->assujettiTva = $assujettiTva;

        return $this;
    }

    public function getTauxTva(): ?float
    {
        return $this->tauxTva;
    }

    public function setTauxTva(?float $tauxTva): static
    {
        $this->tauxTva = $tauxTva;

        return $this;
    }

    public function getNumeroTvaIntra(): ?string
    {
        return $this->numeroTvaIntra;
    }

    public function setNumeroTvaIntra(?string $numeroTvaIntra): static
    {
        $this->numeroTvaIntra = $numeroTvaIntra;

        return $this;
    }


    public function getClauseEngagement(): ?string
    {
        return $this->clauseEngagement;
    }
    public function setClauseEngagement(?string $v): static
    {
        $this->clauseEngagement = $v;
        return $this;
    }

    public function getClauseObjet(): ?string
    {
        return $this->clauseObjet;
    }
    public function setClauseObjet(?string $v): static
    {
        $this->clauseObjet = $v;
        return $this;
    }

    public function getClauseObligations(): ?string
    {
        return $this->clauseObligations;
    }
    public function setClauseObligations(?string $v): static
    {
        $this->clauseObligations = $v;
        return $this;
    }

    public function getClauseNonConcurrence(): ?string
    {
        return $this->clauseNonConcurrence;
    }
    public function setClauseNonConcurrence(?string $v): static
    {
        $this->clauseNonConcurrence = $v;
        return $this;
    }

    public function getClauseInexecution(): ?string
    {
        return $this->clauseInexecution;
    }
    public function setClauseInexecution(?string $v): static
    {
        $this->clauseInexecution = $v;
        return $this;
    }

    public function getClauseAssurance(): ?string
    {
        return $this->clauseAssurance;
    }
    public function setClauseAssurance(?string $v): static
    {
        $this->clauseAssurance = $v;
        return $this;
    }

    public function getClauseFinContrat(): ?string
    {
        return $this->clauseFinContrat;
    }
    public function setClauseFinContrat(?string $v): static
    {
        $this->clauseFinContrat = $v;
        return $this;
    }

    public function getClauseProprieteIntellectuelle(): ?string
    {
        return $this->clauseProprieteIntellectuelle;
    }
    public function setClauseProprieteIntellectuelle(?string $v): static
    {
        $this->clauseProprieteIntellectuelle = $v;
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

    public function getSignatureOrganismePath(): ?string
    {
        return $this->signatureOrganismePath;
    }

    public function setSignatureOrganismePath(?string $signatureOrganismePath): static
    {
        $this->signatureOrganismePath = $signatureOrganismePath;

        return $this;
    }

    public function getSignatureOrganismeAt(): ?\DateTimeImmutable
    {
        return $this->signatureOrganismeAt;
    }

    public function setSignatureOrganismeAt(?\DateTimeImmutable $signatureOrganismeAt): static
    {
        $this->signatureOrganismeAt = $signatureOrganismeAt;

        return $this;
    }

    public function getSignatureOrganismeIp(): ?string
    {
        return $this->signatureOrganismeIp;
    }

    public function setSignatureOrganismeIp(?string $signatureOrganismeIp): static
    {
        $this->signatureOrganismeIp = $signatureOrganismeIp;

        return $this;
    }

    public function getSignatureOrganismeNom(): ?string
    {
        return $this->signatureOrganismeNom;
    }

    public function setSignatureOrganismeNom(?string $signatureOrganismeNom): static
    {
        $this->signatureOrganismeNom = $signatureOrganismeNom;

        return $this;
    }

    public function getSignatureOrganismeFonction(): ?string
    {
        return $this->signatureOrganismeFonction;
    }

    public function setSignatureOrganismeFonction(?string $signatureOrganismeFonction): static
    {
        $this->signatureOrganismeFonction = $signatureOrganismeFonction;

        return $this;
    }

    public function getSignatureOrganismePar(): ?Utilisateur
    {
        return $this->signatureOrganismePar;
    }
    public function setSignatureOrganismePar(?Utilisateur $v): static
    {
        $this->signatureOrganismePar = $v;
        return $this;
    }

    public function getSignatureOrganismeUserAgent(): ?string
    {
        return $this->signatureOrganismeUserAgent;
    }
    public function setSignatureOrganismeUserAgent(?string $v): static
    {
        $this->signatureOrganismeUserAgent = $v;
        return $this;
    }

    public function getClauseEmploi(): ?string
    {
        return $this->clauseEmploi;
    }
    public function setClauseEmploi(?string $v): static
    {
        $this->clauseEmploi = $v;
        return $this;
    }
}
