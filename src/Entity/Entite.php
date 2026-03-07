<?php

namespace App\Entity;

use App\Entity\Billing\EntiteSubscription;
use App\Entity\Billing\EntiteUsageYear;
use App\Entity\Elearning\ElearningBlock;
use App\Entity\Elearning\ElearningCourse;
use App\Entity\Elearning\ElearningEnrollment;
use App\Entity\Elearning\ElearningNode;
use App\Entity\Elearning\ElearningOrder;
use App\Repository\EntiteRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use App\Entity\Billing\EntiteConnect;



#[ORM\Entity(repositoryClass: EntiteRepository::class)]
class Entite
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 7, nullable: true)]
    private ?string $couleurPrincipal = null;

    #[ORM\Column(length: 7, nullable: true)]
    private ?string $couleurSecondaire = null;

    #[ORM\Column(length: 7, nullable: true)]
    private ?string $couleurTertiaire = null;

    #[ORM\Column(length: 7, nullable: true)]
    private ?string $couleurQuaternaire = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logo = null;

    #[ORM\ManyToOne(inversedBy: 'entites')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $adresse = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $complement = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $codePostal = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $ville = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $region = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $pays = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $departement = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Length(max: 20)]
    #[Assert\Regex(
        pattern: '/^\+?[1-9]\d{6,14}$/',
        message: 'Numéro de téléphone invalide (utilise un format international, ex: +33612345678).'
    )]
    private ?string $telephone = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $texteAccueil = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logoMenu = null;

    /**
     * @var Collection<int, UtilisateurEntite>
     */
    #[ORM\OneToMany(targetEntity: UtilisateurEntite::class, mappedBy: 'entite')]
    private Collection $utilisateurEntites;

    #[ORM\Column]
    private ?bool $public = null;

    /**
     * @var Collection<int, Utilisateur>
     */
    #[ORM\OneToMany(targetEntity: Utilisateur::class, mappedBy: 'entite')]
    private Collection $responsables;

    #[ORM\Column(length: 100, nullable: false)]
    private ?string $nom = null;

    /**
     * @var Collection<int, SupportAsset>
     */
    #[ORM\OneToMany(targetEntity: SupportAsset::class, mappedBy: 'entite')]
    private Collection $supportAssets;


    #[ORM\Column(length: 30, nullable: true)]
    private ?string $siret = null;

    /**
     * @var Collection<int, Attestation>
     */
    #[ORM\OneToMany(targetEntity: Attestation::class, mappedBy: 'entite')]
    private Collection $attestations;

    /**
     * @var Collection<int, Facture>
     */
    #[ORM\OneToMany(targetEntity: Facture::class, mappedBy: 'entite', orphanRemoval: true)]
    private Collection $factures;

    /**
     * @var Collection<int, Avoir>
     */
    #[ORM\OneToMany(targetEntity: Avoir::class, mappedBy: 'entite', orphanRemoval: true)]
    private Collection $avoirs;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $iban = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $banque = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $bic = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $numeroTva = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $numeroCompte = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $codeBanque = null;

    #[ORM\Column(length: 14, nullable: true)]
    private ?string $numeroDeclarant = null;

    /**
     * @var Collection<int, ConventionContrat>
     */
    #[ORM\OneToMany(targetEntity: ConventionContrat::class, mappedBy: 'entite')]
    private Collection $conventionContrats;

    /**
     * @var Collection<int, Site>
     */
    #[ORM\OneToMany(targetEntity: Site::class, mappedBy: 'entite')]
    private Collection $sites;

    /**
     * @var Collection<int, Session>
     */
    #[ORM\OneToMany(targetEntity: Session::class, mappedBy: 'entite')]
    private Collection $sessions;

    /**
     * @var Collection<int, ContratFormateur>
     */
    #[ORM\OneToMany(targetEntity: ContratFormateur::class, mappedBy: 'entite')]
    private Collection $contratFormateurs;

    /**
     * @var Collection<int, Formateur>
     */
    #[ORM\OneToMany(targetEntity: Formateur::class, mappedBy: 'entite')]
    private Collection $formateurs;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $formeJuridique = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $fonction = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $nomRepresentant = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $prenomRepresentant = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastActivityAt = null;

    #[ORM\OneToOne(mappedBy: 'entite', targetEntity: EntiteConnect::class, cascade: ['persist', 'remove'])]
    private ?EntiteConnect $connect = null;


    /**
     * @var Collection<int, Devis>
     */
    #[ORM\OneToMany(targetEntity: Devis::class, mappedBy: 'entite')]
    private Collection $devis;

    /**
     * @var Collection<int, DevisSequence>
     */
    #[ORM\OneToMany(targetEntity: DevisSequence::class, mappedBy: 'entite')]
    private Collection $devisSequences;

    /**
     * @var Collection<int, Entreprise>
     */
    #[ORM\OneToMany(targetEntity: Entreprise::class, mappedBy: 'entite')]
    private Collection $entreprises;

    /**
     * @var Collection<int, ContratStagiaire>
     */
    #[ORM\OneToMany(targetEntity: ContratStagiaire::class, mappedBy: 'entite')]
    private Collection $contratStagiaires;

    /**
     * @var Collection<int, Engin>
     */
    #[ORM\OneToMany(targetEntity: Engin::class, mappedBy: 'entite')]
    private Collection $engins;

    /**
     * @var Collection<int, Formation>
     */
    #[ORM\OneToMany(targetEntity: Formation::class, mappedBy: 'entite')]
    private Collection $formations;

    #[ORM\OneToOne(mappedBy: 'entite', cascade: ['persist', 'remove'])]
    private ?EntitePreferences $preferences = null;

    /**
     * @var Collection<int, PositioningQuestionnaire>
     */
    #[ORM\OneToMany(targetEntity: PositioningQuestionnaire::class, mappedBy: 'entite')]
    private Collection $positioningQuestionnaires;


    /**
     * @var Collection<int, SatisfactionTemplate>
     */
    #[ORM\OneToMany(targetEntity: SatisfactionTemplate::class, mappedBy: 'entite', orphanRemoval: true)]
    private Collection $satisfactionTemplates;

    /**
     * @var Collection<int, FormateurSatisfactionTemplate>
     */
    #[ORM\OneToMany(targetEntity: FormateurSatisfactionTemplate::class, mappedBy: 'entite')]
    private Collection $formateurSatisfactionTemplates;

    /**
     * @var Collection<int, Prospect>
     */
    #[ORM\OneToMany(targetEntity: Prospect::class, mappedBy: 'entite')]
    private Collection $prospects;

    /**
     * @var Collection<int, EmailTemplate>
     */
    #[ORM\OneToMany(targetEntity: EmailTemplate::class, mappedBy: 'entite')]
    private Collection $emailTemplates;

    /**
     * @var Collection<int, EmailLog>
     */
    #[ORM\OneToMany(targetEntity: EmailLog::class, mappedBy: 'entite')]
    private Collection $emailLogs;


    /** @var Collection<int, Categorie> */
    #[ORM\OneToMany(mappedBy: 'entite', targetEntity: Categorie::class, orphanRemoval: true)]
    #[ORM\OrderBy(['nom' => 'ASC'])]
    private Collection $categories;

    /**
     * @var Collection<int, Qcm>
     */
    #[ORM\OneToMany(targetEntity: Qcm::class, mappedBy: 'entite')]
    private Collection $qcms;

    /**
     * @var Collection<int, AuditLog>
     */
    #[ORM\OneToMany(targetEntity: AuditLog::class, mappedBy: 'entite')]
    private Collection $adutiLogEntites;

    /**
     * @var Collection<int, ContentBlock>
     */
    #[ORM\OneToMany(targetEntity: ContentBlock::class, mappedBy: 'entite')]
    private Collection $contentBlockEntites;

    /**
     * @var Collection<int, DossierInscription>
     */
    #[ORM\OneToMany(targetEntity: DossierInscription::class, mappedBy: 'entite')]
    private Collection $dossierInscriptionEntites;

    /**
     * @var Collection<int, Emargement>
     */
    #[ORM\OneToMany(targetEntity: Emargement::class, mappedBy: 'entite')]
    private Collection $emargementEntites;

    /**
     * @var Collection<int, EnginPhoto>
     */
    #[ORM\OneToMany(targetEntity: EnginPhoto::class, mappedBy: 'entite')]
    private Collection $enginPhotoEntites;

    /**
     * @var Collection<int, FormateurObjectiveEvaluation>
     */
    #[ORM\OneToMany(targetEntity: FormateurObjectiveEvaluation::class, mappedBy: 'entite')]
    private Collection $formateurObjectiveEvalutaionEntites;

    /**
     * @var Collection<int, FormateurSatisfactionAssignment>
     */
    #[ORM\OneToMany(targetEntity: FormateurSatisfactionAssignment::class, mappedBy: 'entite')]
    private Collection $formateurSatisfactionAssignementEntites;

    /**
     * @var Collection<int, FormateurSatisfactionAttempt>
     */
    #[ORM\OneToMany(targetEntity: FormateurSatisfactionAttempt::class, mappedBy: 'entite')]
    private Collection $formateurSatisfactionAttemptEntites;

    /**
     * @var Collection<int, FormateurSatisfactionChapter>
     */
    #[ORM\OneToMany(targetEntity: FormateurSatisfactionChapter::class, mappedBy: 'entite')]
    private Collection $formateurSatisfactionChapterEntites;

    /**
     * @var Collection<int, FormateurSatisfactionQuestion>
     */
    #[ORM\OneToMany(targetEntity: FormateurSatisfactionQuestion::class, mappedBy: 'entite')]
    private Collection $formateurSatisfactionEntites;

    /**
     * @var Collection<int, FormationContentNode>
     */
    #[ORM\OneToMany(targetEntity: FormationContentNode::class, mappedBy: 'entite')]
    private Collection $formationContentNodeEntites;

    /**
     * @var Collection<int, FormationObjective>
     */
    #[ORM\OneToMany(targetEntity: FormationObjective::class, mappedBy: 'entite')]
    private Collection $formationObjectiveEntites;

    /**
     * @var Collection<int, FormationPhoto>
     */
    #[ORM\OneToMany(targetEntity: FormationPhoto::class, mappedBy: 'entite')]
    private Collection $formationPhotoEntites;

    /**
     * @var Collection<int, Inscription>
     */
    #[ORM\OneToMany(targetEntity: Inscription::class, mappedBy: 'entite')]
    private Collection $inscriptionEntites;

    /**
     * @var Collection<int, LigneDevis>
     */
    #[ORM\OneToMany(targetEntity: LigneDevis::class, mappedBy: 'entite')]
    private Collection $ligneDevisEntites;

    /**
     * @var Collection<int, LigneFacture>
     */
    #[ORM\OneToMany(targetEntity: LigneFacture::class, mappedBy: 'entite')]
    private Collection $lignefactureEntites;

    /**
     * @var Collection<int, Paiement>
     */
    #[ORM\OneToMany(targetEntity: Paiement::class, mappedBy: 'entite')]
    private Collection $paiementEntites;

    /**
     * @var Collection<int, PieceDossier>
     */
    #[ORM\OneToMany(targetEntity: PieceDossier::class, mappedBy: 'entite')]
    private Collection $pieceDossierEntites;

    /**
     * @var Collection<int, PositioningAnswer>
     */
    #[ORM\OneToMany(targetEntity: PositioningAnswer::class, mappedBy: 'entite')]
    private Collection $positioningAnswerEntites;

    /**
     * @var Collection<int, PositioningAssignment>
     */
    #[ORM\OneToMany(targetEntity: PositioningAssignment::class, mappedBy: 'entite')]
    private Collection $positioningAssignmentEntites;

    /**
     * @var Collection<int, PositioningAttempt>
     */
    #[ORM\OneToMany(targetEntity: PositioningAttempt::class, mappedBy: 'entite')]
    private Collection $positioningAttemptEntites;

    /**
     * @var Collection<int, PositioningChapter>
     */
    #[ORM\OneToMany(targetEntity: PositioningChapter::class, mappedBy: 'entite')]
    private Collection $positioningChapterEntites;

    /**
     * @var Collection<int, PositioningItem>
     */
    #[ORM\OneToMany(targetEntity: PositioningItem::class, mappedBy: 'entite')]
    private Collection $positioningItemEntites;

    /**
     * @var Collection<int, ProspectInteraction>
     */
    #[ORM\OneToMany(targetEntity: ProspectInteraction::class, mappedBy: 'entite')]
    private Collection $prospectInteractionEntites;

    /**
     * @var Collection<int, QcmAnswer>
     */
    #[ORM\OneToMany(targetEntity: QcmAnswer::class, mappedBy: 'entite')]
    private Collection $qcmAnswerEntites;

    /**
     * @var Collection<int, QcmAssignment>
     */
    #[ORM\OneToMany(targetEntity: QcmAssignment::class, mappedBy: 'entite')]
    private Collection $qcmAssignmentEntites;

    /**
     * @var Collection<int, QcmAttempt>
     */
    #[ORM\OneToMany(targetEntity: QcmAttempt::class, mappedBy: 'entite')]
    private Collection $qcmAttemptEntites;

    /**
     * @var Collection<int, QcmOption>
     */
    #[ORM\OneToMany(targetEntity: QcmOption::class, mappedBy: 'entite')]
    private Collection $qcmOptionEntites;


    /**
     * @var Collection<int, QcmQuestion>
     */
    #[ORM\OneToMany(targetEntity: QcmQuestion::class, mappedBy: 'entite')]
    private Collection $qcmQuestionEntites;

    /**
     * @var Collection<int, Quiz>
     */
    #[ORM\OneToMany(targetEntity: Quiz::class, mappedBy: 'entite')]
    private Collection $quizEntites;

    /**
     * @var Collection<int, QuizAnswer>
     */
    #[ORM\OneToMany(targetEntity: QuizAnswer::class, mappedBy: 'entite')]
    private Collection $quizAnswerEntites;

    /**
     * @var Collection<int, QuizAttempt>
     */
    #[ORM\OneToMany(targetEntity: QuizAttempt::class, mappedBy: 'entite')]
    private Collection $quizAttemptEntites;

    /**
     * @var Collection<int, QuizChoice>
     */
    #[ORM\OneToMany(targetEntity: QuizChoice::class, mappedBy: 'entite')]
    private Collection $quizChoiceEntites;

    /**
     * @var Collection<int, QuizQuestion>
     */
    #[ORM\OneToMany(targetEntity: QuizQuestion::class, mappedBy: 'entite')]
    private Collection $quizQuestionEntites;

    /**
     * @var Collection<int, RapportFormateur>
     */
    #[ORM\OneToMany(targetEntity: RapportFormateur::class, mappedBy: 'entite')]
    private Collection $rapportFormateurEntites;

    /**
     * @var Collection<int, Reservation>
     */
    #[ORM\OneToMany(targetEntity: Reservation::class, mappedBy: 'entite')]
    private Collection $reservationEntites;

    /**
     * @var Collection<int, SatisfactionAssignment>
     */
    #[ORM\OneToMany(targetEntity: SatisfactionAssignment::class, mappedBy: 'entite')]
    private Collection $satisfactionAssignmentEntites;

    /**
     * @var Collection<int, SatisfactionAttempt>
     */
    #[ORM\OneToMany(targetEntity: SatisfactionAttempt::class, mappedBy: 'entite')]
    private Collection $satisfactionAttemptEntites;

    /**
     * @var Collection<int, SatisfactionChapter>
     */
    #[ORM\OneToMany(targetEntity: SatisfactionChapter::class, mappedBy: 'entite')]
    private Collection $satisfactionChapterEntites;

    /**
     * @var Collection<int, SatisfactionQuestion>
     */
    #[ORM\OneToMany(targetEntity: SatisfactionQuestion::class, mappedBy: 'entite')]
    private Collection $satisfactionQuestionEntites;

    /**
     * @var Collection<int, SessionJour>
     */
    #[ORM\OneToMany(targetEntity: SessionJour::class, mappedBy: 'entite')]
    private Collection $sessionJourEntites;

    /**
     * @var Collection<int, SessionPositioning>
     */
    #[ORM\OneToMany(targetEntity: SessionPositioning::class, mappedBy: 'entite')]
    private Collection $sessionPositioningEntites;

    /**
     * @var Collection<int, SupportAssignSession>
     */
    #[ORM\OneToMany(targetEntity: SupportAssignSession::class, mappedBy: 'entite')]
    private Collection $supportAssignSessionEntites;

    /**
     * @var Collection<int, SupportAssignUser>
     */
    #[ORM\OneToMany(targetEntity: SupportAssignUser::class, mappedBy: 'entite')]
    private Collection $supportAssignUserEntites;

    /**
     * @var Collection<int, SupportDocument>
     */
    #[ORM\OneToMany(targetEntity: SupportDocument::class, mappedBy: 'entite')]
    private Collection $supportDocumentEntites;

    /**
     * @var Collection<int, DepenseCategorie>
     */
    #[ORM\OneToMany(targetEntity: DepenseCategorie::class, mappedBy: 'entite')]
    private Collection $depenseCategories;

    /**
     * @var Collection<int, DepenseFournisseur>
     */
    #[ORM\OneToMany(targetEntity: DepenseFournisseur::class, mappedBy: 'entite')]
    private Collection $depenseFournisseurs;

    /**
     * @var Collection<int, FiscalProfile>
     */
    #[ORM\OneToMany(targetEntity: FiscalProfile::class, mappedBy: 'entite')]
    private Collection $fiscalProfiles;

    /**
     * @var Collection<int, TaxRule>
     */
    #[ORM\OneToMany(targetEntity: TaxRule::class, mappedBy: 'entite')]
    private Collection $taxRules;

    /**
     * @var Collection<int, TaxComputation>
     */
    #[ORM\OneToMany(targetEntity: TaxComputation::class, mappedBy: 'entite')]
    private Collection $taxComputations;

    /**
     * @var Collection<int, ElearningCourse>
     */
    #[ORM\OneToMany(targetEntity: ElearningCourse::class, mappedBy: 'entite')]
    private Collection $elearningCourses;

    /**
     * @var Collection<int, ElearningNode>
     */
    #[ORM\OneToMany(targetEntity: ElearningNode::class, mappedBy: 'entite')]
    private Collection $elearningNodes;

    /**
     * @var Collection<int, ElearningBlock>
     */
    #[ORM\OneToMany(targetEntity: ElearningBlock::class, mappedBy: 'entite')]
    private Collection $elearningBlocks;

    /**
     * @var Collection<int, ElearningEnrollment>
     */
    #[ORM\OneToMany(targetEntity: ElearningEnrollment::class, mappedBy: 'entite')]
    private Collection $elearningEnrollments;

    /**
     * @var Collection<int, ElearningOrder>
     */
    #[ORM\OneToMany(targetEntity: ElearningOrder::class, mappedBy: 'entite')]
    private Collection $elearningOrders;

    /**
     * @var Collection<int, EntrepriseDocument>
     */
    #[ORM\OneToMany(targetEntity: EntrepriseDocument::class, mappedBy: 'entite')]
    private Collection $entrepriseDocuments;

    /**
     * @var Collection<int, EntiteSubscription>
     */
    #[ORM\OneToMany(targetEntity: EntiteSubscription::class, mappedBy: 'entite')]
    private Collection $entiteSubscriptions;

    /**
     * @var Collection<int, EntiteUsageYear>
     */
    #[ORM\OneToMany(targetEntity: EntiteUsageYear::class, mappedBy: 'entite')]
    private Collection $entiteUsageYears;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $slug = null;

    #[ORM\Column(nullable: true)]
    private ?bool $isActive = null;

    /**
     * @var Collection<int, PublicHost>
     */
    #[ORM\OneToMany(targetEntity: PublicHost::class, mappedBy: 'entite')]
    private Collection $publicHosts;





    public function __construct()
    {
        $this->utilisateurEntites = new ArrayCollection();
        $this->responsables = new ArrayCollection();
        $this->supportAssets = new ArrayCollection();
        $this->attestations = new ArrayCollection();
        $this->factures = new ArrayCollection();
        $this->avoirs = new ArrayCollection();
        $this->conventionContrats = new ArrayCollection();
        $this->sites = new ArrayCollection();
        $this->sessions = new ArrayCollection();
        $this->contratFormateurs = new ArrayCollection();
        $this->formateurs = new ArrayCollection();
        $this->devis = new ArrayCollection();
        $this->devisSequences = new ArrayCollection();
        $this->entreprises = new ArrayCollection();
        $this->contratStagiaires = new ArrayCollection();
        $this->engins = new ArrayCollection();
        $this->formations = new ArrayCollection();
        $this->positioningQuestionnaires = new ArrayCollection();
        $this->satisfactionTemplates = new ArrayCollection();
        $this->formateurSatisfactionTemplates = new ArrayCollection();
        $this->prospects = new ArrayCollection();
        $this->emailTemplates = new ArrayCollection();
        $this->emailLogs = new ArrayCollection();
        $this->categories = new ArrayCollection();
        $this->qcms = new ArrayCollection();
        $this->dateCreation = new \DateTimeImmutable();
        $this->adutiLogEntites = new ArrayCollection();
        $this->contentBlockEntites = new ArrayCollection();
        $this->dossierInscriptionEntites = new ArrayCollection();
        $this->emargementEntites = new ArrayCollection();
        $this->enginPhotoEntites = new ArrayCollection();
        $this->formateurObjectiveEvalutaionEntites = new ArrayCollection();
        $this->formateurSatisfactionAssignementEntites = new ArrayCollection();
        $this->formateurSatisfactionAttemptEntites = new ArrayCollection();
        $this->formateurSatisfactionChapterEntites = new ArrayCollection();
        $this->formateurSatisfactionEntites = new ArrayCollection();
        $this->formationContentNodeEntites = new ArrayCollection();
        $this->formationObjectiveEntites = new ArrayCollection();
        $this->formationPhotoEntites = new ArrayCollection();
        $this->inscriptionEntites = new ArrayCollection();
        $this->ligneDevisEntites = new ArrayCollection();
        $this->lignefactureEntites = new ArrayCollection();
        $this->paiementEntites = new ArrayCollection();
        $this->pieceDossierEntites = new ArrayCollection();
        $this->positioningAnswerEntites = new ArrayCollection();
        $this->positioningAssignmentEntites = new ArrayCollection();
        $this->positioningAttemptEntites = new ArrayCollection();
        $this->positioningChapterEntites = new ArrayCollection();
        $this->positioningItemEntites = new ArrayCollection();
        $this->prospectInteractionEntites = new ArrayCollection();
        $this->qcmAnswerEntites = new ArrayCollection();
        $this->qcmAssignmentEntites = new ArrayCollection();
        $this->qcmAttemptEntites = new ArrayCollection();
        $this->qcmOptionEntites = new ArrayCollection();
        $this->qcmQuestionEntites = new ArrayCollection();
        $this->quizEntites = new ArrayCollection();
        $this->quizAnswerEntites = new ArrayCollection();
        $this->quizAttemptEntites = new ArrayCollection();
        $this->quizChoiceEntites = new ArrayCollection();
        $this->quizQuestionEntites = new ArrayCollection();
        $this->rapportFormateurEntites = new ArrayCollection();
        $this->reservationEntites = new ArrayCollection();
        $this->satisfactionAssignmentEntites = new ArrayCollection();
        $this->satisfactionAttemptEntites = new ArrayCollection();
        $this->satisfactionChapterEntites = new ArrayCollection();
        $this->satisfactionQuestionEntites = new ArrayCollection();
        $this->sessionJourEntites = new ArrayCollection();
        $this->sessionPositioningEntites = new ArrayCollection();
        $this->supportAssignSessionEntites = new ArrayCollection();
        $this->supportAssignUserEntites = new ArrayCollection();
        $this->supportDocumentEntites = new ArrayCollection();
        $this->depenseCategories = new ArrayCollection();
        $this->depenseFournisseurs = new ArrayCollection();
        $this->fiscalProfiles = new ArrayCollection();
        $this->taxRules = new ArrayCollection();
        $this->taxComputations = new ArrayCollection();
        $this->elearningCourses = new ArrayCollection();
        $this->elearningNodes = new ArrayCollection();
        $this->elearningBlocks = new ArrayCollection();
        $this->elearningEnrollments = new ArrayCollection();
        $this->elearningOrders = new ArrayCollection();
        $this->entrepriseDocuments = new ArrayCollection();
        $this->entiteSubscriptions = new ArrayCollection();
        $this->entiteUsageYears = new ArrayCollection();
        $this->publicHosts = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCouleurPrincipal(): ?string
    {
        return $this->couleurPrincipal;
    }

    public function setCouleurPrincipal(?string $couleurPrincipal): static
    {
        $this->couleurPrincipal = $couleurPrincipal;

        return $this;
    }

    public function getCouleurSecondaire(): ?string
    {
        return $this->couleurSecondaire;
    }

    public function setCouleurSecondaire(?string $couleurSecondaire): static
    {
        $this->couleurSecondaire = $couleurSecondaire;

        return $this;
    }

    public function getCouleurTertiaire(): ?string
    {
        return $this->couleurTertiaire;
    }

    public function setCouleurTertiaire(?string $couleurTertiaire): static
    {
        $this->couleurTertiaire = $couleurTertiaire;

        return $this;
    }

    public function getCouleurQuaternaire(): ?string
    {
        return $this->couleurQuaternaire;
    }

    public function setCouleurQuaternaire(?string $couleurQuaternaire): static
    {
        $this->couleurQuaternaire = $couleurQuaternaire;

        return $this;
    }

    public function getLogo(): ?string
    {
        return $this->logo;
    }

    public function setLogo(?string $logo): static
    {
        $this->logo = $logo;

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

    public function getDateCreation(): ?\DateTimeImmutable
    {
        return $this->dateCreation;
    }

    public function setDateCreation(\DateTimeImmutable $dateCreation): static
    {
        $this->dateCreation = $dateCreation;

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

    public function getDepartement(): ?string
    {
        return $this->departement;
    }

    public function setDepartement(?string $departement): static
    {
        $this->departement = $departement;

        return $this;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(?string $telephone): static
    {
        $this->telephone = $telephone;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getTexteAccueil(): ?string
    {
        return $this->texteAccueil;
    }

    public function setTexteAccueil(?string $texteAccueil): static
    {
        $this->texteAccueil = $texteAccueil;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getLogoMenu(): ?string
    {
        return $this->logoMenu;
    }

    public function setLogoMenu(?string $logoMenu): static
    {
        $this->logoMenu = $logoMenu;

        return $this;
    }

    /**
     * @return Collection<int, UtilisateurEntite>
     */
    public function getUtilisateurEntites(): Collection
    {
        return $this->utilisateurEntites;
    }

    public function addUtilisateurEntite(UtilisateurEntite $utilisateurEntite): static
    {
        if (!$this->utilisateurEntites->contains($utilisateurEntite)) {
            $this->utilisateurEntites->add($utilisateurEntite);
            $utilisateurEntite->setEntite($this);
        }

        return $this;
    }

    public function removeUtilisateurEntite(UtilisateurEntite $utilisateurEntite): static
    {
        if ($this->utilisateurEntites->removeElement($utilisateurEntite)) {
            // set the owning side to null (unless already changed)
            if ($utilisateurEntite->getEntite() === $this) {
                $utilisateurEntite->setEntite(null);
            }
        }

        return $this;
    }

    public function isPublic(): ?bool
    {
        return $this->public;
    }

    public function setPublic(bool $public): static
    {
        $this->public = $public;

        return $this;
    }

    /**
     * @return Collection<int, Utilisateur>
     */
    public function getResponsables(): Collection
    {
        return $this->responsables;
    }

    public function addResponsable(Utilisateur $responsable): static
    {
        if (!$this->responsables->contains($responsable)) {
            $this->responsables->add($responsable);
            $responsable->setEntite($this);
        }

        return $this;
    }

    public function removeResponsable(Utilisateur $responsable): static
    {
        if ($this->responsables->removeElement($responsable)) {
            // set the owning side to null (unless already changed)
            if ($responsable->getEntite() === $this) {
                $responsable->setEntite(null);
            }
        }

        return $this;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(?string $nom): static
    {
        $this->nom = $nom;

        return $this;
    }

    /**
     * @return Collection<int, SupportAsset>
     */
    public function getSupportAssets(): Collection
    {
        return $this->supportAssets;
    }

    public function addSupportAsset(SupportAsset $supportAsset): static
    {
        if (!$this->supportAssets->contains($supportAsset)) {
            $this->supportAssets->add($supportAsset);
            $supportAsset->setEntite($this);
        }

        return $this;
    }

    public function removeSupportAsset(SupportAsset $supportAsset): static
    {
        if ($this->supportAssets->removeElement($supportAsset)) {
            // set the owning side to null (unless already changed)
            if ($supportAsset->getEntite() === $this) {
                $supportAsset->setEntite(null);
            }
        }

        return $this;
    }

    public function getSiret(): ?string
    {
        return $this->siret;
    }

    public function setSiret(?string $siret): static
    {
        $this->siret = $siret;

        return $this;
    }

    /**
     * @return Collection<int, Attestation>
     */
    public function getAttestations(): Collection
    {
        return $this->attestations;
    }

    public function addAttestation(Attestation $attestation): static
    {
        if (!$this->attestations->contains($attestation)) {
            $this->attestations->add($attestation);
            $attestation->setEntite($this);
        }

        return $this;
    }

    public function removeAttestation(Attestation $attestation): static
    {
        if ($this->attestations->removeElement($attestation)) {
            // set the owning side to null (unless already changed)
            if ($attestation->getEntite() === $this) {
                $attestation->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Facture>
     */
    public function getFactures(): Collection
    {
        return $this->factures;
    }

    public function addFacture(Facture $facture): static
    {
        if (!$this->factures->contains($facture)) {
            $this->factures->add($facture);
            $facture->setEntite($this);
        }

        return $this;
    }

    public function removeFacture(Facture $facture): static
    {
        if ($this->factures->removeElement($facture)) {
            // set the owning side to null (unless already changed)
            if ($facture->getEntite() === $this) {
                $facture->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Avoir>
     */
    public function getAvoirs(): Collection
    {
        return $this->avoirs;
    }

    public function addAvoir(Avoir $avoir): static
    {
        if (!$this->avoirs->contains($avoir)) {
            $this->avoirs->add($avoir);
            $avoir->setEntite($this);
        }

        return $this;
    }

    public function removeAvoir(Avoir $avoir): static
    {
        if ($this->avoirs->removeElement($avoir)) {
            // set the owning side to null (unless already changed)
            if ($avoir->getEntite() === $this) {
                $avoir->setEntite(null);
            }
        }

        return $this;
    }

    public function getIban(): ?string
    {
        return $this->iban;
    }

    public function setIban(?string $iban): static
    {
        $this->iban = $iban;

        return $this;
    }

    public function getBanque(): ?string
    {
        return $this->banque;
    }

    public function setBanque(?string $banque): static
    {
        $this->banque = $banque;

        return $this;
    }

    public function getBic(): ?string
    {
        return $this->bic;
    }

    public function setBic(?string $bic): static
    {
        $this->bic = $bic;

        return $this;
    }

    public function getNumeroTva(): ?string
    {
        return $this->numeroTva;
    }

    public function setNumeroTva(?string $numeroTva): static
    {
        $this->numeroTva = $numeroTva;

        return $this;
    }

    public function getNumeroCompte(): ?string
    {
        return $this->numeroCompte;
    }

    public function setNumeroCompte(?string $numeroCompte): static
    {
        $this->numeroCompte = $numeroCompte;

        return $this;
    }

    public function getCodeBanque(): ?string
    {
        return $this->codeBanque;
    }

    public function setCodeBanque(?string $codeBanque): static
    {
        $this->codeBanque = $codeBanque;

        return $this;
    }

    public function getNumeroDeclarant(): ?string
    {
        return $this->numeroDeclarant;
    }

    public function setNumeroDeclarant(?string $numeroDeclarant): static
    {
        $this->numeroDeclarant = $numeroDeclarant;

        return $this;
    }

    /**
     * @return Collection<int, ConventionContrat>
     */
    public function getConventionContrats(): Collection
    {
        return $this->conventionContrats;
    }

    public function addConventionContrat(ConventionContrat $conventionContrat): static
    {
        if (!$this->conventionContrats->contains($conventionContrat)) {
            $this->conventionContrats->add($conventionContrat);
            $conventionContrat->setEntite($this);
        }

        return $this;
    }

    public function removeConventionContrat(ConventionContrat $conventionContrat): static
    {
        if ($this->conventionContrats->removeElement($conventionContrat)) {
            // set the owning side to null (unless already changed)
            if ($conventionContrat->getEntite() === $this) {
                $conventionContrat->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Site>
     */
    public function getSites(): Collection
    {
        return $this->sites;
    }

    public function addSite(Site $site): static
    {
        if (!$this->sites->contains($site)) {
            $this->sites->add($site);
            $site->setEntite($this);
        }

        return $this;
    }

    public function removeSite(Site $site): static
    {
        if ($this->sites->removeElement($site)) {
            // set the owning side to null (unless already changed)
            /*if ($site->getEntite() === $this) {
                $site->setEntite(null);
            }*/
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
            $session->setEntite($this);
        }

        return $this;
    }

    public function removeSession(Session $session): static
    {
        if ($this->sessions->removeElement($session)) {
            // set the owning side to null (unless already changed)
            if ($session->getEntite() === $this) {
                $session->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ContratFormateur>
     */
    public function getContratFormateurs(): Collection
    {
        return $this->contratFormateurs;
    }

    public function addContratFormateur(ContratFormateur $contratFormateur): static
    {
        if (!$this->contratFormateurs->contains($contratFormateur)) {
            $this->contratFormateurs->add($contratFormateur);
            $contratFormateur->setEntite($this);
        }

        return $this;
    }

    public function removeContratFormateur(ContratFormateur $contratFormateur): static
    {
        if ($this->contratFormateurs->removeElement($contratFormateur)) {
            // set the owning side to null (unless already changed)
            if ($contratFormateur->getEntite() === $this) {
                $contratFormateur->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Formateur>
     */
    public function getFormateurs(): Collection
    {
        return $this->formateurs;
    }

    public function addFormateur(Formateur $formateur): static
    {
        if (!$this->formateurs->contains($formateur)) {
            $this->formateurs->add($formateur);
            $formateur->setEntite($this);
        }

        return $this;
    }

    public function removeFormateur(Formateur $formateur): static
    {
        if ($this->formateurs->removeElement($formateur)) {
            // set the owning side to null (unless already changed)
            if ($formateur->getEntite() === $this) {
                $formateur->setEntite(null);
            }
        }

        return $this;
    }

    public function getFormeJuridique(): ?string
    {
        return $this->formeJuridique;
    }

    public function setFormeJuridique(?string $formeJuridique): static
    {
        $this->formeJuridique = $formeJuridique;

        return $this;
    }

    public function getFonction(): ?string
    {
        return $this->fonction;
    }

    public function setFonction(?string $fonction): static
    {
        $this->fonction = $fonction;

        return $this;
    }

    public function getNomRepresentant(): ?string
    {
        return $this->nomRepresentant;
    }

    public function setNomRepresentant(?string $nomRepresentant): static
    {
        $this->nomRepresentant = $nomRepresentant;

        return $this;
    }

    public function getPrenomRepresentant(): ?string
    {
        return $this->prenomRepresentant;
    }

    public function setPrenomRepresentant(?string $prenomRepresentant): static
    {
        $this->prenomRepresentant = $prenomRepresentant;

        return $this;
    }

    /**
     * @return Collection<int, Devis>
     */
    public function getDevis(): Collection
    {
        return $this->devis;
    }

    public function addDevi(Devis $devi): static
    {
        if (!$this->devis->contains($devi)) {
            $this->devis->add($devi);
            $devi->setEntite($this);
        }

        return $this;
    }

    public function removeDevi(Devis $devi): static
    {
        if ($this->devis->removeElement($devi)) {
            // set the owning side to null (unless already changed)
            if ($devi->getEntite() === $this) {
                $devi->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, DevisSequence>
     */
    public function getDevisSequences(): Collection
    {
        return $this->devisSequences;
    }

    public function addDevisSequence(DevisSequence $devisSequence): static
    {
        if (!$this->devisSequences->contains($devisSequence)) {
            $this->devisSequences->add($devisSequence);
            $devisSequence->setEntite($this);
        }

        return $this;
    }

    public function removeDevisSequence(DevisSequence $devisSequence): static
    {
        if ($this->devisSequences->removeElement($devisSequence)) {
            // set the owning side to null (unless already changed)
            if ($devisSequence->getEntite() === $this) {
                $devisSequence->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Entreprise>
     */
    public function getEntreprises(): Collection
    {
        return $this->entreprises;
    }

    public function addEntreprise(Entreprise $entreprise): static
    {
        if (!$this->entreprises->contains($entreprise)) {
            $this->entreprises->add($entreprise);
            $entreprise->setEntite($this);
        }

        return $this;
    }

    public function removeEntreprise(Entreprise $entreprise): static
    {
        if ($this->entreprises->removeElement($entreprise)) {
            // set the owning side to null (unless already changed)
            if ($entreprise->getEntite() === $this) {
                $entreprise->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ContratStagiaire>
     */
    public function getContratStagiaires(): Collection
    {
        return $this->contratStagiaires;
    }

    public function addContratStagiaire(ContratStagiaire $contratStagiaire): static
    {
        if (!$this->contratStagiaires->contains($contratStagiaire)) {
            $this->contratStagiaires->add($contratStagiaire);
            $contratStagiaire->setEntite($this);
        }

        return $this;
    }

    public function removeContratStagiaire(ContratStagiaire $contratStagiaire): static
    {
        if ($this->contratStagiaires->removeElement($contratStagiaire)) {
            // set the owning side to null (unless already changed)
            if ($contratStagiaire->getEntite() === $this) {
                $contratStagiaire->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Engin>
     */
    public function getEngins(): Collection
    {
        return $this->engins;
    }

    public function addEngin(Engin $engin): static
    {
        if (!$this->engins->contains($engin)) {
            $this->engins->add($engin);
            $engin->setEntite($this);
        }

        return $this;
    }

    public function removeEngin(Engin $engin): static
    {
        if ($this->engins->removeElement($engin)) {
            // set the owning side to null (unless already changed)
            if ($engin->getEntite() === $this) {
                $engin->setEntite(null);
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
            $formation->setEntite($this);
        }

        return $this;
    }

    public function removeFormation(Formation $formation): static
    {
        if ($this->formations->removeElement($formation)) {
            // set the owning side to null (unless already changed)
            if ($formation->getEntite() === $this) {
                $formation->setEntite(null);
            }
        }

        return $this;
    }

    public function getPreferences(): ?EntitePreferences
    {
        return $this->preferences;
    }

    public function setPreferences(EntitePreferences $preferences): static
    {
        // set the owning side of the relation if necessary
        if ($preferences->getEntite() !== $this) {
            $preferences->setEntite($this);
        }

        $this->preferences = $preferences;

        return $this;
    }

    /**
     * @return Collection<int, PositioningQuestionnaire>
     */
    public function getPositioningQuestionnaires(): Collection
    {
        return $this->positioningQuestionnaires;
    }

    public function addPositioningQuestionnaire(PositioningQuestionnaire $positioningQuestionnaire): static
    {
        if (!$this->positioningQuestionnaires->contains($positioningQuestionnaire)) {
            $this->positioningQuestionnaires->add($positioningQuestionnaire);
            $positioningQuestionnaire->setEntite($this);
        }

        return $this;
    }

    public function removePositioningQuestionnaire(PositioningQuestionnaire $positioningQuestionnaire): static
    {
        if ($this->positioningQuestionnaires->removeElement($positioningQuestionnaire)) {
            // set the owning side to null (unless already changed)
            if ($positioningQuestionnaire->getEntite() === $this) {
                $positioningQuestionnaire->setEntite(null);
            }
        }

        return $this;
    }


    /**
     * @return Collection<int, SatisfactionTemplate>
     */
    public function getSatisfactionTemplates(): Collection
    {
        return $this->satisfactionTemplates;
    }

    public function addSatisfactionTemplates(SatisfactionTemplate $satisfactionTemplates): static
    {
        if (!$this->satisfactionTemplates->contains($satisfactionTemplates)) {
            $this->satisfactionTemplates->add($satisfactionTemplates);
            $satisfactionTemplates->setEntite($this);
        }

        return $this;
    }

    public function removeSatisfactionTemplates(SatisfactionTemplate $satisfactionTemplates): static
    {
        if ($this->satisfactionTemplates->removeElement($satisfactionTemplates)) {
            // set the owning side to null (unless already changed)
            if ($satisfactionTemplates->getEntite() === $this) {
                $satisfactionTemplates->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, FormateurSatisfactionTemplate>
     */
    public function getFormateurSatisfactionTemplates(): Collection
    {
        return $this->formateurSatisfactionTemplates;
    }

    public function addFormateurSatisfactionTemplate(FormateurSatisfactionTemplate $formateurSatisfactionTemplate): static
    {
        if (!$this->formateurSatisfactionTemplates->contains($formateurSatisfactionTemplate)) {
            $this->formateurSatisfactionTemplates->add($formateurSatisfactionTemplate);
            $formateurSatisfactionTemplate->setEntite($this);
        }

        return $this;
    }

    public function removeFormateurSatisfactionTemplate(FormateurSatisfactionTemplate $formateurSatisfactionTemplate): static
    {
        if ($this->formateurSatisfactionTemplates->removeElement($formateurSatisfactionTemplate)) {
            // set the owning side to null (unless already changed)
            if ($formateurSatisfactionTemplate->getEntite() === $this) {
                $formateurSatisfactionTemplate->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Prospect>
     */
    public function getProspects(): Collection
    {
        return $this->prospects;
    }

    public function addProspect(Prospect $prospect): static
    {
        if (!$this->prospects->contains($prospect)) {
            $this->prospects->add($prospect);
            $prospect->setEntite($this);
        }

        return $this;
    }

    public function removeProspect(Prospect $prospect): static
    {
        if ($this->prospects->removeElement($prospect)) {
            // set the owning side to null (unless already changed)
            if ($prospect->getEntite() === $this) {
                $prospect->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, EmailTemplate>
     */
    public function getEmailTemplates(): Collection
    {
        return $this->emailTemplates;
    }

    public function addEmailTemplate(EmailTemplate $emailTemplate): static
    {
        if (!$this->emailTemplates->contains($emailTemplate)) {
            $this->emailTemplates->add($emailTemplate);
            $emailTemplate->setEntite($this);
        }

        return $this;
    }

    public function removeEmailTemplate(EmailTemplate $emailTemplate): static
    {
        if ($this->emailTemplates->removeElement($emailTemplate)) {
            // set the owning side to null (unless already changed)
            if ($emailTemplate->getEntite() === $this) {
                $emailTemplate->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, EmailLog>
     */
    public function getEmailLogs(): Collection
    {
        return $this->emailLogs;
    }

    public function addEmailLog(EmailLog $emailLog): static
    {
        if (!$this->emailLogs->contains($emailLog)) {
            $this->emailLogs->add($emailLog);
            $emailLog->setEntite($this);
        }

        return $this;
    }

    public function removeEmailLog(EmailLog $emailLog): static
    {
        if ($this->emailLogs->removeElement($emailLog)) {
            // set the owning side to null (unless already changed)
            if ($emailLog->getEntite() === $this) {
                $emailLog->setEntite(null);
            }
        }

        return $this;
    }


    /** @return Collection<int, Categorie> */
    public function getCategories(): Collection
    {
        return $this->categories;
    }


    public function addCategorie(Categorie $categorie): static
    {
        if (!$this->categories->contains($categorie)) {
            $this->categories->add($categorie);
            $categorie->setEntite($this);
        }

        return $this;
    }

    public function removeCategorie(Categorie $categorie): static
    {
        if ($this->categories->removeElement($categorie)) {
            // set the owning side to null (unless already changed)
            if ($categorie->getEntite() === $this) {
                $categorie->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Qcm>
     */
    public function getQcms(): Collection
    {
        return $this->qcms;
    }

    public function addQcm(Qcm $qcm): static
    {
        if (!$this->qcms->contains($qcm)) {
            $this->qcms->add($qcm);
            $qcm->setEntite($this);
        }

        return $this;
    }

    public function removeQcm(Qcm $qcm): static
    {
        if ($this->qcms->removeElement($qcm)) {
            // set the owning side to null (unless already changed)
            if ($qcm->getEntite() === $this) {
                $qcm->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, AuditLog>
     */
    public function getAdutiLogEntites(): Collection
    {
        return $this->adutiLogEntites;
    }

    public function addAdutiLogEntite(AuditLog $adutiLogEntite): static
    {
        if (!$this->adutiLogEntites->contains($adutiLogEntite)) {
            $this->adutiLogEntites->add($adutiLogEntite);
            $adutiLogEntite->setEntite($this);
        }

        return $this;
    }

    public function removeAdutiLogEntite(AuditLog $adutiLogEntite): static
    {
        if ($this->adutiLogEntites->removeElement($adutiLogEntite)) {
            // set the owning side to null (unless already changed)
            if ($adutiLogEntite->getEntite() === $this) {
                $adutiLogEntite->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ContentBlock>
     */
    public function getContentBlockEntites(): Collection
    {
        return $this->contentBlockEntites;
    }

    public function addContentBlockEntite(ContentBlock $contentBlockEntite): static
    {
        if (!$this->contentBlockEntites->contains($contentBlockEntite)) {
            $this->contentBlockEntites->add($contentBlockEntite);
            $contentBlockEntite->setEntite($this);
        }

        return $this;
    }

    public function removeContentBlockEntite(ContentBlock $contentBlockEntite): static
    {
        if ($this->contentBlockEntites->removeElement($contentBlockEntite)) {
            // set the owning side to null (unless already changed)
            if ($contentBlockEntite->getEntite() === $this) {
                $contentBlockEntite->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, DossierInscription>
     */
    public function getDossierInscriptionEntites(): Collection
    {
        return $this->dossierInscriptionEntites;
    }

    public function addDossierInscriptionEntite(DossierInscription $dossierInscriptionEntite): static
    {
        if (!$this->dossierInscriptionEntites->contains($dossierInscriptionEntite)) {
            $this->dossierInscriptionEntites->add($dossierInscriptionEntite);
            $dossierInscriptionEntite->setEntite($this);
        }

        return $this;
    }

    public function removeDossierInscriptionEntite(DossierInscription $dossierInscriptionEntite): static
    {
        if ($this->dossierInscriptionEntites->removeElement($dossierInscriptionEntite)) {
            // set the owning side to null (unless already changed)
            if ($dossierInscriptionEntite->getEntite() === $this) {
                $dossierInscriptionEntite->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Emargement>
     */
    public function getEmargementEntites(): Collection
    {
        return $this->emargementEntites;
    }

    public function addEmargementEntite(Emargement $emargementEntite): static
    {
        if (!$this->emargementEntites->contains($emargementEntite)) {
            $this->emargementEntites->add($emargementEntite);
            $emargementEntite->setEntite($this);
        }

        return $this;
    }

    public function removeEmargementEntite(Emargement $emargementEntite): static
    {
        if ($this->emargementEntites->removeElement($emargementEntite)) {
            // set the owning side to null (unless already changed)
            if ($emargementEntite->getEntite() === $this) {
                $emargementEntite->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, EnginPhoto>
     */
    public function getEnginPhotoEntites(): Collection
    {
        return $this->enginPhotoEntites;
    }

    public function addEnginPhotoEntite(EnginPhoto $enginPhotoEntite): static
    {
        if (!$this->enginPhotoEntites->contains($enginPhotoEntite)) {
            $this->enginPhotoEntites->add($enginPhotoEntite);
            $enginPhotoEntite->setEntite($this);
        }

        return $this;
    }

    public function removeEnginPhotoEntite(EnginPhoto $enginPhotoEntite): static
    {
        if ($this->enginPhotoEntites->removeElement($enginPhotoEntite)) {
            // set the owning side to null (unless already changed)
            if ($enginPhotoEntite->getEntite() === $this) {
                $enginPhotoEntite->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, FormateurObjectiveEvaluation>
     */
    public function getFormateurObjectiveEvalutaionEntites(): Collection
    {
        return $this->formateurObjectiveEvalutaionEntites;
    }

    public function addFormateurObjectiveEvalutaionEntite(FormateurObjectiveEvaluation $formateurObjectiveEvalutaionEntite): static
    {
        if (!$this->formateurObjectiveEvalutaionEntites->contains($formateurObjectiveEvalutaionEntite)) {
            $this->formateurObjectiveEvalutaionEntites->add($formateurObjectiveEvalutaionEntite);
            $formateurObjectiveEvalutaionEntite->setEntite($this);
        }

        return $this;
    }

    public function removeFormateurObjectiveEvalutaionEntite(FormateurObjectiveEvaluation $formateurObjectiveEvalutaionEntite): static
    {
        if ($this->formateurObjectiveEvalutaionEntites->removeElement($formateurObjectiveEvalutaionEntite)) {
            // set the owning side to null (unless already changed)
            if ($formateurObjectiveEvalutaionEntite->getEntite() === $this) {
                $formateurObjectiveEvalutaionEntite->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, FormateurSatisfactionAssignment>
     */
    public function getFormateurSatisfactionAssignementEntites(): Collection
    {
        return $this->formateurSatisfactionAssignementEntites;
    }

    public function addFormateurSatisfactionAssignementEntite(FormateurSatisfactionAssignment $formateurSatisfactionAssignementEntite): static
    {
        if (!$this->formateurSatisfactionAssignementEntites->contains($formateurSatisfactionAssignementEntite)) {
            $this->formateurSatisfactionAssignementEntites->add($formateurSatisfactionAssignementEntite);
            $formateurSatisfactionAssignementEntite->setEntite($this);
        }

        return $this;
    }

    public function removeFormateurSatisfactionAssignementEntite(FormateurSatisfactionAssignment $formateurSatisfactionAssignementEntite): static
    {
        if ($this->formateurSatisfactionAssignementEntites->removeElement($formateurSatisfactionAssignementEntite)) {
            // set the owning side to null (unless already changed)
            if ($formateurSatisfactionAssignementEntite->getEntite() === $this) {
                $formateurSatisfactionAssignementEntite->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, FormateurSatisfactionAttempt>
     */
    public function getFormateurSatisfactionAttemptEntites(): Collection
    {
        return $this->formateurSatisfactionAttemptEntites;
    }

    public function addFormateurSatisfactionAttemptEntite(FormateurSatisfactionAttempt $formateurSatisfactionAttemptEntite): static
    {
        if (!$this->formateurSatisfactionAttemptEntites->contains($formateurSatisfactionAttemptEntite)) {
            $this->formateurSatisfactionAttemptEntites->add($formateurSatisfactionAttemptEntite);
            $formateurSatisfactionAttemptEntite->setEntite($this);
        }

        return $this;
    }

    public function removeFormateurSatisfactionAttemptEntite(FormateurSatisfactionAttempt $formateurSatisfactionAttemptEntite): static
    {
        if ($this->formateurSatisfactionAttemptEntites->removeElement($formateurSatisfactionAttemptEntite)) {
            // set the owning side to null (unless already changed)
            if ($formateurSatisfactionAttemptEntite->getEntite() === $this) {
                $formateurSatisfactionAttemptEntite->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, FormateurSatisfactionChapter>
     */
    public function getFormateurSatisfactionChapterEntites(): Collection
    {
        return $this->formateurSatisfactionChapterEntites;
    }

    public function addFormateurSatisfactionChapterEntite(FormateurSatisfactionChapter $formateurSatisfactionChapterEntite): static
    {
        if (!$this->formateurSatisfactionChapterEntites->contains($formateurSatisfactionChapterEntite)) {
            $this->formateurSatisfactionChapterEntites->add($formateurSatisfactionChapterEntite);
            $formateurSatisfactionChapterEntite->setEntite($this);
        }

        return $this;
    }

    public function removeFormateurSatisfactionChapterEntite(FormateurSatisfactionChapter $formateurSatisfactionChapterEntite): static
    {
        if ($this->formateurSatisfactionChapterEntites->removeElement($formateurSatisfactionChapterEntite)) {
            // set the owning side to null (unless already changed)
            if ($formateurSatisfactionChapterEntite->getEntite() === $this) {
                $formateurSatisfactionChapterEntite->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, FormateurSatisfactionQuestion>
     */
    public function getFormateurSatisfactionEntites(): Collection
    {
        return $this->formateurSatisfactionEntites;
    }

    public function addFormateurSatisfactionEntite(FormateurSatisfactionQuestion $formateurSatisfactionEntite): static
    {
        if (!$this->formateurSatisfactionEntites->contains($formateurSatisfactionEntite)) {
            $this->formateurSatisfactionEntites->add($formateurSatisfactionEntite);
            $formateurSatisfactionEntite->setEntite($this);
        }

        return $this;
    }

    public function removeFormateurSatisfactionEntite(FormateurSatisfactionQuestion $formateurSatisfactionEntite): static
    {
        if ($this->formateurSatisfactionEntites->removeElement($formateurSatisfactionEntite)) {
            // set the owning side to null (unless already changed)
            if ($formateurSatisfactionEntite->getEntite() === $this) {
                $formateurSatisfactionEntite->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, FormationContentNode>
     */
    public function getFormationContentNodeEntites(): Collection
    {
        return $this->formationContentNodeEntites;
    }

    public function addFormationContentNodeEntite(FormationContentNode $formationContentNodeEntite): static
    {
        if (!$this->formationContentNodeEntites->contains($formationContentNodeEntite)) {
            $this->formationContentNodeEntites->add($formationContentNodeEntite);
            $formationContentNodeEntite->setEntite($this);
        }

        return $this;
    }

    public function removeFormationContentNodeEntite(FormationContentNode $formationContentNodeEntite): static
    {
        if ($this->formationContentNodeEntites->removeElement($formationContentNodeEntite)) {
            // set the owning side to null (unless already changed)
            if ($formationContentNodeEntite->getEntite() === $this) {
                $formationContentNodeEntite->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, FormationObjective>
     */
    public function getFormationObjectiveEntites(): Collection
    {
        return $this->formationObjectiveEntites;
    }

    public function addFormationObjectiveEntite(FormationObjective $formationObjectiveEntite): static
    {
        if (!$this->formationObjectiveEntites->contains($formationObjectiveEntite)) {
            $this->formationObjectiveEntites->add($formationObjectiveEntite);
            $formationObjectiveEntite->setEntite($this);
        }

        return $this;
    }

    public function removeFormationObjectiveEntite(FormationObjective $formationObjectiveEntite): static
    {
        if ($this->formationObjectiveEntites->removeElement($formationObjectiveEntite)) {
            // set the owning side to null (unless already changed)
            if ($formationObjectiveEntite->getEntite() === $this) {
                $formationObjectiveEntite->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, FormationPhoto>
     */
    public function getFormationPhotoEntites(): Collection
    {
        return $this->formationPhotoEntites;
    }

    public function addFormationPhotoEntite(FormationPhoto $formationPhotoEntite): static
    {
        if (!$this->formationPhotoEntites->contains($formationPhotoEntite)) {
            $this->formationPhotoEntites->add($formationPhotoEntite);
            $formationPhotoEntite->setEntite($this);
        }

        return $this;
    }

    public function removeFormationPhotoEntite(FormationPhoto $formationPhotoEntite): static
    {
        if ($this->formationPhotoEntites->removeElement($formationPhotoEntite)) {
            // set the owning side to null (unless already changed)
            if ($formationPhotoEntite->getEntite() === $this) {
                $formationPhotoEntite->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Inscription>
     */
    public function getInscriptionEntites(): Collection
    {
        return $this->inscriptionEntites;
    }

    public function addInscriptionEntite(Inscription $inscriptionEntite): static
    {
        if (!$this->inscriptionEntites->contains($inscriptionEntite)) {
            $this->inscriptionEntites->add($inscriptionEntite);
            $inscriptionEntite->setEntite($this);
        }

        return $this;
    }

    public function removeInscriptionEntite(Inscription $inscriptionEntite): static
    {
        if ($this->inscriptionEntites->removeElement($inscriptionEntite)) {
            // set the owning side to null (unless already changed)
            if ($inscriptionEntite->getEntite() === $this) {
                $inscriptionEntite->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, LigneDevis>
     */
    public function getLigneDevisEntites(): Collection
    {
        return $this->ligneDevisEntites;
    }

    public function addLigneDevisEntite(LigneDevis $ligneDevisEntite): static
    {
        if (!$this->ligneDevisEntites->contains($ligneDevisEntite)) {
            $this->ligneDevisEntites->add($ligneDevisEntite);
            $ligneDevisEntite->setEntite($this);
        }

        return $this;
    }

    public function removeLigneDevisEntite(LigneDevis $ligneDevisEntite): static
    {
        if ($this->ligneDevisEntites->removeElement($ligneDevisEntite)) {
            // set the owning side to null (unless already changed)
            if ($ligneDevisEntite->getEntite() === $this) {
                $ligneDevisEntite->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, LigneFacture>
     */
    public function getLignefactureEntites(): Collection
    {
        return $this->lignefactureEntites;
    }

    public function addLignefactureEntite(LigneFacture $lignefactureEntite): static
    {
        if (!$this->lignefactureEntites->contains($lignefactureEntite)) {
            $this->lignefactureEntites->add($lignefactureEntite);
            $lignefactureEntite->setEntite($this);
        }

        return $this;
    }

    public function removeLignefactureEntite(LigneFacture $lignefactureEntite): static
    {
        if ($this->lignefactureEntites->removeElement($lignefactureEntite)) {
            // set the owning side to null (unless already changed)
            if ($lignefactureEntite->getEntite() === $this) {
                $lignefactureEntite->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Paiement>
     */
    public function getPaiementEntites(): Collection
    {
        return $this->paiementEntites;
    }

    public function addPaiementEntite(Paiement $paiementEntite): static
    {
        if (!$this->paiementEntites->contains($paiementEntite)) {
            $this->paiementEntites->add($paiementEntite);
            $paiementEntite->setEntite($this);
        }

        return $this;
    }

    public function removePaiementEntite(Paiement $paiementEntite): static
    {
        if ($this->paiementEntites->removeElement($paiementEntite)) {
            // set the owning side to null (unless already changed)
            if ($paiementEntite->getEntite() === $this) {
                $paiementEntite->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, PieceDossier>
     */
    public function getPieceDossierEntites(): Collection
    {
        return $this->pieceDossierEntites;
    }

    public function addPieceDossierEntite(PieceDossier $pieceDossierEntite): static
    {
        if (!$this->pieceDossierEntites->contains($pieceDossierEntite)) {
            $this->pieceDossierEntites->add($pieceDossierEntite);
            $pieceDossierEntite->setEntite($this);
        }

        return $this;
    }

    public function removePieceDossierEntite(PieceDossier $pieceDossierEntite): static
    {
        if ($this->pieceDossierEntites->removeElement($pieceDossierEntite)) {
            // set the owning side to null (unless already changed)
            if ($pieceDossierEntite->getEntite() === $this) {
                $pieceDossierEntite->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, PositioningAnswer>
     */
    public function getPositioningAnswerEntites(): Collection
    {
        return $this->positioningAnswerEntites;
    }

    public function addPositioningAnswerEntite(PositioningAnswer $positioningAnswerEntite): static
    {
        if (!$this->positioningAnswerEntites->contains($positioningAnswerEntite)) {
            $this->positioningAnswerEntites->add($positioningAnswerEntite);
            $positioningAnswerEntite->setEntite($this);
        }

        return $this;
    }

    public function removePositioningAnswerEntite(PositioningAnswer $positioningAnswerEntite): static
    {
        if ($this->positioningAnswerEntites->removeElement($positioningAnswerEntite)) {
            // set the owning side to null (unless already changed)
            if ($positioningAnswerEntite->getEntite() === $this) {
                $positioningAnswerEntite->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, PositioningAssignment>
     */
    public function getPositioningAssignmentEntites(): Collection
    {
        return $this->positioningAssignmentEntites;
    }

    public function addPositioningAssignmentEntite(PositioningAssignment $positioningAssignmentEntite): static
    {
        if (!$this->positioningAssignmentEntites->contains($positioningAssignmentEntite)) {
            $this->positioningAssignmentEntites->add($positioningAssignmentEntite);
            $positioningAssignmentEntite->setEntite($this);
        }

        return $this;
    }

    public function removePositioningAssignmentEntite(PositioningAssignment $positioningAssignmentEntite): static
    {
        if ($this->positioningAssignmentEntites->removeElement($positioningAssignmentEntite)) {
            // set the owning side to null (unless already changed)
            if ($positioningAssignmentEntite->getEntite() === $this) {
                $positioningAssignmentEntite->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, PositioningAttempt>
     */
    public function getPositioningAttemptEntites(): Collection
    {
        return $this->positioningAttemptEntites;
    }

    public function addPositioningAttemptEntite(PositioningAttempt $positioningAttemptEntite): static
    {
        if (!$this->positioningAttemptEntites->contains($positioningAttemptEntite)) {
            $this->positioningAttemptEntites->add($positioningAttemptEntite);
            $positioningAttemptEntite->setEntite($this);
        }

        return $this;
    }

    public function removePositioningAttemptEntite(PositioningAttempt $positioningAttemptEntite): static
    {
        if ($this->positioningAttemptEntites->removeElement($positioningAttemptEntite)) {
            // set the owning side to null (unless already changed)
            if ($positioningAttemptEntite->getEntite() === $this) {
                $positioningAttemptEntite->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, PositioningChapter>
     */
    public function getPositioningChapterEntites(): Collection
    {
        return $this->positioningChapterEntites;
    }

    public function addPositioningChapterEntite(PositioningChapter $positioningChapterEntite): static
    {
        if (!$this->positioningChapterEntites->contains($positioningChapterEntite)) {
            $this->positioningChapterEntites->add($positioningChapterEntite);
            $positioningChapterEntite->setEntite($this);
        }

        return $this;
    }

    public function removePositioningChapterEntite(PositioningChapter $positioningChapterEntite): static
    {
        if ($this->positioningChapterEntites->removeElement($positioningChapterEntite)) {
            // set the owning side to null (unless already changed)
            if ($positioningChapterEntite->getEntite() === $this) {
                $positioningChapterEntite->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, PositioningItem>
     */
    public function getPositioningItemEntites(): Collection
    {
        return $this->positioningItemEntites;
    }

    public function addPositioningItemEntite(PositioningItem $positioningItemEntite): static
    {
        if (!$this->positioningItemEntites->contains($positioningItemEntite)) {
            $this->positioningItemEntites->add($positioningItemEntite);
            $positioningItemEntite->setEntite($this);
        }

        return $this;
    }

    public function removePositioningItemEntite(PositioningItem $positioningItemEntite): static
    {
        if ($this->positioningItemEntites->removeElement($positioningItemEntite)) {
            // set the owning side to null (unless already changed)
            if ($positioningItemEntite->getEntite() === $this) {
                $positioningItemEntite->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ProspectInteraction>
     */
    public function getProspectInteractionEntites(): Collection
    {
        return $this->prospectInteractionEntites;
    }

    public function addProspectInteractionEntite(ProspectInteraction $prospectInteractionEntite): static
    {
        if (!$this->prospectInteractionEntites->contains($prospectInteractionEntite)) {
            $this->prospectInteractionEntites->add($prospectInteractionEntite);
            $prospectInteractionEntite->setEntite($this);
        }

        return $this;
    }

    public function removeProspectInteractionEntite(ProspectInteraction $prospectInteractionEntite): static
    {
        if ($this->prospectInteractionEntites->removeElement($prospectInteractionEntite)) {
            // set the owning side to null (unless already changed)
            if ($prospectInteractionEntite->getEntite() === $this) {
                $prospectInteractionEntite->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, QcmAnswer>
     */
    public function getQcmAnswerEntites(): Collection
    {
        return $this->qcmAnswerEntites;
    }

    public function addQcmAnswerEntite(QcmAnswer $qcmAnswerEntite): static
    {
        if (!$this->qcmAnswerEntites->contains($qcmAnswerEntite)) {
            $this->qcmAnswerEntites->add($qcmAnswerEntite);
            $qcmAnswerEntite->setEntite($this);
        }

        return $this;
    }

    public function removeQcmAnswerEntite(QcmAnswer $qcmAnswerEntite): static
    {
        if ($this->qcmAnswerEntites->removeElement($qcmAnswerEntite)) {
            // set the owning side to null (unless already changed)
            if ($qcmAnswerEntite->getEntite() === $this) {
                $qcmAnswerEntite->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, QcmAssignment>
     */
    public function getQcmAssignmentEntites(): Collection
    {
        return $this->qcmAssignmentEntites;
    }

    public function addQcmAssignmentEntite(QcmAssignment $qcmAssignmentEntite): static
    {
        if (!$this->qcmAssignmentEntites->contains($qcmAssignmentEntite)) {
            $this->qcmAssignmentEntites->add($qcmAssignmentEntite);
            $qcmAssignmentEntite->setEntite($this);
        }

        return $this;
    }

    public function removeQcmAssignmentEntite(QcmAssignment $qcmAssignmentEntite): static
    {
        if ($this->qcmAssignmentEntites->removeElement($qcmAssignmentEntite)) {
            // set the owning side to null (unless already changed)
            if ($qcmAssignmentEntite->getEntite() === $this) {
                $qcmAssignmentEntite->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, QcmAttempt>
     */
    public function getQcmAttemptEntites(): Collection
    {
        return $this->qcmAttemptEntites;
    }

    public function addQcmAttemptEntite(QcmAttempt $qcmAttemptEntite): static
    {
        if (!$this->qcmAttemptEntites->contains($qcmAttemptEntite)) {
            $this->qcmAttemptEntites->add($qcmAttemptEntite);
            $qcmAttemptEntite->setEntite($this);
        }

        return $this;
    }

    public function removeQcmAttemptEntite(QcmAttempt $qcmAttemptEntite): static
    {
        $this->qcmAttemptEntites->removeElement($qcmAttemptEntite);
        return $this;
    }

    /**
     * @return Collection<int, QcmOption>
     */
    public function getQcmOptionEntites(): Collection
    {
        return $this->qcmOptionEntites;
    }

    public function addQcmOptionEntite(QcmOption $qcmOptionEntite): static
    {
        if (!$this->qcmOptionEntites->contains($qcmOptionEntite)) {
            $this->qcmOptionEntites->add($qcmOptionEntite);
            $qcmOptionEntite->setEntite($this);
        }

        return $this;
    }

    public function removeQcmOptionEntite(QcmOption $qcmOptionEntite): static
    {
        if ($this->qcmOptionEntites->removeElement($qcmOptionEntite)) {
            // set the owning side to null (unless already changed)
            if ($qcmOptionEntite->getEntite() === $this) {
                $qcmOptionEntite->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, QcmQuestion>
     */
    public function getQcmQuestionEntites(): Collection
    {
        return $this->qcmQuestionEntites;
    }

    public function addQcmQuestionEntite(QcmQuestion $qcmQuestionEntite): static
    {
        if (!$this->qcmQuestionEntites->contains($qcmQuestionEntite)) {
            $this->qcmQuestionEntites->add($qcmQuestionEntite);
            $qcmQuestionEntite->setEntite($this);
        }

        return $this;
    }

    public function removeQcmQuestionEntite(QcmQuestion $qcmQuestionEntite): static
    {
        if ($this->qcmQuestionEntites->removeElement($qcmQuestionEntite)) {
            // set the owning side to null (unless already changed)
            if ($qcmQuestionEntite->getEntite() === $this) {
                $qcmQuestionEntite->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Quiz>
     */
    public function getQuizEntites(): Collection
    {
        return $this->quizEntites;
    }

    public function addQuizEntite(Quiz $quizEntite): static
    {
        if (!$this->quizEntites->contains($quizEntite)) {
            $this->quizEntites->add($quizEntite);
            $quizEntite->setEntite($this);
        }

        return $this;
    }

    public function removeQuizEntite(Quiz $quizEntite): static
    {
        if ($this->quizEntites->removeElement($quizEntite)) {
            // set the owning side to null (unless already changed)
            if ($quizEntite->getEntite() === $this) {
                $quizEntite->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, QuizAnswer>
     */
    public function getQuizAnswerEntites(): Collection
    {
        return $this->quizAnswerEntites;
    }

    public function addQuizAnswerEntite(QuizAnswer $quizAnswerEntite): static
    {
        if (!$this->quizAnswerEntites->contains($quizAnswerEntite)) {
            $this->quizAnswerEntites->add($quizAnswerEntite);
            $quizAnswerEntite->setEntite($this);
        }

        return $this;
    }

    public function removeQuizAnswerEntite(QuizAnswer $quizAnswerEntite): static
    {
        if ($this->quizAnswerEntites->removeElement($quizAnswerEntite)) {
            // set the owning side to null (unless already changed)
            if ($quizAnswerEntite->getEntite() === $this) {
                $quizAnswerEntite->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, QuizAttempt>
     */
    public function getQuizAttemptEntites(): Collection
    {
        return $this->quizAttemptEntites;
    }

    public function addQuizAttemptEntite(QuizAttempt $quizAttemptEntite): static
    {
        if (!$this->quizAttemptEntites->contains($quizAttemptEntite)) {
            $this->quizAttemptEntites->add($quizAttemptEntite);
            $quizAttemptEntite->setEntite($this);
        }

        return $this;
    }

    public function removeQuizAttemptEntite(QuizAttempt $quizAttemptEntite): static
    {
        if ($this->quizAttemptEntites->removeElement($quizAttemptEntite)) {
            // set the owning side to null (unless already changed)
            if ($quizAttemptEntite->getEntite() === $this) {
                $quizAttemptEntite->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, QuizChoice>
     */
    public function getQuizChoiceEntites(): Collection
    {
        return $this->quizChoiceEntites;
    }

    public function addQuizChoiceEntite(QuizChoice $quizChoiceEntite): static
    {
        if (!$this->quizChoiceEntites->contains($quizChoiceEntite)) {
            $this->quizChoiceEntites->add($quizChoiceEntite);
            $quizChoiceEntite->setEntite($this);
        }

        return $this;
    }

    public function removeQuizChoiceEntite(QuizChoice $quizChoiceEntite): static
    {
        if ($this->quizChoiceEntites->removeElement($quizChoiceEntite)) {
            // set the owning side to null (unless already changed)
            if ($quizChoiceEntite->getEntite() === $this) {
                $quizChoiceEntite->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, QuizQuestion>
     */
    public function getQuizQuestionEntites(): Collection
    {
        return $this->quizQuestionEntites;
    }

    public function addQuizQuestionEntite(QuizQuestion $quizQuestionEntite): static
    {
        if (!$this->quizQuestionEntites->contains($quizQuestionEntite)) {
            $this->quizQuestionEntites->add($quizQuestionEntite);
            $quizQuestionEntite->setEntite($this);
        }

        return $this;
    }

    public function removeQuizQuestionEntite(QuizQuestion $quizQuestionEntite): static
    {
        if ($this->quizQuestionEntites->removeElement($quizQuestionEntite)) {
            // set the owning side to null (unless already changed)
            if ($quizQuestionEntite->getEntite() === $this) {
                $quizQuestionEntite->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, RapportFormateur>
     */
    public function getRapportFormateurEntites(): Collection
    {
        return $this->rapportFormateurEntites;
    }

    public function addRapportFormateurEntite(RapportFormateur $rapportFormateurEntite): static
    {
        if (!$this->rapportFormateurEntites->contains($rapportFormateurEntite)) {
            $this->rapportFormateurEntites->add($rapportFormateurEntite);
            $rapportFormateurEntite->setEntite($this);
        }

        return $this;
    }

    public function removeRapportFormateurEntite(RapportFormateur $rapportFormateurEntite): static
    {
        if ($this->rapportFormateurEntites->removeElement($rapportFormateurEntite)) {
            // set the owning side to null (unless already changed)
            if ($rapportFormateurEntite->getEntite() === $this) {
                $rapportFormateurEntite->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Reservation>
     */
    public function getReservationEntites(): Collection
    {
        return $this->reservationEntites;
    }

    public function addReservationEntite(Reservation $reservationEntite): static
    {
        if (!$this->reservationEntites->contains($reservationEntite)) {
            $this->reservationEntites->add($reservationEntite);
            $reservationEntite->setEntite($this);
        }

        return $this;
    }

    public function removeReservationEntite(Reservation $reservationEntite): static
    {
        if ($this->reservationEntites->removeElement($reservationEntite)) {
            // set the owning side to null (unless already changed)
            if ($reservationEntite->getEntite() === $this) {
                $reservationEntite->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, SatisfactionAssignment>
     */
    public function getSatisfactionAssignmentEntites(): Collection
    {
        return $this->satisfactionAssignmentEntites;
    }

    public function addSatisfactionAssignmentEntite(SatisfactionAssignment $satisfactionAssignmentEntite): static
    {
        if (!$this->satisfactionAssignmentEntites->contains($satisfactionAssignmentEntite)) {
            $this->satisfactionAssignmentEntites->add($satisfactionAssignmentEntite);
            $satisfactionAssignmentEntite->setEntite($this);
        }

        return $this;
    }

    public function removeSatisfactionAssignmentEntite(SatisfactionAssignment $satisfactionAssignmentEntite): static
    {
        if ($this->satisfactionAssignmentEntites->removeElement($satisfactionAssignmentEntite)) {
            // set the owning side to null (unless already changed)
            if ($satisfactionAssignmentEntite->getEntite() === $this) {
                $satisfactionAssignmentEntite->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, SatisfactionAttempt>
     */
    public function getSatisfactionAttemptEntites(): Collection
    {
        return $this->satisfactionAttemptEntites;
    }

    public function addSatisfactionAttemptEntite(SatisfactionAttempt $satisfactionAttemptEntite): static
    {
        if (!$this->satisfactionAttemptEntites->contains($satisfactionAttemptEntite)) {
            $this->satisfactionAttemptEntites->add($satisfactionAttemptEntite);
            $satisfactionAttemptEntite->setEntite($this);
        }

        return $this;
    }

    public function removeSatisfactionAttemptEntite(SatisfactionAttempt $satisfactionAttemptEntite): static
    {
        if ($this->satisfactionAttemptEntites->removeElement($satisfactionAttemptEntite)) {
            // set the owning side to null (unless already changed)
            if ($satisfactionAttemptEntite->getEntite() === $this) {
                $satisfactionAttemptEntite->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, SatisfactionChapter>
     */
    public function getSatisfactionChapterEntites(): Collection
    {
        return $this->satisfactionChapterEntites;
    }

    public function addSatisfactionChapterEntite(SatisfactionChapter $satisfactionChapterEntite): static
    {
        if (!$this->satisfactionChapterEntites->contains($satisfactionChapterEntite)) {
            $this->satisfactionChapterEntites->add($satisfactionChapterEntite);
            $satisfactionChapterEntite->setEntite($this);
        }

        return $this;
    }

    public function removeSatisfactionChapterEntite(SatisfactionChapter $satisfactionChapterEntite): static
    {
        if ($this->satisfactionChapterEntites->removeElement($satisfactionChapterEntite)) {
            // set the owning side to null (unless already changed)
            if ($satisfactionChapterEntite->getEntite() === $this) {
                $satisfactionChapterEntite->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, SatisfactionQuestion>
     */
    public function getSatisfactionQuestionEntites(): Collection
    {
        return $this->satisfactionQuestionEntites;
    }

    public function addSatisfactionQuestionEntite(SatisfactionQuestion $satisfactionQuestionEntite): static
    {
        if (!$this->satisfactionQuestionEntites->contains($satisfactionQuestionEntite)) {
            $this->satisfactionQuestionEntites->add($satisfactionQuestionEntite);
            $satisfactionQuestionEntite->setEntite($this);
        }

        return $this;
    }

    public function removeSatisfactionQuestionEntite(SatisfactionQuestion $satisfactionQuestionEntite): static
    {
        if ($this->satisfactionQuestionEntites->removeElement($satisfactionQuestionEntite)) {
            // set the owning side to null (unless already changed)
            if ($satisfactionQuestionEntite->getEntite() === $this) {
                $satisfactionQuestionEntite->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, SessionJour>
     */
    public function getSessionJourEntites(): Collection
    {
        return $this->sessionJourEntites;
    }

    public function addSessionJourEntite(SessionJour $sessionJourEntite): static
    {
        if (!$this->sessionJourEntites->contains($sessionJourEntite)) {
            $this->sessionJourEntites->add($sessionJourEntite);
            $sessionJourEntite->setEntite($this);
        }

        return $this;
    }

    public function removeSessionJourEntite(SessionJour $sessionJourEntite): static
    {
        if ($this->sessionJourEntites->removeElement($sessionJourEntite)) {
            // set the owning side to null (unless already changed)
            if ($sessionJourEntite->getEntite() === $this) {
                $sessionJourEntite->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, SessionPositioning>
     */
    public function getSessionPositioningEntites(): Collection
    {
        return $this->sessionPositioningEntites;
    }

    public function addSessionPositioningEntite(SessionPositioning $sessionPositioningEntite): static
    {
        if (!$this->sessionPositioningEntites->contains($sessionPositioningEntite)) {
            $this->sessionPositioningEntites->add($sessionPositioningEntite);
            $sessionPositioningEntite->setEntite($this);
        }

        return $this;
    }

    public function removeSessionPositioningEntite(SessionPositioning $sessionPositioningEntite): static
    {
        if ($this->sessionPositioningEntites->removeElement($sessionPositioningEntite)) {
            // set the owning side to null (unless already changed)
            if ($sessionPositioningEntite->getEntite() === $this) {
                $sessionPositioningEntite->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, SupportAssignSession>
     */
    public function getSupportAssignSessionEntites(): Collection
    {
        return $this->supportAssignSessionEntites;
    }

    public function addSupportAssignSessionEntite(SupportAssignSession $supportAssignSessionEntite): static
    {
        if (!$this->supportAssignSessionEntites->contains($supportAssignSessionEntite)) {
            $this->supportAssignSessionEntites->add($supportAssignSessionEntite);
            $supportAssignSessionEntite->setEntite($this);
        }

        return $this;
    }

    public function removeSupportAssignSessionEntite(SupportAssignSession $supportAssignSessionEntite): static
    {
        if ($this->supportAssignSessionEntites->removeElement($supportAssignSessionEntite)) {
            // set the owning side to null (unless already changed)
            if ($supportAssignSessionEntite->getEntite() === $this) {
                $supportAssignSessionEntite->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, SupportAssignUser>
     */
    public function getSupportAssignUserEntites(): Collection
    {
        return $this->supportAssignUserEntites;
    }

    public function addSupportAssignUserEntite(SupportAssignUser $supportAssignUserEntite): static
    {
        if (!$this->supportAssignUserEntites->contains($supportAssignUserEntite)) {
            $this->supportAssignUserEntites->add($supportAssignUserEntite);
            $supportAssignUserEntite->setEntite($this);
        }

        return $this;
    }

    public function removeSupportAssignUserEntite(SupportAssignUser $supportAssignUserEntite): static
    {
        if ($this->supportAssignUserEntites->removeElement($supportAssignUserEntite)) {
            // set the owning side to null (unless already changed)
            if ($supportAssignUserEntite->getEntite() === $this) {
                $supportAssignUserEntite->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, SupportDocument>
     */
    public function getSupportDocumentEntites(): Collection
    {
        return $this->supportDocumentEntites;
    }

    public function addSupportDocumentEntite(SupportDocument $supportDocumentEntite): static
    {
        if (!$this->supportDocumentEntites->contains($supportDocumentEntite)) {
            $this->supportDocumentEntites->add($supportDocumentEntite);
            $supportDocumentEntite->setEntite($this);
        }

        return $this;
    }

    public function removeSupportDocumentEntite(SupportDocument $supportDocumentEntite): static
    {
        if ($this->supportDocumentEntites->removeElement($supportDocumentEntite)) {
            // set the owning side to null (unless already changed)
            if ($supportDocumentEntite->getEntite() === $this) {
                $supportDocumentEntite->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, DepenseCategorie>
     */
    public function getDepenseCategories(): Collection
    {
        return $this->depenseCategories;
    }

    public function addDepenseCategory(DepenseCategorie $depenseCategory): static
    {
        if (!$this->depenseCategories->contains($depenseCategory)) {
            $this->depenseCategories->add($depenseCategory);
            $depenseCategory->setEntite($this);
        }

        return $this;
    }

    public function removeDepenseCategory(DepenseCategorie $depenseCategory): static
    {
        if ($this->depenseCategories->removeElement($depenseCategory)) {
            // set the owning side to null (unless already changed)
            if ($depenseCategory->getEntite() === $this) {
                $depenseCategory->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, DepenseFournisseur>
     */
    public function getDepenseFournisseurs(): Collection
    {
        return $this->depenseFournisseurs;
    }

    public function addDepenseFournisseur(DepenseFournisseur $depenseFournisseur): static
    {
        if (!$this->depenseFournisseurs->contains($depenseFournisseur)) {
            $this->depenseFournisseurs->add($depenseFournisseur);
            $depenseFournisseur->setEntite($this);
        }

        return $this;
    }

    public function removeDepenseFournisseur(DepenseFournisseur $depenseFournisseur): static
    {
        if ($this->depenseFournisseurs->removeElement($depenseFournisseur)) {
            // set the owning side to null (unless already changed)
            if ($depenseFournisseur->getEntite() === $this) {
                $depenseFournisseur->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, FiscalProfile>
     */
    public function getFiscalProfiles(): Collection
    {
        return $this->fiscalProfiles;
    }

    public function addFiscalProfile(FiscalProfile $fiscalProfile): static
    {
        if (!$this->fiscalProfiles->contains($fiscalProfile)) {
            $this->fiscalProfiles->add($fiscalProfile);
            $fiscalProfile->setEntite($this);
        }

        return $this;
    }

    public function removeFiscalProfile(FiscalProfile $fiscalProfile): static
    {
        if ($this->fiscalProfiles->removeElement($fiscalProfile)) {
            // set the owning side to null (unless already changed)
            if ($fiscalProfile->getEntite() === $this) {
                $fiscalProfile->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, TaxRule>
     */
    public function getTaxRules(): Collection
    {
        return $this->taxRules;
    }

    public function addTaxRule(TaxRule $taxRule): static
    {
        if (!$this->taxRules->contains($taxRule)) {
            $this->taxRules->add($taxRule);
            $taxRule->setEntite($this);
        }

        return $this;
    }

    public function removeTaxRule(TaxRule $taxRule): static
    {
        if ($this->taxRules->removeElement($taxRule)) {
            // set the owning side to null (unless already changed)
            if ($taxRule->getEntite() === $this) {
                $taxRule->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, TaxComputation>
     */
    public function getTaxComputations(): Collection
    {
        return $this->taxComputations;
    }

    public function addTaxComputation(TaxComputation $taxComputation): static
    {
        if (!$this->taxComputations->contains($taxComputation)) {
            $this->taxComputations->add($taxComputation);
            $taxComputation->setEntite($this);
        }

        return $this;
    }

    public function removeTaxComputation(TaxComputation $taxComputation): static
    {
        if ($this->taxComputations->removeElement($taxComputation)) {
            // set the owning side to null (unless already changed)
            if ($taxComputation->getEntite() === $this) {
                $taxComputation->setEntite(null);
            }
        }

        return $this;
    }

    public function getActiveFiscalProfileAt(\DateTimeImmutable $at): ?FiscalProfile
    {
        foreach ($this->fiscalProfiles as $p) {
            if ($p->getValidFrom() <= $at && ($p->getValidTo() === null || $p->getValidTo() >= $at)) {
                return $p;
            }
        }
        // fallback: default
        foreach ($this->fiscalProfiles as $p) {
            if ($p->isDefault()) return $p;
        }
        return null;
    }

    /**
     * @return Collection<int, ElearningCourse>
     */
    public function getElearningCourses(): Collection
    {
        return $this->elearningCourses;
    }

    public function addElearningCourse(ElearningCourse $elearningCourse): static
    {
        if (!$this->elearningCourses->contains($elearningCourse)) {
            $this->elearningCourses->add($elearningCourse);
            $elearningCourse->setEntite($this);
        }

        return $this;
    }

    public function removeElearningCourse(ElearningCourse $elearningCourse): static
    {
        if ($this->elearningCourses->removeElement($elearningCourse)) {
            // set the owning side to null (unless already changed)
            if ($elearningCourse->getEntite() === $this) {
                $elearningCourse->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ElearningNode>
     */
    public function getElearningNodes(): Collection
    {
        return $this->elearningNodes;
    }

    public function addElearningNode(ElearningNode $elearningNode): static
    {
        if (!$this->elearningNodes->contains($elearningNode)) {
            $this->elearningNodes->add($elearningNode);
            $elearningNode->setEntite($this);
        }

        return $this;
    }

    public function removeElearningNode(ElearningNode $elearningNode): static
    {
        if ($this->elearningNodes->removeElement($elearningNode)) {
            // set the owning side to null (unless already changed)
            if ($elearningNode->getEntite() === $this) {
                $elearningNode->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ElearningBlock>
     */
    public function getElearningBlocks(): Collection
    {
        return $this->elearningBlocks;
    }

    public function addElearningBlock(ElearningBlock $elearningBlock): static
    {
        if (!$this->elearningBlocks->contains($elearningBlock)) {
            $this->elearningBlocks->add($elearningBlock);
            $elearningBlock->setEntite($this);
        }

        return $this;
    }

    public function removeElearningBlock(ElearningBlock $elearningBlock): static
    {
        if ($this->elearningBlocks->removeElement($elearningBlock)) {
            // set the owning side to null (unless already changed)
            if ($elearningBlock->getEntite() === $this) {
                $elearningBlock->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ElearningEnrollment>
     */
    public function getElearningEnrollments(): Collection
    {
        return $this->elearningEnrollments;
    }

    public function addElearningEnrollment(ElearningEnrollment $elearningEnrollment): static
    {
        if (!$this->elearningEnrollments->contains($elearningEnrollment)) {
            $this->elearningEnrollments->add($elearningEnrollment);
            $elearningEnrollment->setEntite($this);
        }

        return $this;
    }

    public function removeElearningEnrollment(ElearningEnrollment $elearningEnrollment): static
    {
        if ($this->elearningEnrollments->removeElement($elearningEnrollment)) {
            // set the owning side to null (unless already changed)
            if ($elearningEnrollment->getEntite() === $this) {
                $elearningEnrollment->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ElearningOrder>
     */
    public function getElearningOrders(): Collection
    {
        return $this->elearningOrders;
    }

    public function addElearningOrder(ElearningOrder $elearningOrder): static
    {
        if (!$this->elearningOrders->contains($elearningOrder)) {
            $this->elearningOrders->add($elearningOrder);
            $elearningOrder->setEntite($this);
        }

        return $this;
    }

    public function removeElearningOrder(ElearningOrder $elearningOrder): static
    {
        if ($this->elearningOrders->removeElement($elearningOrder)) {
            // set the owning side to null (unless already changed)
            if ($elearningOrder->getEntite() === $this) {
                $elearningOrder->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, EntrepriseDocument>
     */
    public function getEntrepriseDocuments(): Collection
    {
        return $this->entrepriseDocuments;
    }

    public function addEntrepriseDocument(EntrepriseDocument $entrepriseDocument): static
    {
        if (!$this->entrepriseDocuments->contains($entrepriseDocument)) {
            $this->entrepriseDocuments->add($entrepriseDocument);
            $entrepriseDocument->setEntite($this);
        }

        return $this;
    }

    public function removeEntrepriseDocument(EntrepriseDocument $entrepriseDocument): static
    {
        if ($this->entrepriseDocuments->removeElement($entrepriseDocument)) {
            // set the owning side to null (unless already changed)
            if ($entrepriseDocument->getEntite() === $this) {
                $entrepriseDocument->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, EntiteSubscription>
     */
    public function getEntiteSubscriptions(): Collection
    {
        return $this->entiteSubscriptions;
    }

    public function addEntiteSubscription(EntiteSubscription $entiteSubscription): static
    {
        if (!$this->entiteSubscriptions->contains($entiteSubscription)) {
            $this->entiteSubscriptions->add($entiteSubscription);
            $entiteSubscription->setEntite($this);
        }

        return $this;
    }

    public function removeEntiteSubscription(EntiteSubscription $entiteSubscription): static
    {
        if ($this->entiteSubscriptions->removeElement($entiteSubscription)) {
            // set the owning side to null (unless already changed)
            if ($entiteSubscription->getEntite() === $this) {
                $entiteSubscription->setEntite(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, EntiteUsageYear>
     */
    public function getEntiteUsageYears(): Collection
    {
        return $this->entiteUsageYears;
    }

    public function addEntiteUsageYear(EntiteUsageYear $entiteUsageYear): static
    {
        if (!$this->entiteUsageYears->contains($entiteUsageYear)) {
            $this->entiteUsageYears->add($entiteUsageYear);
            $entiteUsageYear->setEntite($this);
        }

        return $this;
    }

    public function removeEntiteUsageYear(EntiteUsageYear $entiteUsageYear): static
    {
        if ($this->entiteUsageYears->removeElement($entiteUsageYear)) {
            // set the owning side to null (unless already changed)
            if ($entiteUsageYear->getEntite() === $this) {
                $entiteUsageYear->setEntite($this);
            }
        }

        return $this;
    }

    public function getLastActivityAt(): ?\DateTimeImmutable
    {
        return $this->lastActivityAt;
    }

    public function touchActivity(): void
    {
        $this->lastActivityAt = new \DateTimeImmutable();
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

    public function isActive(): ?bool
    {
        return $this->isActive;
    }

    public function setIsActive(?bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function setLastActivityAt(?\DateTimeImmutable $dt): static
    {
        $this->lastActivityAt = $dt;
        return $this;
    }
    public function getConnect(): ?EntiteConnect 
    { 
        return $this->connect; 
    }

    public function setConnect(?EntiteConnect $connect): static
    {
        $this->connect = $connect;
        if ($connect && $connect->getEntite() !== $this) {
            $connect->setEntite($this);
        }
        return $this;
    }

    /**
     * @return Collection<int, PublicHost>
     */
    public function getPublicHosts(): Collection
    {
        return $this->publicHosts;
    }

    public function addPublicHost(PublicHost $publicHost): static
    {
        if (!$this->publicHosts->contains($publicHost)) {
            $this->publicHosts->add($publicHost);
            $publicHost->setEntite($this);
        }

        return $this;
    }

    public function removePublicHost(PublicHost $publicHost): static
    {
        if ($this->publicHosts->removeElement($publicHost)) {
            // set the owning side to null (unless already changed)
            if ($publicHost->getEntite() === $this) {
                $publicHost->setEntite(null);
            }
        }

        return $this;
    }
}
