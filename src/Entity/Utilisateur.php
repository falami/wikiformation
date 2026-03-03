<?php

namespace App\Entity;

use App\Entity\Elearning\ElearningBlock;
use App\Entity\Elearning\ElearningCourse;
use App\Entity\Elearning\ElearningEnrollment;
use App\Entity\Elearning\ElearningNode;
use App\Entity\Elearning\ElearningOrder;
use App\Repository\UtilisateurRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UtilisateurRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
#[UniqueEntity(fields: ['email'], message: 'There is already an account with this email')]
class Utilisateur implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private ?string $email = null;

    /**
     * @var list<string> The user roles
     */
    #[ORM\Column]
    private array $roles = [];

    /**
     * @var string The hashed password
     */
    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column]
    private bool $isVerified = false;

    /**
     * @var Collection<int, Entite>
     */
    #[ORM\OneToMany(targetEntity: Entite::class, mappedBy: 'createur')]
    private Collection $entites;

    /**
     * @var Collection<int, UtilisateurEntite>
     */
    #[ORM\OneToMany(
        mappedBy: 'utilisateur',
        targetEntity: UtilisateurEntite::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    private Collection $utilisateurEntites;


    #[ORM\Column(length: 100)]
    private ?string $nom = null;

    #[ORM\Column(length: 100)]
    private ?string $prenom = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $telephone = null;

    #[ORM\Column(length: 7, nullable: true)]
    private ?string $couleur = null;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'utilisateursCreateur')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?self $createur = null;

    /**
     * @var Collection<int, self>
     */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'createur')]
    private Collection $utilisateursCreateur;

    #[ORM\ManyToOne(inversedBy: 'responsables')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Entite $entite = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $adresse = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $complement = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $codePostal = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateNaissance = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $ville = null;

    #[ORM\Column(length: 15, nullable: true)]
    private ?string $civilite = null;

    #[ORM\Column(type: "string", length: 255, nullable: true)]
    private ?string $resetToken = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resetTokenExpiresAt = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $abonnement = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeCustomerId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeSubscriptionId = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $numeroLicence = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $region = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $pays = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $departement = null;

    #[ORM\Column(nullable: true)]
    private ?bool $desactiverTemporairement = null;

    #[ORM\Column(nullable: true)]
    private ?bool $bannir = null;

    #[ORM\Column(nullable: true)]
    private ?int $unreadCount = null;

    #[ORM\Column(nullable: true)]
    private ?bool $consentementRgpd = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateConsentementRgpd = null;

    #[ORM\Column(nullable: true)]
    private ?bool $newsletter = null;

    #[ORM\Column(nullable: true)]
    private ?bool $mailBienvenue = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $niveau = null;

    #[ORM\Column(nullable: true)]
    private ?bool $mailSortie = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $societe = null;

    #[ORM\OneToOne(mappedBy: 'utilisateur', cascade: ['persist', 'remove'])]
    private ?Formateur $formateur = null;

    #[ORM\ManyToOne(inversedBy: 'utilisateurs')]
    private ?Entreprise $entreprise = null;

    /**
     * @var Collection<int, Reservation>
     */
    #[ORM\OneToMany(targetEntity: Reservation::class, mappedBy: 'utilisateur')]
    private Collection $reservations;

    /**
     * @var Collection<int, Emargement>
     */
    #[ORM\OneToMany(targetEntity: Emargement::class, mappedBy: 'utilisateur', orphanRemoval: true)]
    private Collection $emargements;

    /**
     * @var Collection<int, SupportDocument>
     */
    #[ORM\OneToMany(targetEntity: SupportDocument::class, mappedBy: 'uploadedBy')]
    private Collection $supportDocuments;

    /**
     * @var Collection<int, SupportAsset>
     */
    #[ORM\OneToMany(targetEntity: SupportAsset::class, mappedBy: 'uploadedBy')]
    private Collection $supportAssets;

    /**
     * @var Collection<int, SupportAssignUser>
     */
    #[ORM\OneToMany(targetEntity: SupportAssignUser::class, mappedBy: 'user')]
    private Collection $supportAssignUsers;

    /**
     * @var Collection<int, Inscription>
     */
    #[ORM\OneToMany(targetEntity: Inscription::class, mappedBy: 'stagiaire', orphanRemoval: true)]
    private Collection $inscriptions;

    /**
     * @var Collection<int, Facture>
     */
    #[ORM\OneToMany(targetEntity: Facture::class, mappedBy: 'destinataire')]
    private Collection $factures;

    /**
     * @var Collection<int, QuizAttempt>
     */
    #[ORM\OneToMany(targetEntity: QuizAttempt::class, mappedBy: 'stagiaire')]
    private Collection $quizAttempts;

    /**
     * @var Collection<int, QuestionnaireSatisfaction>
     */
    #[ORM\OneToMany(targetEntity: QuestionnaireSatisfaction::class, mappedBy: 'stagiaire')]
    private Collection $questionnaireSatisfactions;

    /**
     * @var Collection<int, AuditLog>
     */
    #[ORM\OneToMany(targetEntity: AuditLog::class, mappedBy: 'actor')]
    private Collection $auditLogs;

    /**
     * @var Collection<int, Devis>
     */
    #[ORM\OneToMany(mappedBy: 'destinataire', targetEntity: Devis::class, cascade: ['remove'], orphanRemoval: true)]
    private Collection $devis;

    /**
     * @var Collection<int, PositioningAttempt>
     */
    #[ORM\OneToMany(targetEntity: PositioningAttempt::class, mappedBy: 'stagiaire')]
    private Collection $positioningAttempts;


    /**
     * @var Collection<int, PositioningAssignment>
     */
    #[ORM\OneToMany(mappedBy: 'stagiaire', targetEntity: PositioningAssignment::class)]
    private Collection $positioningAssignments;

    /**
     * @var Collection<int, PositioningAssignment>
     */
    #[ORM\OneToMany(targetEntity: PositioningAssignment::class, mappedBy: 'evaluator')]
    private Collection $positioningAssignementsFormateur;

    /**
     * @var Collection<int, SatisfactionAssignment>
     */
    #[ORM\OneToMany(targetEntity: SatisfactionAssignment::class, mappedBy: 'stagiaire')]
    private Collection $satisfactionAssignments;

    /**
     * @var Collection<int, FormateurSatisfactionAssignment>
     */
    #[ORM\OneToMany(targetEntity: FormateurSatisfactionAssignment::class, mappedBy: 'formateur')]
    private Collection $formateurSatisfactionAssignments;

    /**
     * @var Collection<int, FormateurObjectiveEvaluation>
     */
    #[ORM\OneToMany(targetEntity: FormateurObjectiveEvaluation::class, mappedBy: 'stagiaire')]
    private Collection $formateurObjectiveEvaluations;

    /**
     * @var Collection<int, Prospect>
     */
    #[ORM\OneToMany(targetEntity: Prospect::class, mappedBy: 'linkedUser')]
    private Collection $prospects;

    /**
     * @var Collection<int, ProspectInteraction>
     */
    #[ORM\OneToMany(targetEntity: ProspectInteraction::class, mappedBy: 'actor')]
    private Collection $prospectInteractions;

    /**
     * @var Collection<int, EmailLog>
     */
    #[ORM\OneToMany(targetEntity: EmailLog::class, mappedBy: 'actor')]
    private Collection $sentEmailLogs;

    /**
     * @var Collection<int, EmailLog>
     */
    #[ORM\OneToMany(targetEntity: EmailLog::class, mappedBy: 'toUser')]
    private Collection $receivedEmailLogs;

    /**
     * @var Collection<int, ProspectInteraction>
     */
    #[ORM\OneToMany(targetEntity: ProspectInteraction::class, mappedBy: 'utilisateur')]
    private Collection $prospectInteractionsUtilisateurs;

    /**
     * @var Collection<int, QcmAssignment>
     */
    #[ORM\OneToMany(targetEntity: QcmAssignment::class, mappedBy: 'adminFollowUpBy')]
    private Collection $qcmAssignments;

    /**
     * @var Collection<int, Formateur>
     */
    #[ORM\OneToMany(targetEntity: Formateur::class, mappedBy: 'createur')]
    private Collection $formateurs;

    /**
     * @var Collection<int, Prospect>
     */
    #[ORM\OneToMany(targetEntity: Prospect::class, mappedBy: 'createur')]
    private Collection $prospectCreateurs;

    /**
     * @var Collection<int, Session>
     */
    #[ORM\OneToMany(targetEntity: Session::class, mappedBy: 'createur')]
    private Collection $sessionCreateurs;

    /**
     * @var Collection<int, Formation>
     */
    #[ORM\OneToMany(targetEntity: Formation::class, mappedBy: 'createur')]
    private Collection $formationCreateurs;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    /**
     * @var Collection<int, Attestation>
     */
    #[ORM\OneToMany(targetEntity: Attestation::class, mappedBy: 'createur')]
    private Collection $attestationCreateurs;

    /**
     * @var Collection<int, AuditLog>
     */
    #[ORM\OneToMany(targetEntity: AuditLog::class, mappedBy: 'createur')]
    private Collection $AuditLogCreateurs;

    /**
     * @var Collection<int, Avoir>
     */
    #[ORM\OneToMany(targetEntity: Avoir::class, mappedBy: 'createur')]
    private Collection $avoirCreateurs;


    /**
     * @var Collection<int, Categorie>
     */
    #[ORM\OneToMany(targetEntity: Categorie::class, mappedBy: 'createur')]
    private Collection $categorieCreateurs;

    /**
     * @var Collection<int, ContentBlock>
     */
    #[ORM\OneToMany(targetEntity: ContentBlock::class, mappedBy: 'createur')]
    private Collection $contentBlockCreateurs;

    /**
     * @var Collection<int, ContratFormateur>
     */
    #[ORM\OneToMany(targetEntity: ContratFormateur::class, mappedBy: 'createur')]
    private Collection $contratFormateurCreateurs;


    /**
     * @var Collection<int, ContratStagiaire>
     */
    #[ORM\OneToMany(targetEntity: ContratStagiaire::class, mappedBy: 'createur')]
    private Collection $contratStagiaireCreateurs;

    /**
     * @var Collection<int, ConventionContrat>
     */
    #[ORM\OneToMany(targetEntity: ConventionContrat::class, mappedBy: 'createur')]
    private Collection $conventionContratCreateurs;

    /**
     * @var Collection<int, Devis>
     */
    #[ORM\OneToMany(targetEntity: Devis::class, mappedBy: 'createur')]
    private Collection $devisCreateurs;

    /**
     * @var Collection<int, DossierInscription>
     */
    #[ORM\OneToMany(targetEntity: DossierInscription::class, mappedBy: 'createur')]
    private Collection $dossierInscriptionCreateurs;

    /**
     * @var Collection<int, EmailLog>
     */
    #[ORM\OneToMany(targetEntity: EmailLog::class, mappedBy: 'createur')]
    private Collection $emailLogCreateurs;

    /**
     * @var Collection<int, EmailTemplate>
     */
    #[ORM\OneToMany(targetEntity: EmailTemplate::class, mappedBy: 'createur')]
    private Collection $emailTemplateCreateurs;

    /**
     * @var Collection<int, Emargement>
     */
    #[ORM\OneToMany(targetEntity: Emargement::class, mappedBy: 'createur')]
    private Collection $emargementCreateurs;

    /**
     * @var Collection<int, Engin>
     */
    #[ORM\OneToMany(targetEntity: Engin::class, mappedBy: 'createur')]
    private Collection $enginCreateurs;

    /**
     * @var Collection<int, EnginPhoto>
     */
    #[ORM\OneToMany(targetEntity: EnginPhoto::class, mappedBy: 'createur')]
    private Collection $enginPhotoCreateurs;

    /**
     * @var Collection<int, EntitePreferences>
     */
    #[ORM\OneToMany(targetEntity: EntitePreferences::class, mappedBy: 'createur')]
    private Collection $entitePreferenceCreateurs;

    /**
     * @var Collection<int, Entreprise>
     */
    #[ORM\OneToMany(targetEntity: Entreprise::class, mappedBy: 'createur')]
    private Collection $entrepriseCreateurs;

    /**
     * @var Collection<int, Facture>
     */
    #[ORM\OneToMany(targetEntity: Facture::class, mappedBy: 'createur')]
    private Collection $factureCreateurs;


    /**
     * @var Collection<int, FormateurObjectiveEvaluation>
     */
    #[ORM\OneToMany(targetEntity: FormateurObjectiveEvaluation::class, mappedBy: 'createur')]
    private Collection $formateurObjectiveEvaluationCreateurs;

    /**
     * @var Collection<int, FormateurSatisfactionAssignment>
     */
    #[ORM\OneToMany(targetEntity: FormateurSatisfactionAssignment::class, mappedBy: 'createur')]
    private Collection $formateurSatisfactionAssignementCreateurs;

    /**
     * @var Collection<int, FormateurSatisfactionAttempt>
     */
    #[ORM\OneToMany(targetEntity: FormateurSatisfactionAttempt::class, mappedBy: 'createur')]
    private Collection $formateurSatisfactionAttemptCreateurs;

    /**
     * @var Collection<int, FormateurSatisfactionChapter>
     */
    #[ORM\OneToMany(targetEntity: FormateurSatisfactionChapter::class, mappedBy: 'createur')]
    private Collection $formateurStisfactionChapterCreateurs;

    /**
     * @var Collection<int, FormateurSatisfactionQuestion>
     */
    #[ORM\OneToMany(targetEntity: FormateurSatisfactionQuestion::class, mappedBy: 'createur')]
    private Collection $formateurSatisfactionQuestionCreateurs;

    /**
     * @var Collection<int, FormateurSatisfactionTemplate>
     */
    #[ORM\OneToMany(targetEntity: FormateurSatisfactionTemplate::class, mappedBy: 'createur')]
    private Collection $formateurSatisfactionTemplateCreateurs;

    /**
     * @var Collection<int, FormationContentNode>
     */
    #[ORM\OneToMany(targetEntity: FormationContentNode::class, mappedBy: 'createur')]
    private Collection $formationContentNodeCreateurs;

    /**
     * @var Collection<int, FormationObjective>
     */
    #[ORM\OneToMany(targetEntity: FormationObjective::class, mappedBy: 'createur')]
    private Collection $formationObjectiveCreateurs;

    /**
     * @var Collection<int, FormationPhoto>
     */
    #[ORM\OneToMany(targetEntity: FormationPhoto::class, mappedBy: 'createur')]
    private Collection $formationPhotoCreateurs;

    /**
     * @var Collection<int, Inscription>
     */
    #[ORM\OneToMany(targetEntity: Inscription::class, mappedBy: 'createur')]
    private Collection $inscriptionCreateurs;

    /**
     * @var Collection<int, LigneDevis>
     */
    #[ORM\OneToMany(targetEntity: LigneDevis::class, mappedBy: 'createur')]
    private Collection $ligneDevisCreateurs;

    /**
     * @var Collection<int, LigneFacture>
     */
    #[ORM\OneToMany(targetEntity: LigneFacture::class, mappedBy: 'createur')]
    private Collection $ligneFactureCreateurs;

    /**
     * @var Collection<int, Paiement>
     */
    #[ORM\OneToMany(targetEntity: Paiement::class, mappedBy: 'createur')]
    private Collection $paiementCreateurs;

    /**
     * @var Collection<int, PieceDossier>
     */
    #[ORM\OneToMany(targetEntity: PieceDossier::class, mappedBy: 'createur')]
    private Collection $pieceDossierCreateurs;

    /**
     * @var Collection<int, PositioningAnswer>
     */
    #[ORM\OneToMany(targetEntity: PositioningAnswer::class, mappedBy: 'createur')]
    private Collection $positioningAnswerCreateurs;

    /**
     * @var Collection<int, PositioningAssignment>
     */
    #[ORM\OneToMany(targetEntity: PositioningAssignment::class, mappedBy: 'createur')]
    private Collection $positioningAssignementCreateurs;

    /**
     * @var Collection<int, PositioningAttempt>
     */
    #[ORM\OneToMany(targetEntity: PositioningAttempt::class, mappedBy: 'createur')]
    private Collection $positioningAttemptCreateurs;

    /**
     * @var Collection<int, PositioningChapter>
     */
    #[ORM\OneToMany(targetEntity: PositioningChapter::class, mappedBy: 'createur')]
    private Collection $positioningChapterCreateurs;

    /**
     * @var Collection<int, PositioningItem>
     */
    #[ORM\OneToMany(targetEntity: PositioningItem::class, mappedBy: 'createur')]
    private Collection $positioningItemCreateurs;

    /**
     * @var Collection<int, PositioningQuestionnaire>
     */
    #[ORM\OneToMany(targetEntity: PositioningQuestionnaire::class, mappedBy: 'createur')]
    private Collection $positioningQuestionnaireCreateurs;

    /**
     * @var Collection<int, ProspectInteraction>
     */
    #[ORM\OneToMany(targetEntity: ProspectInteraction::class, mappedBy: 'createur')]
    private Collection $prospectInteractionCreateurs;

    /**
     * @var Collection<int, Qcm>
     */
    #[ORM\OneToMany(targetEntity: Qcm::class, mappedBy: 'createur')]
    private Collection $qcmCreateurs;

    /**
     * @var Collection<int, QcmAnswer>
     */
    #[ORM\OneToMany(targetEntity: QcmAnswer::class, mappedBy: 'createur')]
    private Collection $qcmAsnwerCreateurs;

    /**
     * @var Collection<int, QcmAssignment>
     */
    #[ORM\OneToMany(targetEntity: QcmAssignment::class, mappedBy: 'createur')]
    private Collection $qcmAssignementCreateur;

    /**
     * @var Collection<int, QcmAttempt>
     */
    #[ORM\OneToMany(targetEntity: QcmAttempt::class, mappedBy: 'createur')]
    private Collection $qcmAttemptCreateurs;

    /**
     * @var Collection<int, QcmOption>
     */
    #[ORM\OneToMany(targetEntity: QcmOption::class, mappedBy: 'createur')]
    private Collection $qcmOptionCreateurs;

    /**
     * @var Collection<int, QcmQuestion>
     */
    #[ORM\OneToMany(targetEntity: QcmQuestion::class, mappedBy: 'createur')]
    private Collection $qcmQuestionCreateurs;

    /**
     * @var Collection<int, QuestionnaireSatisfaction>
     */
    #[ORM\OneToMany(targetEntity: QuestionnaireSatisfaction::class, mappedBy: 'createur')]
    private Collection $questionnaireSatisfactionCreateurs;

    /**
     * @var Collection<int, Quiz>
     */
    #[ORM\OneToMany(targetEntity: Quiz::class, mappedBy: 'createur')]
    private Collection $quizCreateurs;

    /**
     * @var Collection<int, QuizAnswer>
     */
    #[ORM\OneToMany(targetEntity: QuizAnswer::class, mappedBy: 'createur')]
    private Collection $quizAnswerCreateurs;

    /**
     * @var Collection<int, QuizAttempt>
     */
    #[ORM\OneToMany(targetEntity: QuizAttempt::class, mappedBy: 'createur')]
    private Collection $quizAttemptCreateurs;

    /**
     * @var Collection<int, QuizChoice>
     */
    #[ORM\OneToMany(targetEntity: QuizChoice::class, mappedBy: 'createur')]
    private Collection $quizChoiceCreateurs;

    /**
     * @var Collection<int, QuizQuestion>
     */
    #[ORM\OneToMany(targetEntity: QuizQuestion::class, mappedBy: 'createur')]
    private Collection $quizQuestionCreateurs;

    /**
     * @var Collection<int, RapportFormateur>
     */
    #[ORM\OneToMany(targetEntity: RapportFormateur::class, mappedBy: 'createur')]
    private Collection $rapportFormateurCreateurs;

    /**
     * @var Collection<int, Reservation>
     */
    #[ORM\OneToMany(targetEntity: Reservation::class, mappedBy: 'createur')]
    private Collection $reservationCreateurs;

    /**
     * @var Collection<int, SatisfactionAssignment>
     */
    #[ORM\OneToMany(targetEntity: SatisfactionAssignment::class, mappedBy: 'createur')]
    private Collection $satisfactionAssignementCreateurs;

    /**
     * @var Collection<int, SatisfactionAttempt>
     */
    #[ORM\OneToMany(targetEntity: SatisfactionAttempt::class, mappedBy: 'createur')]
    private Collection $satisfactionAttemptCreateurs;

    /**
     * @var Collection<int, SatisfactionChapter>
     */
    #[ORM\OneToMany(targetEntity: SatisfactionChapter::class, mappedBy: 'createur')]
    private Collection $satisfactionChapterCreateurs;

    /**
     * @var Collection<int, SatisfactionQuestion>
     */
    #[ORM\OneToMany(targetEntity: SatisfactionQuestion::class, mappedBy: 'createur')]
    private Collection $satisfactionQuestionCreateurs;

    /**
     * @var Collection<int, SatisfactionTemplate>
     */
    #[ORM\OneToMany(targetEntity: SatisfactionTemplate::class, mappedBy: 'createur')]
    private Collection $satisfactionTemplateCreateurs;

    /**
     * @var Collection<int, SessionJour>
     */
    #[ORM\OneToMany(targetEntity: SessionJour::class, mappedBy: 'createur')]
    private Collection $sessionJourCreateurs;

    /**
     * @var Collection<int, SessionPositioning>
     */
    #[ORM\OneToMany(targetEntity: SessionPositioning::class, mappedBy: 'createur')]
    private Collection $sessionPositioningCreateurs;

    /**
     * @var Collection<int, Site>
     */
    #[ORM\OneToMany(targetEntity: Site::class, mappedBy: 'createur')]
    private Collection $siteCreateurs;

    /**
     * @var Collection<int, SupportAsset>
     */
    #[ORM\OneToMany(targetEntity: SupportAsset::class, mappedBy: 'createur')]
    private Collection $supportAssetCreateurs;

    /**
     * @var Collection<int, SupportAssignSession>
     */
    #[ORM\OneToMany(targetEntity: SupportAssignSession::class, mappedBy: 'createur')]
    private Collection $supportAssignSessionCreateurs;

    /**
     * @var Collection<int, SupportAssignUser>
     */
    #[ORM\OneToMany(targetEntity: SupportAssignUser::class, mappedBy: 'createur')]
    private Collection $supportAssignUserCreateurs;

    /**
     * @var Collection<int, SupportDocument>
     */
    #[ORM\OneToMany(targetEntity: SupportDocument::class, mappedBy: 'createur')]
    private Collection $supportDocumentCreateurs;

    /**
     * @var Collection<int, UtilisateurEntite>
     */
    #[ORM\OneToMany(targetEntity: UtilisateurEntite::class, mappedBy: 'createur')]
    private Collection $utilisateurEntiteCreateurs;


    #[ORM\OneToMany(mappedBy: 'createur', targetEntity: Depense::class)]
    private Collection $depenseCreateurs;

    #[ORM\OneToMany(mappedBy: 'payeur', targetEntity: Depense::class)]
    private Collection $depensesPayees;

    /**
     * @var Collection<int, Paiement>
     */
    #[ORM\OneToMany(targetEntity: Paiement::class, mappedBy: 'payeurUtilisateur')]
    private Collection $paiements;

    /**
     * @var Collection<int, FiscalProfile>
     */
    #[ORM\OneToMany(targetEntity: FiscalProfile::class, mappedBy: 'createur')]
    private Collection $fiscalProfileCreateurs;

    /**
     * @var Collection<int, TaxRule>
     */
    #[ORM\OneToMany(targetEntity: TaxRule::class, mappedBy: 'createur')]
    private Collection $taxRuleCreateurs;

    /**
     * @var Collection<int, TaxComputation>
     */
    #[ORM\OneToMany(targetEntity: TaxComputation::class, mappedBy: 'createur')]
    private Collection $taxComputationCreateurs;

    /**
     * @var Collection<int, ElearningCourse>
     */
    #[ORM\OneToMany(targetEntity: ElearningCourse::class, mappedBy: 'createur')]
    private Collection $elearningCourseCreateurs;

    /**
     * @var Collection<int, ElearningNode>
     */
    #[ORM\OneToMany(targetEntity: ElearningNode::class, mappedBy: 'createur')]
    private Collection $elearningNodeCreateurs;

    /**
     * @var Collection<int, ElearningBlock>
     */
    #[ORM\OneToMany(targetEntity: ElearningBlock::class, mappedBy: 'createur')]
    private Collection $elearningBlockCreateurs;

    /**
     * @var Collection<int, ElearningEnrollment>
     */
    #[ORM\OneToMany(targetEntity: ElearningEnrollment::class, mappedBy: 'stagiaire')]
    private Collection $elearningEnrollments;

    /**
     * @var Collection<int, ElearningEnrollment>
     */
    #[ORM\OneToMany(targetEntity: ElearningEnrollment::class, mappedBy: 'createur')]
    private Collection $elearningEnrollmentCreateur;

    /**
     * @var Collection<int, ElearningOrder>
     */
    #[ORM\OneToMany(targetEntity: ElearningOrder::class, mappedBy: 'buyer')]
    private Collection $elearningOrders;

    /**
     * @var Collection<int, EntrepriseDocument>
     */
    #[ORM\OneToMany(targetEntity: EntrepriseDocument::class, mappedBy: 'uploadedBy')]
    private Collection $entrepriseDocuments;

    /**
     * @var Collection<int, EntrepriseDocument>
     */
    #[ORM\OneToMany(targetEntity: EntrepriseDocument::class, mappedBy: 'createur')]
    private Collection $entrepriseDocumentCreateurs;

    /**
     * @var Collection<int, Entreprise>
     */
    #[ORM\OneToMany(targetEntity: Entreprise::class, mappedBy: 'representant')]
    private Collection $entreprises;



    public function __construct()
    {
        $this->entites = new ArrayCollection();
        $this->utilisateurEntites = new ArrayCollection();
        $this->utilisateursCreateur = new ArrayCollection();
        $this->reservations = new ArrayCollection();
        $this->emargements = new ArrayCollection();
        $this->supportDocuments = new ArrayCollection();
        $this->supportAssets = new ArrayCollection();
        $this->supportAssignUsers = new ArrayCollection();
        $this->inscriptions = new ArrayCollection();
        $this->factures = new ArrayCollection();
        $this->quizAttempts = new ArrayCollection();
        $this->questionnaireSatisfactions = new ArrayCollection();
        $this->auditLogs = new ArrayCollection();
        $this->devis = new ArrayCollection();
        $this->positioningAttempts = new ArrayCollection();
        $this->positioningAssignments = new ArrayCollection();
        $this->positioningAssignementsFormateur = new ArrayCollection();
        $this->satisfactionAssignments = new ArrayCollection();
        $this->formateurSatisfactionAssignments = new ArrayCollection();
        $this->formateurObjectiveEvaluations = new ArrayCollection();
        $this->prospects = new ArrayCollection();
        $this->prospectInteractions = new ArrayCollection();
        $this->sentEmailLogs = new ArrayCollection();
        $this->receivedEmailLogs = new ArrayCollection();
        $this->prospectInteractionsUtilisateurs = new ArrayCollection();
        $this->qcmAssignments = new ArrayCollection();
        $this->formateurs = new ArrayCollection();
        $this->prospectCreateurs = new ArrayCollection();
        $this->sessionCreateurs = new ArrayCollection();
        $this->formationCreateurs = new ArrayCollection();
        $this->dateCreation = new \DateTimeImmutable();
        $this->attestationCreateurs = new ArrayCollection();
        $this->AuditLogCreateurs = new ArrayCollection();
        $this->avoirCreateurs = new ArrayCollection();
        $this->categorieCreateurs = new ArrayCollection();
        $this->contentBlockCreateurs = new ArrayCollection();
        $this->contratFormateurCreateurs = new ArrayCollection();
        $this->contratStagiaireCreateurs = new ArrayCollection();
        $this->conventionContratCreateurs = new ArrayCollection();
        $this->devisCreateurs = new ArrayCollection();
        $this->dossierInscriptionCreateurs = new ArrayCollection();
        $this->emailLogCreateurs = new ArrayCollection();
        $this->emailTemplateCreateurs = new ArrayCollection();
        $this->emargementCreateurs = new ArrayCollection();
        $this->enginCreateurs = new ArrayCollection();
        $this->enginPhotoCreateurs = new ArrayCollection();
        $this->entitePreferenceCreateurs = new ArrayCollection();
        $this->entrepriseCreateurs = new ArrayCollection();
        $this->factureCreateurs = new ArrayCollection();
        $this->formateurObjectiveEvaluationCreateurs = new ArrayCollection();
        $this->formateurSatisfactionAssignementCreateurs = new ArrayCollection();
        $this->formateurSatisfactionAttemptCreateurs = new ArrayCollection();
        $this->formateurStisfactionChapterCreateurs = new ArrayCollection();
        $this->formateurSatisfactionQuestionCreateurs = new ArrayCollection();
        $this->formateurSatisfactionTemplateCreateurs = new ArrayCollection();
        $this->formationContentNodeCreateurs = new ArrayCollection();
        $this->formationObjectiveCreateurs = new ArrayCollection();
        $this->formationPhotoCreateurs = new ArrayCollection();
        $this->inscriptionCreateurs = new ArrayCollection();
        $this->ligneDevisCreateurs = new ArrayCollection();
        $this->ligneFactureCreateurs = new ArrayCollection();
        $this->paiementCreateurs = new ArrayCollection();
        $this->pieceDossierCreateurs = new ArrayCollection();
        $this->positioningAnswerCreateurs = new ArrayCollection();
        $this->positioningAssignementCreateurs = new ArrayCollection();
        $this->positioningAttemptCreateurs = new ArrayCollection();
        $this->positioningChapterCreateurs = new ArrayCollection();
        $this->positioningItemCreateurs = new ArrayCollection();
        $this->positioningQuestionnaireCreateurs = new ArrayCollection();
        $this->prospectInteractionCreateurs = new ArrayCollection();
        $this->qcmCreateurs = new ArrayCollection();
        $this->qcmAsnwerCreateurs = new ArrayCollection();
        $this->qcmAssignementCreateur = new ArrayCollection();
        $this->qcmAttemptCreateurs = new ArrayCollection();
        $this->qcmOptionCreateurs = new ArrayCollection();
        $this->qcmQuestionCreateurs = new ArrayCollection();
        $this->questionnaireSatisfactionCreateurs = new ArrayCollection();
        $this->quizCreateurs = new ArrayCollection();
        $this->quizAnswerCreateurs = new ArrayCollection();
        $this->quizAttemptCreateurs = new ArrayCollection();
        $this->quizChoiceCreateurs = new ArrayCollection();
        $this->quizQuestionCreateurs = new ArrayCollection();
        $this->rapportFormateurCreateurs = new ArrayCollection();
        $this->reservationCreateurs = new ArrayCollection();
        $this->satisfactionAssignementCreateurs = new ArrayCollection();
        $this->satisfactionAttemptCreateurs = new ArrayCollection();
        $this->satisfactionChapterCreateurs = new ArrayCollection();
        $this->satisfactionQuestionCreateurs = new ArrayCollection();
        $this->satisfactionTemplateCreateurs = new ArrayCollection();
        $this->sessionJourCreateurs = new ArrayCollection();
        $this->sessionPositioningCreateurs = new ArrayCollection();
        $this->siteCreateurs = new ArrayCollection();
        $this->supportAssetCreateurs = new ArrayCollection();
        $this->supportAssignSessionCreateurs = new ArrayCollection();
        $this->supportAssignUserCreateurs = new ArrayCollection();
        $this->supportDocumentCreateurs = new ArrayCollection();
        $this->utilisateurEntiteCreateurs = new ArrayCollection();
        $this->depenseCreateurs = new ArrayCollection();
        $this->depensesPayees = new ArrayCollection();
        $this->paiements = new ArrayCollection();
        $this->fiscalProfileCreateurs = new ArrayCollection();
        $this->taxRuleCreateurs = new ArrayCollection();
        $this->taxComputationCreateurs = new ArrayCollection();
        $this->elearningCourseCreateurs = new ArrayCollection();
        $this->elearningNodeCreateurs = new ArrayCollection();
        $this->elearningBlockCreateurs = new ArrayCollection();
        $this->elearningEnrollments = new ArrayCollection();
        $this->elearningEnrollmentCreateur = new ArrayCollection();
        $this->elearningOrders = new ArrayCollection();
        $this->entrepriseDocuments = new ArrayCollection();
        $this->entrepriseDocumentCreateurs = new ArrayCollection();
        $this->entreprises = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';
        return array_values(array_unique($roles));
    }

    public function isSuperAdmin(): bool
    {
        return in_array('ROLE_SUPER_ADMIN', $this->getRoles(), true);
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function eraseCredentials(): void
    {
        // @deprecated, to be removed when upgrading to Symfony 8
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function setIsVerified(bool $isVerified): static
    {
        $this->isVerified = $isVerified;

        return $this;
    }

    /**
     * @return Collection<int, Entite>
     */
    public function getEntites(): Collection
    {
        return $this->entites;
    }

    public function addEntite(Entite $entite): static
    {
        if (!$this->entites->contains($entite)) {
            $this->entites->add($entite);
            $entite->setCreateur($this);
        }

        return $this;
    }

    public function removeEntite(Entite $entite): static
    {
        if ($this->entites->removeElement($entite)) {
            // set the owning side to null (unless already changed)
            if ($entite->getCreateur() === $this) {
                $entite->setCreateur(null);
            }
        }

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
            $utilisateurEntite->setUtilisateur($this);
        }

        return $this;
    }

    public function removeUtilisateurEntite(UtilisateurEntite $utilisateurEntite): static
    {
        if ($this->utilisateurEntites->removeElement($utilisateurEntite)) {
            // set the owning side to null (unless already changed)
            if ($utilisateurEntite->getUtilisateur() === $this) {
                $utilisateurEntite->setUtilisateur(null);
            }
        }

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

    public function getPrenom(): ?string
    {
        return $this->prenom;
    }

    public function setPrenom(string $prenom): static
    {
        $this->prenom = $prenom;

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

    public function getCouleur(): ?string
    {
        return $this->couleur;
    }

    public function setCouleur(?string $couleur): static
    {
        $this->couleur = $couleur;

        return $this;
    }

    public function getCreateur(): ?self
    {
        return $this->createur;
    }

    public function setCreateur(?self $createur): static
    {
        $this->createur = $createur;

        return $this;
    }

    /**
     * @return Collection<int, self>
     */
    public function getUtilisateursCreateur(): Collection
    {
        return $this->utilisateursCreateur;
    }

    public function addUtilisateursCreateur(self $utilisateursCreateur): static
    {
        if (!$this->utilisateursCreateur->contains($utilisateursCreateur)) {
            $this->utilisateursCreateur->add($utilisateursCreateur);
            $utilisateursCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeUtilisateursCreateur(self $utilisateursCreateur): static
    {
        if ($this->utilisateursCreateur->removeElement($utilisateursCreateur)) {
            // set the owning side to null (unless already changed)
            if ($utilisateursCreateur->getCreateur() === $this) {
                $utilisateursCreateur->setCreateur(null);
            }
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

    public function getPhoto(): ?string
    {
        return $this->photo;
    }

    public function setPhoto(?string $photo): static
    {
        $this->photo = $photo;

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

    public function getDateNaissance(): ?\DateTimeImmutable
    {
        return $this->dateNaissance;
    }

    public function setDateNaissance(?\DateTimeImmutable $dateNaissance): static
    {
        $this->dateNaissance = $dateNaissance;

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

    public function getCivilite(): ?string
    {
        return $this->civilite;
    }

    public function setCivilite(?string $civilite): static
    {
        $this->civilite = $civilite;

        return $this;
    }

    public function getResetToken(): ?string
    {
        return $this->resetToken;
    }

    public function setResetToken(?string $resetToken): static
    {
        $this->resetToken = $resetToken;

        return $this;
    }


    public function getResetTokenExpiresAt(): ?\DateTimeImmutable
    {
        return $this->resetTokenExpiresAt;
    }

    public function setResetTokenExpiresAt(?\DateTimeImmutable $resetTokenExpiresAt): static
    {
        $this->resetTokenExpiresAt = $resetTokenExpiresAt;

        return $this;
    }

    public function getAbonnement(): ?string
    {
        return $this->abonnement;
    }

    public function setAbonnement(?string $abonnement): static
    {
        $this->abonnement = $abonnement;

        return $this;
    }

    public function getStripeCustomerId(): ?string
    {
        return $this->stripeCustomerId;
    }

    public function setStripeCustomerId(?string $stripeCustomerId): static
    {
        $this->stripeCustomerId = $stripeCustomerId;

        return $this;
    }

    public function getStripeSubscriptionId(): ?string
    {
        return $this->stripeSubscriptionId;
    }

    public function setStripeSubscriptionId(?string $stripeSubscriptionId): static
    {
        $this->stripeSubscriptionId = $stripeSubscriptionId;

        return $this;
    }

    public function getNumeroLicence(): ?string
    {
        return $this->numeroLicence;
    }

    public function setNumeroLicence(?string $numeroLicence): static
    {
        $this->numeroLicence = $numeroLicence;

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

    public function isDesactiverTemporairement(): ?bool
    {
        return $this->desactiverTemporairement;
    }

    public function setDesactiverTemporairement(bool $desactiverTemporairement): static
    {
        $this->desactiverTemporairement = $desactiverTemporairement;

        return $this;
    }

    public function isBannir(): ?bool
    {
        return $this->bannir;
    }

    public function setBannir(bool $bannir): static
    {
        $this->bannir = $bannir;

        return $this;
    }

    public function getUnreadCount(): ?int
    {
        return $this->unreadCount;
    }

    public function setUnreadCount(?int $unreadCount): static
    {
        $this->unreadCount = $unreadCount;

        return $this;
    }

    public function isConsentementRgpd(): ?bool
    {
        return $this->consentementRgpd;
    }

    public function setConsentementRgpd(?bool $consentementRgpd): static
    {
        $this->consentementRgpd = $consentementRgpd;

        return $this;
    }

    public function getDateConsentementRgpd(): ?\DateTimeImmutable
    {
        return $this->dateConsentementRgpd;
    }

    public function setDateConsentementRgpd(?\DateTimeImmutable $dateConsentementRgpd): static
    {
        $this->dateConsentementRgpd = $dateConsentementRgpd;

        return $this;
    }

    public function isNewsletter(): ?bool
    {
        return $this->newsletter;
    }

    public function setNewsletter(?bool $newsletter): static
    {
        $this->newsletter = $newsletter;

        return $this;
    }

    public function isMailBienvenue(): ?bool
    {
        return $this->mailBienvenue;
    }

    public function setMailBienvenue(?bool $mailBienvenue): static
    {
        $this->mailBienvenue = $mailBienvenue;

        return $this;
    }

    public function getNiveau(): ?string
    {
        return $this->niveau;
    }

    public function setNiveau(?string $niveau): static
    {
        $this->niveau = $niveau;

        return $this;
    }

    public function isMailSortie(): ?bool
    {
        return $this->mailSortie;
    }

    public function setMailSortie(?bool $mailSortie): static
    {
        $this->mailSortie = $mailSortie;

        return $this;
    }

    public function getFormateur(): ?Formateur
    {
        return $this->formateur;
    }

    public function setFormateur(?Formateur $formateur): static
    {
        // set the owning side of the relation if necessary
        if ($formateur->getUtilisateur() !== $this) {
            $formateur->setUtilisateur($this);
        }

        $this->formateur = $formateur;

        return $this;
    }

    /**
     * @return Collection<int, Reservation>
     */
    public function getReservations(): Collection
    {
        return $this->reservations;
    }

    public function addReservation(Reservation $reservation): static
    {
        if (!$this->reservations->contains($reservation)) {
            $this->reservations->add($reservation);
            $reservation->setUtilisateur($this);
        }

        return $this;
    }

    public function removeReservation(Reservation $reservation): static
    {
        if ($this->reservations->removeElement($reservation)) {
            // set the owning side to null (unless already changed)
            if ($reservation->getUtilisateur() === $this) {
                $reservation->setUtilisateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Emargement>
     */
    public function getEmargements(): Collection
    {
        return $this->emargements;
    }

    public function addEmargement(Emargement $emargement): static
    {
        if (!$this->emargements->contains($emargement)) {
            $this->emargements->add($emargement);
            $emargement->setUtilisateur($this);
        }

        return $this;
    }

    public function removeEmargement(Emargement $emargement): static
    {
        if ($this->emargements->removeElement($emargement)) {
            // set the owning side to null (unless already changed)
            if ($emargement->getUtilisateur() === $this) {
                $emargement->setUtilisateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, SupportDocument>
     */
    public function getSupportDocuments(): Collection
    {
        return $this->supportDocuments;
    }

    public function addSupportDocument(SupportDocument $supportDocument): static
    {
        if (!$this->supportDocuments->contains($supportDocument)) {
            $this->supportDocuments->add($supportDocument);
            $supportDocument->setUploadedBy($this);
        }

        return $this;
    }

    public function removeSupportDocument(SupportDocument $supportDocument): static
    {
        if ($this->supportDocuments->removeElement($supportDocument)) {
            // set the owning side to null (unless already changed)
            if ($supportDocument->getUploadedBy() === $this) {
                $supportDocument->setUploadedBy(null);
            }
        }

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
            $supportAsset->setUploadedBy($this);
        }

        return $this;
    }

    public function removeSupportAsset(SupportAsset $supportAsset): static
    {
        if ($this->supportAssets->removeElement($supportAsset)) {
            // set the owning side to null (unless already changed)
            if ($supportAsset->getUploadedBy() === $this) {
                $supportAsset->setUploadedBy(null);
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
            $supportAssignUser->setUser($this);
        }

        return $this;
    }

    public function removeSupportAssignUser(SupportAssignUser $supportAssignUser): static
    {
        if ($this->supportAssignUsers->removeElement($supportAssignUser)) {
            // set the owning side to null (unless already changed)
            if ($supportAssignUser->getUser() === $this) {
                $supportAssignUser->setUser(null);
            }
        }

        return $this;
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
            $inscription->setStagiaire($this);
        }

        return $this;
    }

    public function removeInscription(Inscription $inscription): static
    {
        if ($this->inscriptions->removeElement($inscription)) {
            // set the owning side to null (unless already changed)
            if ($inscription->getStagiaire() === $this) {
                $inscription->setStagiaire(null);
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
            $facture->setDestinataire($this);
        }

        return $this;
    }

    public function removeFacture(Facture $facture): static
    {
        if ($this->factures->removeElement($facture)) {
            // set the owning side to null (unless already changed)
            if ($facture->getDestinataire() === $this) {
                $facture->setDestinataire(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, QuizAttempt>
     */
    public function getQuizAttempts(): Collection
    {
        return $this->quizAttempts;
    }

    public function addQuizAttempt(QuizAttempt $quizAttempt): static
    {
        if (!$this->quizAttempts->contains($quizAttempt)) {
            $this->quizAttempts->add($quizAttempt);
            $quizAttempt->setStagiaire($this);
        }

        return $this;
    }

    public function removeQuizAttempt(QuizAttempt $quizAttempt): static
    {
        if ($this->quizAttempts->removeElement($quizAttempt)) {
            // set the owning side to null (unless already changed)
            if ($quizAttempt->getStagiaire() === $this) {
                $quizAttempt->setStagiaire(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, QuestionnaireSatisfaction>
     */
    public function getQuestionnaireSatisfactions(): Collection
    {
        return $this->questionnaireSatisfactions;
    }

    public function addQuestionnaireSatisfaction(QuestionnaireSatisfaction $questionnaireSatisfaction): static
    {
        if (!$this->questionnaireSatisfactions->contains($questionnaireSatisfaction)) {
            $this->questionnaireSatisfactions->add($questionnaireSatisfaction);
            $questionnaireSatisfaction->setStagiaire($this);
        }

        return $this;
    }

    public function removeQuestionnaireSatisfaction(QuestionnaireSatisfaction $questionnaireSatisfaction): static
    {
        if ($this->questionnaireSatisfactions->removeElement($questionnaireSatisfaction)) {
            // set the owning side to null (unless already changed)
            if ($questionnaireSatisfaction->getStagiaire() === $this) {
                $questionnaireSatisfaction->setStagiaire(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, AuditLog>
     */
    public function getAuditLogs(): Collection
    {
        return $this->auditLogs;
    }

    public function addAuditLog(AuditLog $auditLog): static
    {
        if (!$this->auditLogs->contains($auditLog)) {
            $this->auditLogs->add($auditLog);
            $auditLog->setActor($this);
        }

        return $this;
    }

    public function removeAuditLog(AuditLog $auditLog): static
    {
        if ($this->auditLogs->removeElement($auditLog)) {
            // set the owning side to null (unless already changed)
            if ($auditLog->getActor() === $this) {
                $auditLog->setActor(null);
            }
        }

        return $this;
    }

    public function getSociete(): ?string
    {
        return $this->societe;
    }

    public function setSociete(?string $societe): static
    {
        $this->societe = $societe;

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
            $devi->setDestinataire($this);
        }

        return $this;
    }

    public function removeDevi(Devis $devi): static
    {
        if ($this->devis->removeElement($devi)) {
            // set the owning side to null (unless already changed)
            if ($devi->getDestinataire() === $this) {
                $devi->setDestinataire(null);
            }
        }

        return $this;
    }


    public function __toString(): string
    {
        return $this->prenom . ' ' . $this->nom;
    }

    /**
     * @return Collection<int, PositioningAttempt>
     */
    public function getPositioningAttempts(): Collection
    {
        return $this->positioningAttempts;
    }

    public function addPositioningAttempt(PositioningAttempt $positioningAttempt): static
    {
        if (!$this->positioningAttempts->contains($positioningAttempt)) {
            $this->positioningAttempts->add($positioningAttempt);
            $positioningAttempt->setStagiaire($this);
        }

        return $this;
    }

    public function removePositioningAttempt(PositioningAttempt $positioningAttempt): static
    {
        if ($this->positioningAttempts->removeElement($positioningAttempt)) {
            // set the owning side to null (unless already changed)
            if ($positioningAttempt->getStagiaire() === $this) {
                $positioningAttempt->setStagiaire(null);
            }
        }

        return $this;
    }
    /** @return Collection<int, PositioningAssignment> */
    public function getPositioningAssignments(): Collection
    {
        return $this->positioningAssignments;
    }

    /**
     * @return Collection<int, PositioningAssignment>
     */
    public function getPositioningAssignementsFormateur(): Collection
    {
        return $this->positioningAssignementsFormateur;
    }

    public function addPositioningAssignementsFormateur(PositioningAssignment $positioningAssignementsFormateur): static
    {
        if (!$this->positioningAssignementsFormateur->contains($positioningAssignementsFormateur)) {
            $this->positioningAssignementsFormateur->add($positioningAssignementsFormateur);
            $positioningAssignementsFormateur->setEvaluator($this);
        }

        return $this;
    }

    public function removePositioningAssignementsFormateur(PositioningAssignment $positioningAssignementsFormateur): static
    {
        if ($this->positioningAssignementsFormateur->removeElement($positioningAssignementsFormateur)) {
            // set the owning side to null (unless already changed)
            if ($positioningAssignementsFormateur->getEvaluator() === $this) {
                $positioningAssignementsFormateur->setEvaluator(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, SatisfactionAssignment>
     */
    public function getSatisfactionAssignments(): Collection
    {
        return $this->satisfactionAssignments;
    }

    public function addSatisfactionAssignment(SatisfactionAssignment $satisfactionAssignment): static
    {
        if (!$this->satisfactionAssignments->contains($satisfactionAssignment)) {
            $this->satisfactionAssignments->add($satisfactionAssignment);
            $satisfactionAssignment->setStagiaire($this);
        }

        return $this;
    }

    public function removeSatisfactionAssignment(SatisfactionAssignment $satisfactionAssignment): static
    {
        if ($this->satisfactionAssignments->removeElement($satisfactionAssignment)) {
            // set the owning side to null (unless already changed)
            if ($satisfactionAssignment->getStagiaire() === $this) {
                $satisfactionAssignment->setStagiaire(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, FormateurSatisfactionAssignment>
     */
    public function getFormateurSatisfactionAssignments(): Collection
    {
        return $this->formateurSatisfactionAssignments;
    }

    public function addFormateurSatisfactionAssignment(FormateurSatisfactionAssignment $formateurSatisfactionAssignment): static
    {
        if (!$this->formateurSatisfactionAssignments->contains($formateurSatisfactionAssignment)) {
            $this->formateurSatisfactionAssignments->add($formateurSatisfactionAssignment);
            $formateurSatisfactionAssignment->setFormateur($this);
        }

        return $this;
    }

    public function removeFormateurSatisfactionAssignment(FormateurSatisfactionAssignment $formateurSatisfactionAssignment): static
    {
        if ($this->formateurSatisfactionAssignments->removeElement($formateurSatisfactionAssignment)) {
            // set the owning side to null (unless already changed)
            if ($formateurSatisfactionAssignment->getFormateur() === $this) {
                $formateurSatisfactionAssignment->setFormateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, FormateurObjectiveEvaluation>
     */
    public function getFormateurObjectiveEvaluations(): Collection
    {
        return $this->formateurObjectiveEvaluations;
    }

    public function addFormateurObjectiveEvaluation(FormateurObjectiveEvaluation $formateurObjectiveEvaluation): static
    {
        if (!$this->formateurObjectiveEvaluations->contains($formateurObjectiveEvaluation)) {
            $this->formateurObjectiveEvaluations->add($formateurObjectiveEvaluation);
            $formateurObjectiveEvaluation->setStagiaire($this);
        }

        return $this;
    }

    public function removeFormateurObjectiveEvaluation(FormateurObjectiveEvaluation $formateurObjectiveEvaluation): static
    {
        if ($this->formateurObjectiveEvaluations->removeElement($formateurObjectiveEvaluation)) {
            // set the owning side to null (unless already changed)
            if ($formateurObjectiveEvaluation->getStagiaire() === $this) {
                $formateurObjectiveEvaluation->setStagiaire(null);
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
            $prospect->setLinkedUser($this);
        }

        return $this;
    }

    public function removeProspect(Prospect $prospect): static
    {
        if ($this->prospects->removeElement($prospect)) {
            // set the owning side to null (unless already changed)
            if ($prospect->getLinkedUser() === $this) {
                $prospect->setLinkedUser(null);
            }
        }

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
            $prospectInteraction->setActor($this);
        }

        return $this;
    }

    public function removeProspectInteraction(ProspectInteraction $prospectInteraction): static
    {
        if ($this->prospectInteractions->removeElement($prospectInteraction)) {
            // set the owning side to null (unless already changed)
            if ($prospectInteraction->getActor() === $this) {
                $prospectInteraction->setActor(null);
            }
        }

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
    public function getAdresseComplete(): ?string
    {
        $parts = array_filter([
            $this->adresse,
            $this->complement,
            $this->codePostal,
            $this->ville,
            $this->pays,
        ], static fn($v) => is_string($v) && trim($v) !== '');

        $full = trim(implode(', ', array_map('trim', $parts)));

        return $full !== '' ? $full : null;
    }

    /**
     * @return Collection<int, EmailLog>
     */
    public function getSentEmailLogs(): Collection
    {
        return $this->sentEmailLogs;
    }

    public function addSentEmailLog(EmailLog $sentEmailLog): static
    {
        if (!$this->sentEmailLogs->contains($sentEmailLog)) {
            $this->sentEmailLogs->add($sentEmailLog);
            $sentEmailLog->setActor($this);
        }

        return $this;
    }

    public function removeSentEmailLog(EmailLog $sentEmailLog): static
    {
        if ($this->sentEmailLogs->removeElement($sentEmailLog)) {
            // set the owning side to null (unless already changed)
            if ($sentEmailLog->getActor() === $this) {
                $sentEmailLog->setActor(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, EmailLog>
     */
    public function getReceivedEmailLogs(): Collection
    {
        return $this->receivedEmailLogs;
    }

    public function addReceivedEmailLog(EmailLog $receivedEmailLog): static
    {
        if (!$this->receivedEmailLogs->contains($receivedEmailLog)) {
            $this->receivedEmailLogs->add($receivedEmailLog);
            $receivedEmailLog->setToUser($this);
        }

        return $this;
    }

    public function removeReceivedEmailLog(EmailLog $receivedEmailLog): static
    {
        if ($this->receivedEmailLogs->removeElement($receivedEmailLog)) {
            // set the owning side to null (unless already changed)
            if ($receivedEmailLog->getToUser() === $this) {
                $receivedEmailLog->setToUser(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ProspectInteraction>
     */
    public function getProspectInteractionsUtilisateurs(): Collection
    {
        return $this->prospectInteractionsUtilisateurs;
    }

    public function addProspectInteractionsUtilisateur(ProspectInteraction $prospectInteractionsUtilisateur): static
    {
        if (!$this->prospectInteractionsUtilisateurs->contains($prospectInteractionsUtilisateur)) {
            $this->prospectInteractionsUtilisateurs->add($prospectInteractionsUtilisateur);
            $prospectInteractionsUtilisateur->setUtilisateur($this);
        }

        return $this;
    }

    public function removeProspectInteractionsUtilisateur(ProspectInteraction $prospectInteractionsUtilisateur): static
    {
        if ($this->prospectInteractionsUtilisateurs->removeElement($prospectInteractionsUtilisateur)) {
            // set the owning side to null (unless already changed)
            if ($prospectInteractionsUtilisateur->getUtilisateur() === $this) {
                $prospectInteractionsUtilisateur->setUtilisateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, QcmAssignment>
     */
    public function getQcmAssignments(): Collection
    {
        return $this->qcmAssignments;
    }

    public function addQcmAssignment(QcmAssignment $qcmAssignment): static
    {
        if (!$this->qcmAssignments->contains($qcmAssignment)) {
            $this->qcmAssignments->add($qcmAssignment);
            $qcmAssignment->setAdminFollowUpBy($this);
        }

        return $this;
    }

    public function removeQcmAssignment(QcmAssignment $qcmAssignment): static
    {
        if ($this->qcmAssignments->removeElement($qcmAssignment)) {
            // set the owning side to null (unless already changed)
            if ($qcmAssignment->getAdminFollowUpBy() === $this) {
                $qcmAssignment->setAdminFollowUpBy(null);
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
            $formateur->setCreateur($this);
        }

        return $this;
    }

    public function removeFormateur(Formateur $formateur): static
    {
        if ($this->formateurs->removeElement($formateur)) {
            // set the owning side to null (unless already changed)
            if ($formateur->getCreateur() === $this) {
                $formateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Prospect>
     */
    public function getProspectCreateurs(): Collection
    {
        return $this->prospectCreateurs;
    }

    public function addProspectCreateur(Prospect $prospectCreateur): static
    {
        if (!$this->prospectCreateurs->contains($prospectCreateur)) {
            $this->prospectCreateurs->add($prospectCreateur);
            $prospectCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeProspectCreateur(Prospect $prospectCreateur): static
    {
        if ($this->prospectCreateurs->removeElement($prospectCreateur)) {
            // set the owning side to null (unless already changed)
            if ($prospectCreateur->getCreateur() === $this) {
                $prospectCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Session>
     */
    public function getSessionCreateurs(): Collection
    {
        return $this->sessionCreateurs;
    }

    public function addSessionCreateur(Session $sessionCreateur): static
    {
        if (!$this->sessionCreateurs->contains($sessionCreateur)) {
            $this->sessionCreateurs->add($sessionCreateur);
            $sessionCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeSessionCreateur(Session $sessionCreateur): static
    {
        if ($this->sessionCreateurs->removeElement($sessionCreateur)) {
            // set the owning side to null (unless already changed)
            if ($sessionCreateur->getCreateur() === $this) {
                $sessionCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Formation>
     */
    public function getFormationCreateurs(): Collection
    {
        return $this->formationCreateurs;
    }

    public function addFormationCreateur(Formation $formationCreateur): static
    {
        if (!$this->formationCreateurs->contains($formationCreateur)) {
            $this->formationCreateurs->add($formationCreateur);
            $formationCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeFormationCreateur(Formation $formationCreateur): static
    {
        if ($this->formationCreateurs->removeElement($formationCreateur)) {
            // set the owning side to null (unless already changed)
            if ($formationCreateur->getCreateur() === $this) {
                $formationCreateur->setCreateur(null);
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

    /**
     * @return Collection<int, Attestation>
     */
    public function getAttestationCreateurs(): Collection
    {
        return $this->attestationCreateurs;
    }

    public function addAttestationCreateur(Attestation $attestationCreateur): static
    {
        if (!$this->attestationCreateurs->contains($attestationCreateur)) {
            $this->attestationCreateurs->add($attestationCreateur);
            $attestationCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeAttestationCreateur(Attestation $attestationCreateur): static
    {
        if ($this->attestationCreateurs->removeElement($attestationCreateur)) {
            // set the owning side to null (unless already changed)
            if ($attestationCreateur->getCreateur() === $this) {
                $attestationCreateur->setCreateur(null);
            }
        }

        return $this;
    }



    /**
     * @return Collection<int, AuditLog>
     */
    public function getAuditLogCreateurs(): Collection
    {
        return $this->AuditLogCreateurs;
    }

    public function addAuditLogCreateur(AuditLog $auditLogCreateur): static
    {
        if (!$this->AuditLogCreateurs->contains($auditLogCreateur)) {
            $this->AuditLogCreateurs->add($auditLogCreateur);
            $auditLogCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeAuditLogCreateur(AuditLog $auditLogCreateur): static
    {
        if ($this->AuditLogCreateurs->removeElement($auditLogCreateur)) {
            // set the owning side to null (unless already changed)
            if ($auditLogCreateur->getCreateur() === $this) {
                $auditLogCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Avoir>
     */
    public function getAvoirCreateurs(): Collection
    {
        return $this->avoirCreateurs;
    }

    public function addAvoirCreateur(Avoir $avoirCreateur): static
    {
        if (!$this->avoirCreateurs->contains($avoirCreateur)) {
            $this->avoirCreateurs->add($avoirCreateur);
            $avoirCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeAvoirCreateur(Avoir $avoirCreateur): static
    {
        if ($this->avoirCreateurs->removeElement($avoirCreateur)) {
            // set the owning side to null (unless already changed)
            if ($avoirCreateur->getCreateur() === $this) {
                $avoirCreateur->setCreateur(null);
            }
        }

        return $this;
    }



    /**
     * @return Collection<int, Categorie>
     */
    public function getCategorieCreateurs(): Collection
    {
        return $this->categorieCreateurs;
    }

    public function addCategorieCreateur(Categorie $categorieCreateur): static
    {
        if (!$this->categorieCreateurs->contains($categorieCreateur)) {
            $this->categorieCreateurs->add($categorieCreateur);
            $categorieCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeCategorieCreateur(Categorie $categorieCreateur): static
    {
        if ($this->categorieCreateurs->removeElement($categorieCreateur)) {
            // set the owning side to null (unless already changed)
            if ($categorieCreateur->getCreateur() === $this) {
                $categorieCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ContentBlock>
     */
    public function getContentBlockCreateurs(): Collection
    {
        return $this->contentBlockCreateurs;
    }

    public function addContentBlockCreateur(ContentBlock $contentBlockCreateur): static
    {
        if (!$this->contentBlockCreateurs->contains($contentBlockCreateur)) {
            $this->contentBlockCreateurs->add($contentBlockCreateur);
            $contentBlockCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeContentBlockCreateur(ContentBlock $contentBlockCreateur): static
    {
        if ($this->contentBlockCreateurs->removeElement($contentBlockCreateur)) {
            // set the owning side to null (unless already changed)
            if ($contentBlockCreateur->getCreateur() === $this) {
                $contentBlockCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ContratFormateur>
     */
    public function getContratFormateurCreateurs(): Collection
    {
        return $this->contratFormateurCreateurs;
    }

    public function addContratFormateurCreateur(ContratFormateur $contratFormateurCreateur): static
    {
        if (!$this->contratFormateurCreateurs->contains($contratFormateurCreateur)) {
            $this->contratFormateurCreateurs->add($contratFormateurCreateur);
            $contratFormateurCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeContratFormateurCreateur(ContratFormateur $contratFormateurCreateur): static
    {
        if ($this->contratFormateurCreateurs->removeElement($contratFormateurCreateur)) {
            // set the owning side to null (unless already changed)
            if ($contratFormateurCreateur->getCreateur() === $this) {
                $contratFormateurCreateur->setCreateur(null);
            }
        }

        return $this;
    }


    /**
     * @return Collection<int, ContratStagiaire>
     */
    public function getContratStagiaireCreateurs(): Collection
    {
        return $this->contratStagiaireCreateurs;
    }

    public function addContratStagiaireCreateur(ContratStagiaire $contratStagiaireCreateur): static
    {
        if (!$this->contratStagiaireCreateurs->contains($contratStagiaireCreateur)) {
            $this->contratStagiaireCreateurs->add($contratStagiaireCreateur);
            $contratStagiaireCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeContratStagiaireCreateur(ContratStagiaire $contratStagiaireCreateur): static
    {
        if ($this->contratStagiaireCreateurs->removeElement($contratStagiaireCreateur)) {
            // set the owning side to null (unless already changed)
            if ($contratStagiaireCreateur->getCreateur() === $this) {
                $contratStagiaireCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ConventionContrat>
     */
    public function getConventionContratCreateurs(): Collection
    {
        return $this->conventionContratCreateurs;
    }

    public function addConventionContratCreateur(ConventionContrat $conventionContratCreateur): static
    {
        if (!$this->conventionContratCreateurs->contains($conventionContratCreateur)) {
            $this->conventionContratCreateurs->add($conventionContratCreateur);
            $conventionContratCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeConventionContratCreateur(ConventionContrat $conventionContratCreateur): static
    {
        if ($this->conventionContratCreateurs->removeElement($conventionContratCreateur)) {
            // set the owning side to null (unless already changed)
            if ($conventionContratCreateur->getCreateur() === $this) {
                $conventionContratCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Devis>
     */
    public function getDevisCreateurs(): Collection
    {
        return $this->devisCreateurs;
    }

    public function addDevisCreateur(Devis $devisCreateur): static
    {
        if (!$this->devisCreateurs->contains($devisCreateur)) {
            $this->devisCreateurs->add($devisCreateur);
            $devisCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeDevisCreateur(Devis $devisCreateur): static
    {
        if ($this->devisCreateurs->removeElement($devisCreateur)) {
            // set the owning side to null (unless already changed)
            if ($devisCreateur->getCreateur() === $this) {
                $devisCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, DossierInscription>
     */
    public function getDossierInscriptionCreateurs(): Collection
    {
        return $this->dossierInscriptionCreateurs;
    }

    public function addDossierInscriptionCreateur(DossierInscription $dossierInscriptionCreateur): static
    {
        if (!$this->dossierInscriptionCreateurs->contains($dossierInscriptionCreateur)) {
            $this->dossierInscriptionCreateurs->add($dossierInscriptionCreateur);
            $dossierInscriptionCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeDossierInscriptionCreateur(DossierInscription $dossierInscriptionCreateur): static
    {
        if ($this->dossierInscriptionCreateurs->removeElement($dossierInscriptionCreateur)) {
            // set the owning side to null (unless already changed)
            if ($dossierInscriptionCreateur->getCreateur() === $this) {
                $dossierInscriptionCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, EmailLog>
     */
    public function getEmailLogCreateurs(): Collection
    {
        return $this->emailLogCreateurs;
    }

    public function addEmailLogCreateur(EmailLog $emailLogCreateur): static
    {
        if (!$this->emailLogCreateurs->contains($emailLogCreateur)) {
            $this->emailLogCreateurs->add($emailLogCreateur);
            $emailLogCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeEmailLogCreateur(EmailLog $emailLogCreateur): static
    {
        if ($this->emailLogCreateurs->removeElement($emailLogCreateur)) {
            // set the owning side to null (unless already changed)
            if ($emailLogCreateur->getCreateur() === $this) {
                $emailLogCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, EmailTemplate>
     */
    public function getEmailTemplateCreateurs(): Collection
    {
        return $this->emailTemplateCreateurs;
    }

    public function addEmailTemplateCreateur(EmailTemplate $emailTemplateCreateur): static
    {
        if (!$this->emailTemplateCreateurs->contains($emailTemplateCreateur)) {
            $this->emailTemplateCreateurs->add($emailTemplateCreateur);
            $emailTemplateCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeEmailTemplateCreateur(EmailTemplate $emailTemplateCreateur): static
    {
        if ($this->emailTemplateCreateurs->removeElement($emailTemplateCreateur)) {
            // set the owning side to null (unless already changed)
            if ($emailTemplateCreateur->getCreateur() === $this) {
                $emailTemplateCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Emargement>
     */
    public function getEmargementCreateurs(): Collection
    {
        return $this->emargementCreateurs;
    }

    public function addEmargementCreateur(Emargement $emargementCreateur): static
    {
        if (!$this->emargementCreateurs->contains($emargementCreateur)) {
            $this->emargementCreateurs->add($emargementCreateur);
            $emargementCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeEmargementCreateur(Emargement $emargementCreateur): static
    {
        if ($this->emargementCreateurs->removeElement($emargementCreateur)) {
            // set the owning side to null (unless already changed)
            if ($emargementCreateur->getCreateur() === $this) {
                $emargementCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Engin>
     */
    public function getEnginCreateurs(): Collection
    {
        return $this->enginCreateurs;
    }

    public function addEnginCreateur(Engin $enginCreateur): static
    {
        if (!$this->enginCreateurs->contains($enginCreateur)) {
            $this->enginCreateurs->add($enginCreateur);
            $enginCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeEnginCreateur(Engin $enginCreateur): static
    {
        if ($this->enginCreateurs->removeElement($enginCreateur)) {
            // set the owning side to null (unless already changed)
            if ($enginCreateur->getCreateur() === $this) {
                $enginCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, EnginPhoto>
     */
    public function getEnginPhotoCreateurs(): Collection
    {
        return $this->enginPhotoCreateurs;
    }

    public function addEnginPhotoCreateur(EnginPhoto $enginPhotoCreateur): static
    {
        if (!$this->enginPhotoCreateurs->contains($enginPhotoCreateur)) {
            $this->enginPhotoCreateurs->add($enginPhotoCreateur);
            $enginPhotoCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeEnginPhotoCreateur(EnginPhoto $enginPhotoCreateur): static
    {
        if ($this->enginPhotoCreateurs->removeElement($enginPhotoCreateur)) {
            // set the owning side to null (unless already changed)
            if ($enginPhotoCreateur->getCreateur() === $this) {
                $enginPhotoCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, EntitePreferences>
     */
    public function getEntitePreferenceCreateurs(): Collection
    {
        return $this->entitePreferenceCreateurs;
    }

    public function addEntitePreferenceCreateur(EntitePreferences $entitePreferenceCreateur): static
    {
        if (!$this->entitePreferenceCreateurs->contains($entitePreferenceCreateur)) {
            $this->entitePreferenceCreateurs->add($entitePreferenceCreateur);
            $entitePreferenceCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeEntitePreferenceCreateur(EntitePreferences $entitePreferenceCreateur): static
    {
        if ($this->entitePreferenceCreateurs->removeElement($entitePreferenceCreateur)) {
            // set the owning side to null (unless already changed)
            if ($entitePreferenceCreateur->getCreateur() === $this) {
                $entitePreferenceCreateur->setCreateur($this);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Entreprise>
     */
    public function getEntrepriseCreateurs(): Collection
    {
        return $this->entrepriseCreateurs;
    }

    public function addEntrepriseCreateur(Entreprise $entrepriseCreateur): static
    {
        if (!$this->entrepriseCreateurs->contains($entrepriseCreateur)) {
            $this->entrepriseCreateurs->add($entrepriseCreateur);
            $entrepriseCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeEntrepriseCreateur(Entreprise $entrepriseCreateur): static
    {
        if ($this->entrepriseCreateurs->removeElement($entrepriseCreateur)) {
            // set the owning side to null (unless already changed)
            if ($entrepriseCreateur->getCreateur() === $this) {
                $entrepriseCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Facture>
     */
    public function getFactureCreateurs(): Collection
    {
        return $this->factureCreateurs;
    }

    public function addFactureCreateur(Facture $factureCreateur): static
    {
        if (!$this->factureCreateurs->contains($factureCreateur)) {
            $this->factureCreateurs->add($factureCreateur);
            $factureCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeFactureCreateur(Facture $factureCreateur): static
    {
        if ($this->factureCreateurs->removeElement($factureCreateur)) {
            // set the owning side to null (unless already changed)
            if ($factureCreateur->getCreateur() === $this) {
                $factureCreateur->setCreateur(null);
            }
        }

        return $this;
    }


    /**
     * @return Collection<int, FormateurObjectiveEvaluation>
     */
    public function getFormateurObjectiveEvaluationCreateurs(): Collection
    {
        return $this->formateurObjectiveEvaluationCreateurs;
    }

    public function addFormateurObjectiveEvaluationCreateur(FormateurObjectiveEvaluation $formateurObjectiveEvaluationCreateur): static
    {
        if (!$this->formateurObjectiveEvaluationCreateurs->contains($formateurObjectiveEvaluationCreateur)) {
            $this->formateurObjectiveEvaluationCreateurs->add($formateurObjectiveEvaluationCreateur);
            $formateurObjectiveEvaluationCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeFormateurObjectiveEvaluationCreateur(FormateurObjectiveEvaluation $formateurObjectiveEvaluationCreateur): static
    {
        if ($this->formateurObjectiveEvaluationCreateurs->removeElement($formateurObjectiveEvaluationCreateur)) {
            // set the owning side to null (unless already changed)
            if ($formateurObjectiveEvaluationCreateur->getCreateur() === $this) {
                $formateurObjectiveEvaluationCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, FormateurSatisfactionAssignment>
     */
    public function getFormateurSatisfactionAssignementCreateurs(): Collection
    {
        return $this->formateurSatisfactionAssignementCreateurs;
    }

    public function addFormateurSatisfactionAssignementCreateur(FormateurSatisfactionAssignment $formateurSatisfactionAssignementCreateur): static
    {
        if (!$this->formateurSatisfactionAssignementCreateurs->contains($formateurSatisfactionAssignementCreateur)) {
            $this->formateurSatisfactionAssignementCreateurs->add($formateurSatisfactionAssignementCreateur);
            $formateurSatisfactionAssignementCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeFormateurSatisfactionAssignementCreateur(FormateurSatisfactionAssignment $formateurSatisfactionAssignementCreateur): static
    {
        if ($this->formateurSatisfactionAssignementCreateurs->removeElement($formateurSatisfactionAssignementCreateur)) {
            // set the owning side to null (unless already changed)
            if ($formateurSatisfactionAssignementCreateur->getCreateur() === $this) {
                $formateurSatisfactionAssignementCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, FormateurSatisfactionAttempt>
     */
    public function getFormateurSatisfactionAttemptCreateurs(): Collection
    {
        return $this->formateurSatisfactionAttemptCreateurs;
    }

    public function addFormateurSatisfactionAttemptCreateur(FormateurSatisfactionAttempt $formateurSatisfactionAttemptCreateur): static
    {
        if (!$this->formateurSatisfactionAttemptCreateurs->contains($formateurSatisfactionAttemptCreateur)) {
            $this->formateurSatisfactionAttemptCreateurs->add($formateurSatisfactionAttemptCreateur);
            $formateurSatisfactionAttemptCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeFormateurSatisfactionAttemptCreateur(FormateurSatisfactionAttempt $formateurSatisfactionAttemptCreateur): static
    {
        if ($this->formateurSatisfactionAttemptCreateurs->removeElement($formateurSatisfactionAttemptCreateur)) {
            // set the owning side to null (unless already changed)
            if ($formateurSatisfactionAttemptCreateur->getCreateur() === $this) {
                $formateurSatisfactionAttemptCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, FormateurSatisfactionChapter>
     */
    public function getFormateurStisfactionChapterCreateurs(): Collection
    {
        return $this->formateurStisfactionChapterCreateurs;
    }

    public function addFormateurStisfactionChapterCreateur(FormateurSatisfactionChapter $formateurStisfactionChapterCreateur): static
    {
        if (!$this->formateurStisfactionChapterCreateurs->contains($formateurStisfactionChapterCreateur)) {
            $this->formateurStisfactionChapterCreateurs->add($formateurStisfactionChapterCreateur);
            $formateurStisfactionChapterCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeFormateurStisfactionChapterCreateur(FormateurSatisfactionChapter $formateurStisfactionChapterCreateur): static
    {
        if ($this->formateurStisfactionChapterCreateurs->removeElement($formateurStisfactionChapterCreateur)) {
            // set the owning side to null (unless already changed)
            if ($formateurStisfactionChapterCreateur->getCreateur() === $this) {
                $formateurStisfactionChapterCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, FormateurSatisfactionQuestion>
     */
    public function getFormateurSatisfactionQuestionCreateurs(): Collection
    {
        return $this->formateurSatisfactionQuestionCreateurs;
    }

    public function addFormateurSatisfactionQuestionCreateur(FormateurSatisfactionQuestion $formateurSatisfactionQuestionCreateur): static
    {
        if (!$this->formateurSatisfactionQuestionCreateurs->contains($formateurSatisfactionQuestionCreateur)) {
            $this->formateurSatisfactionQuestionCreateurs->add($formateurSatisfactionQuestionCreateur);
            $formateurSatisfactionQuestionCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeFormateurSatisfactionQuestionCreateur(FormateurSatisfactionQuestion $formateurSatisfactionQuestionCreateur): static
    {
        if ($this->formateurSatisfactionQuestionCreateurs->removeElement($formateurSatisfactionQuestionCreateur)) {
            // set the owning side to null (unless already changed)
            if ($formateurSatisfactionQuestionCreateur->getCreateur() === $this) {
                $formateurSatisfactionQuestionCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, FormateurSatisfactionTemplate>
     */
    public function getFormateurSatisfactionTemplateCreateurs(): Collection
    {
        return $this->formateurSatisfactionTemplateCreateurs;
    }

    public function addFormateurSatisfactionTemplateCreateur(FormateurSatisfactionTemplate $formateurSatisfactionTemplateCreateur): static
    {
        if (!$this->formateurSatisfactionTemplateCreateurs->contains($formateurSatisfactionTemplateCreateur)) {
            $this->formateurSatisfactionTemplateCreateurs->add($formateurSatisfactionTemplateCreateur);
            $formateurSatisfactionTemplateCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeFormateurSatisfactionTemplateCreateur(FormateurSatisfactionTemplate $formateurSatisfactionTemplateCreateur): static
    {
        if ($this->formateurSatisfactionTemplateCreateurs->removeElement($formateurSatisfactionTemplateCreateur)) {
            // set the owning side to null (unless already changed)
            if ($formateurSatisfactionTemplateCreateur->getCreateur() === $this) {
                $formateurSatisfactionTemplateCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, FormationContentNode>
     */
    public function getFormationContentNodeCreateurs(): Collection
    {
        return $this->formationContentNodeCreateurs;
    }

    public function addFormationContentNodeCreateur(FormationContentNode $formationContentNodeCreateur): static
    {
        if (!$this->formationContentNodeCreateurs->contains($formationContentNodeCreateur)) {
            $this->formationContentNodeCreateurs->add($formationContentNodeCreateur);
            $formationContentNodeCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeFormationContentNodeCreateur(FormationContentNode $formationContentNodeCreateur): static
    {
        if ($this->formationContentNodeCreateurs->removeElement($formationContentNodeCreateur)) {
            // set the owning side to null (unless already changed)
            if ($formationContentNodeCreateur->getCreateur() === $this) {
                $formationContentNodeCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, FormationObjective>
     */
    public function getFormationObjectiveCreateurs(): Collection
    {
        return $this->formationObjectiveCreateurs;
    }

    public function addFormationObjectiveCreateur(FormationObjective $formationObjectiveCreateur): static
    {
        if (!$this->formationObjectiveCreateurs->contains($formationObjectiveCreateur)) {
            $this->formationObjectiveCreateurs->add($formationObjectiveCreateur);
            $formationObjectiveCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeFormationObjectiveCreateur(FormationObjective $formationObjectiveCreateur): static
    {
        if ($this->formationObjectiveCreateurs->removeElement($formationObjectiveCreateur)) {
            // set the owning side to null (unless already changed)
            if ($formationObjectiveCreateur->getCreateur() === $this) {
                $formationObjectiveCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, FormationPhoto>
     */
    public function getFormationPhotoCreateurs(): Collection
    {
        return $this->formationPhotoCreateurs;
    }

    public function addFormationPhotoCreateur(FormationPhoto $formationPhotoCreateur): static
    {
        if (!$this->formationPhotoCreateurs->contains($formationPhotoCreateur)) {
            $this->formationPhotoCreateurs->add($formationPhotoCreateur);
            $formationPhotoCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeFormationPhotoCreateur(FormationPhoto $formationPhotoCreateur): static
    {
        if ($this->formationPhotoCreateurs->removeElement($formationPhotoCreateur)) {
            // set the owning side to null (unless already changed)
            if ($formationPhotoCreateur->getCreateur() === $this) {
                $formationPhotoCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Inscription>
     */
    public function getInscriptionCreateurs(): Collection
    {
        return $this->inscriptionCreateurs;
    }

    public function addInscriptionCreateur(Inscription $inscriptionCreateur): static
    {
        if (!$this->inscriptionCreateurs->contains($inscriptionCreateur)) {
            $this->inscriptionCreateurs->add($inscriptionCreateur);
            $inscriptionCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeInscriptionCreateur(Inscription $inscriptionCreateur): static
    {
        if ($this->inscriptionCreateurs->removeElement($inscriptionCreateur)) {
            // set the owning side to null (unless already changed)
            if ($inscriptionCreateur->getCreateur() === $this) {
                $inscriptionCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, LigneDevis>
     */
    public function getLigneDevisCreateurs(): Collection
    {
        return $this->ligneDevisCreateurs;
    }

    public function addLigneDevisCreateur(LigneDevis $ligneDevisCreateur): static
    {
        if (!$this->ligneDevisCreateurs->contains($ligneDevisCreateur)) {
            $this->ligneDevisCreateurs->add($ligneDevisCreateur);
            $ligneDevisCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeLigneDevisCreateur(LigneDevis $ligneDevisCreateur): static
    {
        if ($this->ligneDevisCreateurs->removeElement($ligneDevisCreateur)) {
            // set the owning side to null (unless already changed)
            if ($ligneDevisCreateur->getCreateur() === $this) {
                $ligneDevisCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, LigneFacture>
     */
    public function getLigneFactureCreateurs(): Collection
    {
        return $this->ligneFactureCreateurs;
    }

    public function addLigneFactureCreateur(LigneFacture $ligneFactureCreateur): static
    {
        if (!$this->ligneFactureCreateurs->contains($ligneFactureCreateur)) {
            $this->ligneFactureCreateurs->add($ligneFactureCreateur);
            $ligneFactureCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeLigneFactureCreateur(LigneFacture $ligneFactureCreateur): static
    {
        if ($this->ligneFactureCreateurs->removeElement($ligneFactureCreateur)) {
            // set the owning side to null (unless already changed)
            if ($ligneFactureCreateur->getCreateur() === $this) {
                $ligneFactureCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Paiement>
     */
    public function getPaiementCreateurs(): Collection
    {
        return $this->paiementCreateurs;
    }

    public function addPaiementCreateur(Paiement $paiementCreateur): static
    {
        if (!$this->paiementCreateurs->contains($paiementCreateur)) {
            $this->paiementCreateurs->add($paiementCreateur);
            $paiementCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removePaiementCreateur(Paiement $paiementCreateur): static
    {
        if ($this->paiementCreateurs->removeElement($paiementCreateur)) {
            // set the owning side to null (unless already changed)
            if ($paiementCreateur->getCreateur() === $this) {
                $paiementCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, PieceDossier>
     */
    public function getPieceDossierCreateurs(): Collection
    {
        return $this->pieceDossierCreateurs;
    }

    public function addPieceDossierCreateur(PieceDossier $pieceDossierCreateur): static
    {
        if (!$this->pieceDossierCreateurs->contains($pieceDossierCreateur)) {
            $this->pieceDossierCreateurs->add($pieceDossierCreateur);
            $pieceDossierCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removePieceDossierCreateur(PieceDossier $pieceDossierCreateur): static
    {
        if ($this->pieceDossierCreateurs->removeElement($pieceDossierCreateur)) {
            // set the owning side to null (unless already changed)
            if ($pieceDossierCreateur->getCreateur() === $this) {
                $pieceDossierCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, PositioningAnswer>
     */
    public function getPositioningAnswerCreateurs(): Collection
    {
        return $this->positioningAnswerCreateurs;
    }

    public function addPositioningAnswerCreateur(PositioningAnswer $positioningAnswerCreateur): static
    {
        if (!$this->positioningAnswerCreateurs->contains($positioningAnswerCreateur)) {
            $this->positioningAnswerCreateurs->add($positioningAnswerCreateur);
            $positioningAnswerCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removePositioningAnswerCreateur(PositioningAnswer $positioningAnswerCreateur): static
    {
        if ($this->positioningAnswerCreateurs->removeElement($positioningAnswerCreateur)) {
            // set the owning side to null (unless already changed)
            if ($positioningAnswerCreateur->getCreateur() === $this) {
                $positioningAnswerCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, PositioningAssignment>
     */
    public function getPositioningAssignementCreateurs(): Collection
    {
        return $this->positioningAssignementCreateurs;
    }

    public function addPositioningAssignementCreateur(PositioningAssignment $positioningAssignementCreateur): static
    {
        if (!$this->positioningAssignementCreateurs->contains($positioningAssignementCreateur)) {
            $this->positioningAssignementCreateurs->add($positioningAssignementCreateur);
            $positioningAssignementCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removePositioningAssignementCreateur(PositioningAssignment $positioningAssignementCreateur): static
    {
        if ($this->positioningAssignementCreateurs->removeElement($positioningAssignementCreateur)) {
            // set the owning side to null (unless already changed)
            if ($positioningAssignementCreateur->getCreateur() === $this) {
                $positioningAssignementCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, PositioningAttempt>
     */
    public function getPositioningAttemptCreateurs(): Collection
    {
        return $this->positioningAttemptCreateurs;
    }

    public function addPositioningAttemptCreateur(PositioningAttempt $positioningAttemptCreateur): static
    {
        if (!$this->positioningAttemptCreateurs->contains($positioningAttemptCreateur)) {
            $this->positioningAttemptCreateurs->add($positioningAttemptCreateur);
            $positioningAttemptCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removePositioningAttemptCreateur(PositioningAttempt $positioningAttemptCreateur): static
    {
        if ($this->positioningAttemptCreateurs->removeElement($positioningAttemptCreateur)) {
            // set the owning side to null (unless already changed)
            if ($positioningAttemptCreateur->getCreateur() === $this) {
                $positioningAttemptCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, PositioningChapter>
     */
    public function getPositioningChapterCreateurs(): Collection
    {
        return $this->positioningChapterCreateurs;
    }

    public function addPositioningChapterCreateur(PositioningChapter $positioningChapterCreateur): static
    {
        if (!$this->positioningChapterCreateurs->contains($positioningChapterCreateur)) {
            $this->positioningChapterCreateurs->add($positioningChapterCreateur);
            $positioningChapterCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removePositioningChapterCreateur(PositioningChapter $positioningChapterCreateur): static
    {
        if ($this->positioningChapterCreateurs->removeElement($positioningChapterCreateur)) {
            // set the owning side to null (unless already changed)
            if ($positioningChapterCreateur->getCreateur() === $this) {
                $positioningChapterCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, PositioningItem>
     */
    public function getPositioningItemCreateurs(): Collection
    {
        return $this->positioningItemCreateurs;
    }

    public function addPositioningItemCreateur(PositioningItem $positioningItemCreateur): static
    {
        if (!$this->positioningItemCreateurs->contains($positioningItemCreateur)) {
            $this->positioningItemCreateurs->add($positioningItemCreateur);
            $positioningItemCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removePositioningItemCreateur(PositioningItem $positioningItemCreateur): static
    {
        if ($this->positioningItemCreateurs->removeElement($positioningItemCreateur)) {
            // set the owning side to null (unless already changed)
            if ($positioningItemCreateur->getCreateur() === $this) {
                $positioningItemCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, PositioningQuestionnaire>
     */
    public function getPositioningQuestionnaireCreateurs(): Collection
    {
        return $this->positioningQuestionnaireCreateurs;
    }

    public function addPositioningQuestionnaireCreateur(PositioningQuestionnaire $positioningQuestionnaireCreateur): static
    {
        if (!$this->positioningQuestionnaireCreateurs->contains($positioningQuestionnaireCreateur)) {
            $this->positioningQuestionnaireCreateurs->add($positioningQuestionnaireCreateur);
            $positioningQuestionnaireCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removePositioningQuestionnaireCreateur(PositioningQuestionnaire $positioningQuestionnaireCreateur): static
    {
        if ($this->positioningQuestionnaireCreateurs->removeElement($positioningQuestionnaireCreateur)) {
            // set the owning side to null (unless already changed)
            if ($positioningQuestionnaireCreateur->getCreateur() === $this) {
                $positioningQuestionnaireCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ProspectInteraction>
     */
    public function getProspectInteractionCreateurs(): Collection
    {
        return $this->prospectInteractionCreateurs;
    }

    public function addProspectInteractionCreateur(ProspectInteraction $prospectInteractionCreateur): static
    {
        if (!$this->prospectInteractionCreateurs->contains($prospectInteractionCreateur)) {
            $this->prospectInteractionCreateurs->add($prospectInteractionCreateur);
            $prospectInteractionCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeProspectInteractionCreateur(ProspectInteraction $prospectInteractionCreateur): static
    {
        if ($this->prospectInteractionCreateurs->removeElement($prospectInteractionCreateur)) {
            // set the owning side to null (unless already changed)
            if ($prospectInteractionCreateur->getCreateur() === $this) {
                $prospectInteractionCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Qcm>
     */
    public function getQcmCreateurs(): Collection
    {
        return $this->qcmCreateurs;
    }

    public function addQcmCreateur(Qcm $qcmCreateur): static
    {
        if (!$this->qcmCreateurs->contains($qcmCreateur)) {
            $this->qcmCreateurs->add($qcmCreateur);
            $qcmCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeQcmCreateur(Qcm $qcmCreateur): static
    {
        if ($this->qcmCreateurs->removeElement($qcmCreateur)) {
            // set the owning side to null (unless already changed)
            if ($qcmCreateur->getCreateur() === $this) {
                $qcmCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, QcmAnswer>
     */
    public function getQcmAsnwerCreateurs(): Collection
    {
        return $this->qcmAsnwerCreateurs;
    }

    public function addQcmAsnwerCreateur(QcmAnswer $qcmAsnwerCreateur): static
    {
        if (!$this->qcmAsnwerCreateurs->contains($qcmAsnwerCreateur)) {
            $this->qcmAsnwerCreateurs->add($qcmAsnwerCreateur);
            $qcmAsnwerCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeQcmAsnwerCreateur(QcmAnswer $qcmAsnwerCreateur): static
    {
        if ($this->qcmAsnwerCreateurs->removeElement($qcmAsnwerCreateur)) {
            // set the owning side to null (unless already changed)
            if ($qcmAsnwerCreateur->getCreateur() === $this) {
                $qcmAsnwerCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, QcmAssignment>
     */
    public function getQcmAssignementCreateur(): Collection
    {
        return $this->qcmAssignementCreateur;
    }

    public function addQcmAssignementCreateur(QcmAssignment $qcmAssignementCreateur): static
    {
        if (!$this->qcmAssignementCreateur->contains($qcmAssignementCreateur)) {
            $this->qcmAssignementCreateur->add($qcmAssignementCreateur);
            $qcmAssignementCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeQcmAssignementCreateur(QcmAssignment $qcmAssignementCreateur): static
    {
        if ($this->qcmAssignementCreateur->removeElement($qcmAssignementCreateur)) {
            // set the owning side to null (unless already changed)
            if ($qcmAssignementCreateur->getCreateur() === $this) {
                $qcmAssignementCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, QcmAttempt>
     */
    public function getQcmAttemptCreateurs(): Collection
    {
        return $this->qcmAttemptCreateurs;
    }

    public function addQcmAttemptCreateur(QcmAttempt $qcmAttemptCreateur): static
    {
        if (!$this->qcmAttemptCreateurs->contains($qcmAttemptCreateur)) {
            $this->qcmAttemptCreateurs->add($qcmAttemptCreateur);
            $qcmAttemptCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeQcmAttemptCreateur(QcmAttempt $qcmAttemptCreateur): static
    {
        if ($this->qcmAttemptCreateurs->removeElement($qcmAttemptCreateur)) {
            // set the owning side to null (unless already changed)
            if ($qcmAttemptCreateur->getCreateur() === $this) {
                $qcmAttemptCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, QcmOption>
     */
    public function getQcmOptionCreateurs(): Collection
    {
        return $this->qcmOptionCreateurs;
    }

    public function addQcmOptionCreateur(QcmOption $qcmOptionCreateur): static
    {
        if (!$this->qcmOptionCreateurs->contains($qcmOptionCreateur)) {
            $this->qcmOptionCreateurs->add($qcmOptionCreateur);
            $qcmOptionCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeQcmOptionCreateur(QcmOption $qcmOptionCreateur): static
    {
        if ($this->qcmOptionCreateurs->removeElement($qcmOptionCreateur)) {
            // set the owning side to null (unless already changed)
            if ($qcmOptionCreateur->getCreateur() === $this) {
                $qcmOptionCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, QcmQuestion>
     */
    public function getQcmQuestionCreateurs(): Collection
    {
        return $this->qcmQuestionCreateurs;
    }

    public function addQcmQuestionCreateur(QcmQuestion $qcmQuestionCreateur): static
    {
        if (!$this->qcmQuestionCreateurs->contains($qcmQuestionCreateur)) {
            $this->qcmQuestionCreateurs->add($qcmQuestionCreateur);
            $qcmQuestionCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeQcmQuestionCreateur(QcmQuestion $qcmQuestionCreateur): static
    {
        if ($this->qcmQuestionCreateurs->removeElement($qcmQuestionCreateur)) {
            // set the owning side to null (unless already changed)
            if ($qcmQuestionCreateur->getCreateur() === $this) {
                $qcmQuestionCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, QuestionnaireSatisfaction>
     */
    public function getQuestionnaireSatisfactionCreateurs(): Collection
    {
        return $this->questionnaireSatisfactionCreateurs;
    }

    public function addQuestionnaireSatisfactionCreateur(QuestionnaireSatisfaction $questionnaireSatisfactionCreateur): static
    {
        if (!$this->questionnaireSatisfactionCreateurs->contains($questionnaireSatisfactionCreateur)) {
            $this->questionnaireSatisfactionCreateurs->add($questionnaireSatisfactionCreateur);
            $questionnaireSatisfactionCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeQuestionnaireSatisfactionCreateur(QuestionnaireSatisfaction $questionnaireSatisfactionCreateur): static
    {
        if ($this->questionnaireSatisfactionCreateurs->removeElement($questionnaireSatisfactionCreateur)) {
            // set the owning side to null (unless already changed)
            if ($questionnaireSatisfactionCreateur->getCreateur() === $this) {
                $questionnaireSatisfactionCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Quiz>
     */
    public function getQuizCreateurs(): Collection
    {
        return $this->quizCreateurs;
    }

    public function addQuizCreateur(Quiz $quizCreateur): static
    {
        if (!$this->quizCreateurs->contains($quizCreateur)) {
            $this->quizCreateurs->add($quizCreateur);
            $quizCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeQuizCreateur(Quiz $quizCreateur): static
    {
        if ($this->quizCreateurs->removeElement($quizCreateur)) {
            // set the owning side to null (unless already changed)
            if ($quizCreateur->getCreateur() === $this) {
                $quizCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, QuizAnswer>
     */
    public function getQuizAnswerCreateurs(): Collection
    {
        return $this->quizAnswerCreateurs;
    }

    public function addQuizAnswerCreateur(QuizAnswer $quizAnswerCreateur): static
    {
        if (!$this->quizAnswerCreateurs->contains($quizAnswerCreateur)) {
            $this->quizAnswerCreateurs->add($quizAnswerCreateur);
            $quizAnswerCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeQuizAnswerCreateur(QuizAnswer $quizAnswerCreateur): static
    {
        if ($this->quizAnswerCreateurs->removeElement($quizAnswerCreateur)) {
            // set the owning side to null (unless already changed)
            if ($quizAnswerCreateur->getCreateur() === $this) {
                $quizAnswerCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, QuizAttempt>
     */
    public function getQuizAttemptCreateurs(): Collection
    {
        return $this->quizAttemptCreateurs;
    }

    public function addQuizAttemptCreateur(QuizAttempt $quizAttemptCreateur): static
    {
        if (!$this->quizAttemptCreateurs->contains($quizAttemptCreateur)) {
            $this->quizAttemptCreateurs->add($quizAttemptCreateur);
            $quizAttemptCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeQuizAttemptCreateur(QuizAttempt $quizAttemptCreateur): static
    {
        if ($this->quizAttemptCreateurs->removeElement($quizAttemptCreateur)) {
            // set the owning side to null (unless already changed)
            if ($quizAttemptCreateur->getCreateur() === $this) {
                $quizAttemptCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, QuizChoice>
     */
    public function getQuizChoiceCreateurs(): Collection
    {
        return $this->quizChoiceCreateurs;
    }

    public function addQuizChoiceCreateur(QuizChoice $quizChoiceCreateur): static
    {
        if (!$this->quizChoiceCreateurs->contains($quizChoiceCreateur)) {
            $this->quizChoiceCreateurs->add($quizChoiceCreateur);
            $quizChoiceCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeQuizChoiceCreateur(QuizChoice $quizChoiceCreateur): static
    {
        if ($this->quizChoiceCreateurs->removeElement($quizChoiceCreateur)) {
            // set the owning side to null (unless already changed)
            if ($quizChoiceCreateur->getCreateur() === $this) {
                $quizChoiceCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, QuizQuestion>
     */
    public function getQuizQuestionCreateurs(): Collection
    {
        return $this->quizQuestionCreateurs;
    }

    public function addQuizQuestionCreateur(QuizQuestion $quizQuestionCreateur): static
    {
        if (!$this->quizQuestionCreateurs->contains($quizQuestionCreateur)) {
            $this->quizQuestionCreateurs->add($quizQuestionCreateur);
            $quizQuestionCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeQuizQuestionCreateur(QuizQuestion $quizQuestionCreateur): static
    {
        if ($this->quizQuestionCreateurs->removeElement($quizQuestionCreateur)) {
            // set the owning side to null (unless already changed)
            if ($quizQuestionCreateur->getCreateur() === $this) {
                $quizQuestionCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, RapportFormateur>
     */
    public function getRapportFormateurCreateurs(): Collection
    {
        return $this->rapportFormateurCreateurs;
    }

    public function addRapportFormateurCreateur(RapportFormateur $rapportFormateurCreateur): static
    {
        if (!$this->rapportFormateurCreateurs->contains($rapportFormateurCreateur)) {
            $this->rapportFormateurCreateurs->add($rapportFormateurCreateur);
            $rapportFormateurCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeRapportFormateurCreateur(RapportFormateur $rapportFormateurCreateur): static
    {
        if ($this->rapportFormateurCreateurs->removeElement($rapportFormateurCreateur)) {
            // set the owning side to null (unless already changed)
            if ($rapportFormateurCreateur->getCreateur() === $this) {
                $rapportFormateurCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Reservation>
     */
    public function getReservationCreateurs(): Collection
    {
        return $this->reservationCreateurs;
    }

    public function addReservationCreateur(Reservation $reservationCreateur): static
    {
        if (!$this->reservationCreateurs->contains($reservationCreateur)) {
            $this->reservationCreateurs->add($reservationCreateur);
            $reservationCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeReservationCreateur(Reservation $reservationCreateur): static
    {
        if ($this->reservationCreateurs->removeElement($reservationCreateur)) {
            // set the owning side to null (unless already changed)
            if ($reservationCreateur->getCreateur() === $this) {
                $reservationCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, SatisfactionAssignment>
     */
    public function getSatisfactionAssignementCreateurs(): Collection
    {
        return $this->satisfactionAssignementCreateurs;
    }

    public function addSatisfactionAssignementCreateur(SatisfactionAssignment $satisfactionAssignementCreateur): static
    {
        if (!$this->satisfactionAssignementCreateurs->contains($satisfactionAssignementCreateur)) {
            $this->satisfactionAssignementCreateurs->add($satisfactionAssignementCreateur);
            $satisfactionAssignementCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeSatisfactionAssignementCreateur(SatisfactionAssignment $satisfactionAssignementCreateur): static
    {
        if ($this->satisfactionAssignementCreateurs->removeElement($satisfactionAssignementCreateur)) {
            // set the owning side to null (unless already changed)
            if ($satisfactionAssignementCreateur->getCreateur() === $this) {
                $satisfactionAssignementCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, SatisfactionAttempt>
     */
    public function getSatisfactionAttemptCreateurs(): Collection
    {
        return $this->satisfactionAttemptCreateurs;
    }

    public function addSatisfactionAttemptCreateur(SatisfactionAttempt $satisfactionAttemptCreateur): static
    {
        if (!$this->satisfactionAttemptCreateurs->contains($satisfactionAttemptCreateur)) {
            $this->satisfactionAttemptCreateurs->add($satisfactionAttemptCreateur);
            $satisfactionAttemptCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeSatisfactionAttemptCreateur(SatisfactionAttempt $satisfactionAttemptCreateur): static
    {
        if ($this->satisfactionAttemptCreateurs->removeElement($satisfactionAttemptCreateur)) {
            // set the owning side to null (unless already changed)
            if ($satisfactionAttemptCreateur->getCreateur() === $this) {
                $satisfactionAttemptCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, SatisfactionChapter>
     */
    public function getSatisfactionChapterCreateurs(): Collection
    {
        return $this->satisfactionChapterCreateurs;
    }

    public function addSatisfactionChapterCreateur(SatisfactionChapter $satisfactionChapterCreateur): static
    {
        if (!$this->satisfactionChapterCreateurs->contains($satisfactionChapterCreateur)) {
            $this->satisfactionChapterCreateurs->add($satisfactionChapterCreateur);
            $satisfactionChapterCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeSatisfactionChapterCreateur(SatisfactionChapter $satisfactionChapterCreateur): static
    {
        if ($this->satisfactionChapterCreateurs->removeElement($satisfactionChapterCreateur)) {
            // set the owning side to null (unless already changed)
            if ($satisfactionChapterCreateur->getCreateur() === $this) {
                $satisfactionChapterCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, SatisfactionQuestion>
     */
    public function getSatisfactionQuestionCreateurs(): Collection
    {
        return $this->satisfactionQuestionCreateurs;
    }

    public function addSatisfactionQuestionCreateur(SatisfactionQuestion $satisfactionQuestionCreateur): static
    {
        if (!$this->satisfactionQuestionCreateurs->contains($satisfactionQuestionCreateur)) {
            $this->satisfactionQuestionCreateurs->add($satisfactionQuestionCreateur);
            $satisfactionQuestionCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeSatisfactionQuestionCreateur(SatisfactionQuestion $satisfactionQuestionCreateur): static
    {
        if ($this->satisfactionQuestionCreateurs->removeElement($satisfactionQuestionCreateur)) {
            // set the owning side to null (unless already changed)
            if ($satisfactionQuestionCreateur->getCreateur() === $this) {
                $satisfactionQuestionCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, SatisfactionTemplate>
     */
    public function getSatisfactionTemplateCreateurs(): Collection
    {
        return $this->satisfactionTemplateCreateurs;
    }

    public function addSatisfactionTemplateCreateur(SatisfactionTemplate $satisfactionTemplateCreateur): static
    {
        if (!$this->satisfactionTemplateCreateurs->contains($satisfactionTemplateCreateur)) {
            $this->satisfactionTemplateCreateurs->add($satisfactionTemplateCreateur);
            $satisfactionTemplateCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeSatisfactionTemplateCreateur(SatisfactionTemplate $satisfactionTemplateCreateur): static
    {
        if ($this->satisfactionTemplateCreateurs->removeElement($satisfactionTemplateCreateur)) {
            // set the owning side to null (unless already changed)
            if ($satisfactionTemplateCreateur->getCreateur() === $this) {
                $satisfactionTemplateCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, SessionJour>
     */
    public function getSessionJourCreateurs(): Collection
    {
        return $this->sessionJourCreateurs;
    }

    public function addSessionJourCreateurs(SessionJour $sessionJourCreateurs): static
    {
        if (!$this->sessionJourCreateurs->contains($sessionJourCreateurs)) {
            $this->sessionJourCreateurs->add($sessionJourCreateurs);
            $sessionJourCreateurs->setCreateur($this);
        }

        return $this;
    }

    public function removeSessionJourCreateurs(SessionJour $sessionJourCreateurs): static
    {
        if ($this->sessionJourCreateurs->removeElement($sessionJourCreateurs)) {
            // set the owning side to null (unless already changed)
            if ($sessionJourCreateurs->getCreateur() === $this) {
                $sessionJourCreateurs->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, SessionPositioning>
     */
    public function getSessionPositioningCreateurs(): Collection
    {
        return $this->sessionPositioningCreateurs;
    }

    public function addSessionPositioningCreateur(SessionPositioning $sessionPositioningCreateur): static
    {
        if (!$this->sessionPositioningCreateurs->contains($sessionPositioningCreateur)) {
            $this->sessionPositioningCreateurs->add($sessionPositioningCreateur);
            $sessionPositioningCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeSessionPositioningCreateur(SessionPositioning $sessionPositioningCreateur): static
    {
        if ($this->sessionPositioningCreateurs->removeElement($sessionPositioningCreateur)) {
            // set the owning side to null (unless already changed)
            if ($sessionPositioningCreateur->getCreateur() === $this) {
                $sessionPositioningCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Site>
     */
    public function getSiteCreateurs(): Collection
    {
        return $this->siteCreateurs;
    }

    public function addSiteCreateur(Site $siteCreateur): static
    {
        if (!$this->siteCreateurs->contains($siteCreateur)) {
            $this->siteCreateurs->add($siteCreateur);
            $siteCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeSiteCreateur(Site $siteCreateur): static
    {
        if ($this->siteCreateurs->removeElement($siteCreateur)) {
            // set the owning side to null (unless already changed)
            if ($siteCreateur->getCreateur() === $this) {
                $siteCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, SupportAsset>
     */
    public function getSupportAssetCreateurs(): Collection
    {
        return $this->supportAssetCreateurs;
    }

    public function addSupportAssetCreateur(SupportAsset $supportAssetCreateur): static
    {
        if (!$this->supportAssetCreateurs->contains($supportAssetCreateur)) {
            $this->supportAssetCreateurs->add($supportAssetCreateur);
            $supportAssetCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeSupportAssetCreateur(SupportAsset $supportAssetCreateur): static
    {
        if ($this->supportAssetCreateurs->removeElement($supportAssetCreateur)) {
            // set the owning side to null (unless already changed)
            if ($supportAssetCreateur->getCreateur() === $this) {
                $supportAssetCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, SupportAssignSession>
     */
    public function getSupportAssignSessionCreateurs(): Collection
    {
        return $this->supportAssignSessionCreateurs;
    }

    public function addSupportAssignSessionCreateur(SupportAssignSession $supportAssignSessionCreateur): static
    {
        if (!$this->supportAssignSessionCreateurs->contains($supportAssignSessionCreateur)) {
            $this->supportAssignSessionCreateurs->add($supportAssignSessionCreateur);
            $supportAssignSessionCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeSupportAssignSessionCreateur(SupportAssignSession $supportAssignSessionCreateur): static
    {
        if ($this->supportAssignSessionCreateurs->removeElement($supportAssignSessionCreateur)) {
            // set the owning side to null (unless already changed)
            if ($supportAssignSessionCreateur->getCreateur() === $this) {
                $supportAssignSessionCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, SupportAssignUser>
     */
    public function getSupportAssignUserCreateurs(): Collection
    {
        return $this->supportAssignUserCreateurs;
    }

    public function addSupportAssignUserCreateur(SupportAssignUser $supportAssignUserCreateur): static
    {
        if (!$this->supportAssignUserCreateurs->contains($supportAssignUserCreateur)) {
            $this->supportAssignUserCreateurs->add($supportAssignUserCreateur);
            $supportAssignUserCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeSupportAssignUserCreateur(SupportAssignUser $supportAssignUserCreateur): static
    {
        if ($this->supportAssignUserCreateurs->removeElement($supportAssignUserCreateur)) {
            // set the owning side to null (unless already changed)
            if ($supportAssignUserCreateur->getCreateur() === $this) {
                $supportAssignUserCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, SupportDocument>
     */
    public function getSupportDocumentCreateurs(): Collection
    {
        return $this->supportDocumentCreateurs;
    }

    public function addSupportDocumentCreateur(SupportDocument $supportDocumentCreateur): static
    {
        if (!$this->supportDocumentCreateurs->contains($supportDocumentCreateur)) {
            $this->supportDocumentCreateurs->add($supportDocumentCreateur);
            $supportDocumentCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeSupportDocumentCreateur(SupportDocument $supportDocumentCreateur): static
    {
        if ($this->supportDocumentCreateurs->removeElement($supportDocumentCreateur)) {
            // set the owning side to null (unless already changed)
            if ($supportDocumentCreateur->getCreateur() === $this) {
                $supportDocumentCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, UtilisateurEntite>
     */
    public function getUtilisateurEntiteCreateurs(): Collection
    {
        return $this->utilisateurEntiteCreateurs;
    }

    public function addUtilisateurEntiteCreateur(UtilisateurEntite $utilisateurEntiteCreateur): static
    {
        if (!$this->utilisateurEntiteCreateurs->contains($utilisateurEntiteCreateur)) {
            $this->utilisateurEntiteCreateurs->add($utilisateurEntiteCreateur);
            $utilisateurEntiteCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeUtilisateurEntiteCreateur(UtilisateurEntite $utilisateurEntiteCreateur): static
    {
        if ($this->utilisateurEntiteCreateurs->removeElement($utilisateurEntiteCreateur)) {
            // set the owning side to null (unless already changed)
            if ($utilisateurEntiteCreateur->getCreateur() === $this) {
                $utilisateurEntiteCreateur->setCreateur(null);
            }
        }

        return $this;
    }




    /**
     * @return Collection<int, Depense>
     */
    public function getDepenseCreateurs(): Collection
    {
        return $this->depenseCreateurs;
    }

    public function addDepenseCreateur(Depense $depenseCreateur): static
    {
        if (!$this->depenseCreateurs->contains($depenseCreateur)) {
            $this->depenseCreateurs->add($depenseCreateur);
            $depenseCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeDepenseCreateur(Depense $depenseCreateur): static
    {
        if ($this->depenseCreateurs->removeElement($depenseCreateur)) {
            // set the owning side to null (unless already changed)
            if ($depenseCreateur->getCreateur() === $this) {
                $depenseCreateur->setCreateur(null);
            }
        }

        return $this;
    }







    /**
     * @return Collection<int, Depense>
     */
    public function getDepensesPayees(): Collection
    {
        return $this->depensesPayees;
    }

    public function addDepensePayee(Depense $d): static
    {
        if (!$this->depensesPayees->contains($d)) {
            $this->depensesPayees->add($d);
            $d->setPayeur($this);
        }
        return $this;
    }

    public function removeDepensePayee(Depense $d): static
    {
        if ($this->depensesPayees->removeElement($d)) {
            if ($d->getPayeur() === $this) {
                $d->setPayeur(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, Paiement>
     */
    public function getPaiements(): Collection
    {
        return $this->paiements;
    }

    public function addPaiement(Paiement $paiement): static
    {
        if (!$this->paiements->contains($paiement)) {
            $this->paiements->add($paiement);
            $paiement->setPayeurUtilisateur($this);
        }

        return $this;
    }

    public function removePaiement(Paiement $paiement): static
    {
        if ($this->paiements->removeElement($paiement)) {
            // set the owning side to null (unless already changed)
            if ($paiement->getPayeurUtilisateur() === $this) {
                $paiement->setPayeurUtilisateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, FiscalProfile>
     */
    public function getFiscalProfileCreateurs(): Collection
    {
        return $this->fiscalProfileCreateurs;
    }

    public function addFiscalProfileCreateur(FiscalProfile $fiscalProfileCreateur): static
    {
        if (!$this->fiscalProfileCreateurs->contains($fiscalProfileCreateur)) {
            $this->fiscalProfileCreateurs->add($fiscalProfileCreateur);
            $fiscalProfileCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeFiscalProfileCreateur(FiscalProfile $fiscalProfileCreateur): static
    {
        if ($this->fiscalProfileCreateurs->removeElement($fiscalProfileCreateur)) {
            // set the owning side to null (unless already changed)
            if ($fiscalProfileCreateur->getCreateur() === $this) {
                $fiscalProfileCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, TaxRule>
     */
    public function getTaxRuleCreateurs(): Collection
    {
        return $this->taxRuleCreateurs;
    }

    public function addTaxRuleCreateur(TaxRule $taxRuleCreateur): static
    {
        if (!$this->taxRuleCreateurs->contains($taxRuleCreateur)) {
            $this->taxRuleCreateurs->add($taxRuleCreateur);
            $taxRuleCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeTaxRuleCreateur(TaxRule $taxRuleCreateur): static
    {
        if ($this->taxRuleCreateurs->removeElement($taxRuleCreateur)) {
            // set the owning side to null (unless already changed)
            if ($taxRuleCreateur->getCreateur() === $this) {
                $taxRuleCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, TaxComputation>
     */
    public function getTaxComputationCreateurs(): Collection
    {
        return $this->taxComputationCreateurs;
    }

    public function addTaxComputationCreateur(TaxComputation $taxComputationCreateur): static
    {
        if (!$this->taxComputationCreateurs->contains($taxComputationCreateur)) {
            $this->taxComputationCreateurs->add($taxComputationCreateur);
            $taxComputationCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeTaxComputationCreateur(TaxComputation $taxComputationCreateur): static
    {
        if ($this->taxComputationCreateurs->removeElement($taxComputationCreateur)) {
            // set the owning side to null (unless already changed)
            if ($taxComputationCreateur->getCreateur() === $this) {
                $taxComputationCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ElearningCourse>
     */
    public function getElearningCourseCreateurs(): Collection
    {
        return $this->elearningCourseCreateurs;
    }

    public function addElearningCourseCreateur(ElearningCourse $elearningCourseCreateur): static
    {
        if (!$this->elearningCourseCreateurs->contains($elearningCourseCreateur)) {
            $this->elearningCourseCreateurs->add($elearningCourseCreateur);
            $elearningCourseCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeElearningCourseCreateur(ElearningCourse $elearningCourseCreateur): static
    {
        if ($this->elearningCourseCreateurs->removeElement($elearningCourseCreateur)) {
            // set the owning side to null (unless already changed)
            if ($elearningCourseCreateur->getCreateur() === $this) {
                $elearningCourseCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ElearningNode>
     */
    public function getElearningNodeCreateurs(): Collection
    {
        return $this->elearningNodeCreateurs;
    }

    public function addElearningNodeCreateur(ElearningNode $elearningNodeCreateur): static
    {
        if (!$this->elearningNodeCreateurs->contains($elearningNodeCreateur)) {
            $this->elearningNodeCreateurs->add($elearningNodeCreateur);
            $elearningNodeCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeElearningNodeCreateur(ElearningNode $elearningNodeCreateur): static
    {
        if ($this->elearningNodeCreateurs->removeElement($elearningNodeCreateur)) {
            // set the owning side to null (unless already changed)
            if ($elearningNodeCreateur->getCreateur() === $this) {
                $elearningNodeCreateur->setCreateur(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ElearningBlock>
     */
    public function getElearningBlockCreateurs(): Collection
    {
        return $this->elearningBlockCreateurs;
    }

    public function addElearningBlockCreateur(ElearningBlock $elearningBlockCreateur): static
    {
        if (!$this->elearningBlockCreateurs->contains($elearningBlockCreateur)) {
            $this->elearningBlockCreateurs->add($elearningBlockCreateur);
            $elearningBlockCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeElearningBlockCreateur(ElearningBlock $elearningBlockCreateur): static
    {
        if ($this->elearningBlockCreateurs->removeElement($elearningBlockCreateur)) {
            // set the owning side to null (unless already changed)
            if ($elearningBlockCreateur->getCreateur() === $this) {
                $elearningBlockCreateur->setCreateur(null);
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
            $elearningEnrollment->setStagiaire($this);
        }

        return $this;
    }

    public function removeElearningEnrollment(ElearningEnrollment $elearningEnrollment): static
    {
        if ($this->elearningEnrollments->removeElement($elearningEnrollment)) {
            // set the owning side to null (unless already changed)
            if ($elearningEnrollment->getStagiaire() === $this) {
                $elearningEnrollment->setStagiaire(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ElearningEnrollment>
     */
    public function getElearningEnrollmentCreateur(): Collection
    {
        return $this->elearningEnrollmentCreateur;
    }

    public function addElearningEnrollmentCreateur(ElearningEnrollment $elearningEnrollmentCreateur): static
    {
        if (!$this->elearningEnrollmentCreateur->contains($elearningEnrollmentCreateur)) {
            $this->elearningEnrollmentCreateur->add($elearningEnrollmentCreateur);
            $elearningEnrollmentCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeElearningEnrollmentCreateur(ElearningEnrollment $elearningEnrollmentCreateur): static
    {
        if ($this->elearningEnrollmentCreateur->removeElement($elearningEnrollmentCreateur)) {
            // set the owning side to null (unless already changed)
            if ($elearningEnrollmentCreateur->getCreateur() === $this) {
                $elearningEnrollmentCreateur->setCreateur(null);
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
            $elearningOrder->setBuyer($this);
        }

        return $this;
    }

    public function removeElearningOrder(ElearningOrder $elearningOrder): static
    {
        if ($this->elearningOrders->removeElement($elearningOrder)) {
            // set the owning side to null (unless already changed)
            if ($elearningOrder->getBuyer() === $this) {
                $elearningOrder->setBuyer(null);
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
            $entrepriseDocument->setUploadedBy($this);
        }

        return $this;
    }

    public function removeEntrepriseDocument(EntrepriseDocument $entrepriseDocument): static
    {
        if ($this->entrepriseDocuments->removeElement($entrepriseDocument)) {
            // set the owning side to null (unless already changed)
            if ($entrepriseDocument->getUploadedBy() === $this) {
                $entrepriseDocument->setUploadedBy(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, EntrepriseDocument>
     */
    public function getEntrepriseDocumentCreateurs(): Collection
    {
        return $this->entrepriseDocumentCreateurs;
    }

    public function addEntrepriseDocumentCreateur(EntrepriseDocument $entrepriseDocumentCreateur): static
    {
        if (!$this->entrepriseDocumentCreateurs->contains($entrepriseDocumentCreateur)) {
            $this->entrepriseDocumentCreateurs->add($entrepriseDocumentCreateur);
            $entrepriseDocumentCreateur->setCreateur($this);
        }

        return $this;
    }

    public function removeEntrepriseDocumentCreateur(EntrepriseDocument $entrepriseDocumentCreateur): static
    {
        if ($this->entrepriseDocumentCreateurs->removeElement($entrepriseDocumentCreateur)) {
            // set the owning side to null (unless already changed)
            if ($entrepriseDocumentCreateur->getCreateur() === $this) {
                $entrepriseDocumentCreateur->setCreateur(null);
            }
        }

        return $this;
    }


    public function hasRole(string $role): bool
    {
        return in_array($role, $this->getRoles(), true);
    }

    public function hasAnyRole(array $roles): bool
    {
        foreach ($roles as $role) {
            if ($this->hasRole($role)) {
                return true;
            }
        }
        return false;
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
            $entreprise->setRepresentant($this);
        }

        return $this;
    }

    public function removeEntreprise(Entreprise $entreprise): static
    {
        if ($this->entreprises->removeElement($entreprise)) {
            // set the owning side to null (unless already changed)
            if ($entreprise->getRepresentant() === $this) {
                $entreprise->setRepresentant(null);
            }
        }

        return $this;
    }


    public function needsOnboarding(): bool
    {
        // si l'utilisateur a déjà créé une entité => onboarding terminé
        if ($this->entites && $this->entites->count() > 0) {
            return false;
        }

        // sinon onboarding oui (il doit créer son premier organisme)
        return true;
    }
}
