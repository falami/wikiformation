<?php

declare(strict_types=1);
namespace App\Service\Quality;

use App\Entity\{Entite, Formation, Session, Inscription, PositioningAttempt, QcmAttempt, SatisfactionAttempt, FormateurSatisfactionAttempt, DocumentFormateur, ContratFormateur, RapportFormateur, PieceDossier, AuditLog, QualityRecord};
use App\Enum\{StatusInscription, StatusSession};
use App\Form\Administrateur\FormationPublicInformationType;
use App\Service\Document\RealisationCertificate;
use Doctrine\ORM\EntityManagerInterface;

/** Documentary inventory only: existence of a record never proves compliance. */
final class QualityReadiness
{
    public const SOURCES = [
        'Référentiel qualité en vigueur au 08/10/2026' => 'https://www.legifrance.gouv.fr/jorf/article_jo/JORFARTI000038565276',
        'Évolution applicable le 01/11/2026' => 'https://www.legifrance.gouv.fr/jorf/id/JORFTEXT000054608509',
        'Guide de lecture ministériel' => 'https://travail-emploi.gouv.fr/referentiel-national-qualite-guide-de-lecture-qualiopi',
        'Notice officielle BPF' => 'https://www.formulaires.service-public.fr/gf/getNotice.do?cerfaNotice=50199&cerfaFormulaire=10443',
    ];
    public function __construct(private EntityManagerInterface $em, private RealisationCertificate $certificates) {}

    public static function formationMissing(Formation $formation): array
    {
        $missing = [];
        foreach ([
            'Public visé' => $formation->getPublic(), 'Prérequis' => $formation->getConditionPrealable(),
            'Objectifs' => $formation->getObjectifs(), 'Méthodes pédagogiques' => $formation->getPedagogie(),
            'Modalités d’évaluation' => $formation->getModalitesEvaluation(),
        ] as $label => $value) {
            $text = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            // Rich-text editors retain non-breaking spaces and invisible placeholders in empty paragraphs.
            if (preg_match('/[^\s\p{Z}\x{200B}\x{FEFF}]/u', $text) !== 1) $missing[] = $label;
        }
        if (!$formation->getDuree()) $missing[] = 'Durée';
        foreach (FormationPublicInformationType::FIELDS as $key => [$label]) {
            if (trim((string) ($formation->getPublicInformation()[$key] ?? '')) === '') $missing[] = $label;
        }
        return $missing;
    }

    public function inspect(Entite $entite): array
    {
        $counts = [];
        foreach ([
            'sessions' => Session::class, 'positionnements' => PositioningAttempt::class,
            'evaluations' => QcmAttempt::class, 'satisfactions' => SatisfactionAttempt::class,
            'satisfactions_formateurs' => FormateurSatisfactionAttempt::class, 'modeles' => DocumentFormateur::class,
            'contrats_formateurs' => ContratFormateur::class, 'rapports' => RapportFormateur::class,
            'pieces' => PieceDossier::class, 'traces' => AuditLog::class, 'registre_qualite' => QualityRecord::class,
        ] as $key => $class) $counts[$key] = $this->em->getRepository($class)->count(['entite' => $entite]);
        $formations = $this->em->getRepository(Formation::class)->findBy(['entite' => $entite, 'isPublic' => true], ['titre' => 'ASC']);
        $gaps = [];
        foreach ($formations as $formation) {
            $missing = self::formationMissing($formation);
            if ($missing) $gaps[] = ['id' => $formation->getId(), 'title' => $formation->getTitre(), 'missing' => $missing, 'fields' => self::formationFields($missing)];
        }
        $identity = [];
        foreach (['SIRET' => $entite->getSiret(), 'Déclaration d’activité' => $entite->getNumeroDeclarant(), 'Adresse' => $entite->getAdresse(), 'Code postal' => $entite->getCodePostal(), 'Ville' => $entite->getVille(), 'Région' => $entite->getRegion(), 'E-mail' => $entite->getEmail(), 'Représentant' => $entite->getNomRepresentant()] as $key => $value) if (!trim((string) $value)) $identity[] = $key;
        $distanceGaps = $this->em->getRepository(Session::class)->createQueryBuilder('s')
            ->where('s.entite = :entite')->andWhere('s.tauxDistanciel IS NULL')->andWhere('s.status != :cancelled')
            ->setParameter('entite', $entite)->setParameter('cancelled', \App\Enum\StatusSession::CANCELED->value);
        $distanceGapCount = (int) (clone $distanceGaps)->select('COUNT(s.id)')->getQuery()->getSingleScalarResult();
        return [
            'generatedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format(DATE_ATOM),
            'entity' => ['id' => $entite->getId(), 'name' => $entite->getNom()],
            'scope' => 'Inventaire technique, tous dossiers conservés de cet organisme. Ni audit de certification, ni validation administrative.',
            'publicFormationCount' => count($formations), 'publicationGaps' => $gaps, 'identityGaps' => $identity,
            'distanceGapCount' => $distanceGapCount,
            'distanceGaps' => $distanceGaps->select('s.id', 's.code')->orderBy('s.id', 'DESC')->setMaxResults(10)->getQuery()->getArrayResult(),
            'inventory' => $counts, 'criteria' => self::criteria(), 'sources' => self::SOURCES,
            'signatureMissing' => !$entite->getPreferences()?->getSignatureOrganismeNom() || !$entite->getPreferences()?->getSignatureOrganismePath(),
            'followUp' => $this->followUp($entite),
            'overdueRecords' => $this->em->getRepository(QualityRecord::class)->createQueryBuilder('q')
                ->select('q.id', 'q.title', 'q.dueAt')->where('q.entite = :entite')->andWhere('q.status != :closed')->andWhere('q.dueAt < :today')
                ->setParameter('entite', $entite)->setParameter('closed', 'closed')->setParameter('today', new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris')))
                ->orderBy('q.dueAt', 'ASC')->setMaxResults(10)->getQuery()->getArrayResult(),
        ];
    }

    private static function formationFields(array $missing): array
    {
        $anchors = ['Public visé' => 'formation-public', 'Prérequis' => 'formation-prerequis', 'Objectifs' => 'formation-objectifs',
            'Méthodes pédagogiques' => 'formation-pedagogie', 'Modalités d’évaluation' => 'formation-evaluation', 'Durée' => 'formation-identification'];
        return array_map(static fn (string $label): array => ['label' => $label, 'anchor' => $anchors[$label] ?? 'informations-publiques'], $missing);
    }

    /** Bounded, read-only review; never infer an absence or close a learner's record. */
    private function followUp(Entite $entite): array
    {
        $endedSessions = $this->em->getRepository(Session::class)->createQueryBuilder('ended')
            ->select('ended.id')->join('ended.jours', 'day')->where('ended.entite = :entite')
            ->andWhere('ended.status NOT IN (:excluded)')->groupBy('ended.id')->having('MAX(day.dateFin) <= :now')->getDQL();
        $query = $this->em->getRepository(Inscription::class)->createQueryBuilder('i')
            ->join('i.session', 's')->where('i.entite = :entite')->andWhere('s.entite = :entite')
            ->andWhere('i.session IN ('.$endedSessions.')')->andWhere('i.status IN (:statuses)')
            ->setParameter('entite', $entite)->setParameter('excluded', [StatusSession::DRAFT->value, StatusSession::CANCELED->value])
            ->setParameter('statuses', [StatusInscription::CONFIRME->value, StatusInscription::EN_COURS->value, StatusInscription::TERMINE->value])
            ->setParameter('now', new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')));
        $total = (int) (clone $query)->select('COUNT(i.id)')->getQuery()->getSingleScalarResult();
        $records = $query->addSelect('s')->orderBy('i.id', 'DESC')->setMaxResults(30)->getQuery()->getResult();
        $items = [];
        foreach ($records as $inscription) {
            $data = $this->certificates->data($inscription);
            if (!$data['issues']) continue;
            $items[] = ['id' => $inscription->getId(), 'session' => $inscription->getSession()->getCode(),
                'learner' => trim($inscription->getStagiaire()?->getPrenom().' '.$inscription->getStagiaire()?->getNom()),
                'issues' => $data['issues'], 'hours' => $data['hours'],
                'attendance' => $data['hours'] === null && !$inscription->getSession()->isSousTraitance()];
        }
        return ['total' => $total, 'checked' => count($records), 'items' => $items];
    }

    public static function criteria(): array
    {
        return [
            ['id' => 1, 'title' => 'Informer le public', 'indicators' => '1 à 3', 'inventory' => [],
                'proofs' => 'Programme daté, conditions d’accès, tarifs, accessibilité, résultats avec période et méthode de calcul. Pour les formations certifiantes : fiche RNCP/RS en vigueur, habilitation et débouchés.',
                'review' => 'Relire les informations publiées et justifier chaque résultat annoncé. La présence d’un champ rempli ne prouve pas son exactitude.'],
            ['id' => 2, 'title' => 'Définir et adapter la prestation', 'indicators' => '4 à 8', 'inventory' => ['positionnements'],
                'proofs' => 'Analyse des besoins, objectifs évaluables, positionnement initial, programme et adaptation au bénéficiaire. Adéquation à la certification lorsqu’elle est préparée.',
                'review' => 'Contrôler les réponses et décisions d’adaptation ; un questionnaire affecté ou ouvert ne prouve pas qu’il a été réalisé.'],
            ['id' => 3, 'title' => 'Accompagner et évaluer', 'indicators' => '9 à 16', 'inventory' => ['sessions', 'evaluations', 'rapports', 'pieces'],
                'proofs' => 'Convocations, règlement intérieur communiqué, suivi effectif, évaluations, prévention des abandons et adaptations. Obligations spécifiques pour alternance, apprentissage et certification.',
                'review' => 'Vérifier les preuves par bénéficiaire, les absences et les acquis. Les présences et la satisfaction ne démontrent pas à elles seules la réussite.'],
            ['id' => 4, 'title' => 'Mobiliser les moyens', 'indicators' => '17 à 20', 'inventory' => ['modeles', 'contrats_formateurs'],
                'proofs' => 'Moyens humains, locaux et équipements adaptés, coordination, ressources pédagogiques accessibles et accompagnement à leur utilisation. Dispositions propres aux CFA si applicables.',
                'review' => 'Documenter les moyens réellement utilisés et leur accessibilité ; le dépôt d’un fichier ne prouve pas sa remise ni son appropriation.'],
            ['id' => 5, 'title' => 'Maintenir les compétences', 'indicators' => '21 et 22', 'inventory' => ['contrats_formateurs'],
                'proofs' => 'CV, qualifications, expérience, évaluation des intervenants et développement des compétences des salariés.',
                'review' => 'Conserver des justificatifs à jour. Un contrat formateur signé ne démontre pas ses compétences.'],
            ['id' => 6, 'title' => 'Veille, handicap et partenaires', 'indicators' => '23 à 29', 'inventory' => ['registre_qualite'],
                'proofs' => 'Veilles réglementaire, métier et pédagogique avec suites données ; réseau handicap ; maîtrise de la sous-traitance. Preuves spécifiques aux périodes en entreprise et à l’apprentissage.',
                'review' => 'À documenter dans vos procédures et registres : la plateforme ne permet pas de déduire automatiquement leur mise en œuvre.'],
            ['id' => 7, 'title' => 'Écouter et améliorer', 'indicators' => '30 à 32', 'inventory' => ['satisfactions', 'satisfactions_formateurs', 'registre_qualite'],
                'proofs' => 'Retours bénéficiaires, équipes, entreprises et financeurs concernés ; traitement des difficultés, réclamations et aléas ; actions d’amélioration suivies.',
                'review' => 'Tenir un registre des réclamations et des actions avec responsable, échéance et vérification d’efficacité. Les questionnaires seuls ne couvrent pas ce critère.'],
        ];
    }
}
