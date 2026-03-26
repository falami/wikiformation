<?php

namespace App\Controller\Administrateur;

use App\Enum\StatusSession;
use App\Service\Pdf\PdfManager;
use App\Entity\{UtilisateurEntite, Entite, Utilisateur, ContratFormateur, SessionJour, Formateur, Emargement, Site, Session, Inscription, ConventionContrat, SessionPiece};
use App\Enum\SessionPieceType;
use App\Enum\ContratFormateurStatus;
use App\Enum\TypeFinancement;
use App\Enum\StatusInscription;
use App\Service\Email\MailerManager;
use App\Form\Administrateur\SessionType;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\Geocoding\NominatimGeocoder;
use Symfony\Component\Routing\Attribute\Route;
use App\Service\Satisfaction\SatisfactionAssigner;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse, RedirectResponse};
use App\Service\Sequence\SessionNumberGenerator;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use App\Service\Sequence\ContratFormateurNumberGenerator;
use App\Entity\QcmAssignment;
use App\Service\FormateurSatisfaction\FormateurSatisfactionAssigner as FormateurSatAssigner;
use App\Enum\PieceType;
use App\Entity\Qcm;
use App\Entity\SupportAssignSession;
use App\Entity\SupportAssignUser;
use App\Security\Permission\TenantPermission;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Service\Billing\BillingGuard;
use App\Exception\BillingQuotaExceededException;


#[Route('/administrateur/{entite}/session')]
#[IsGranted(TenantPermission::SESSION_MANAGE, subject: 'entite')]
final class SessionController extends AbstractController
{
    public function __construct(
        private UtilisateurEntiteManager $utilisateurEntiteManager,
        private MailerManager $mailerManager,
        private SatisfactionAssigner $satisfactionAssigner,
        private ContratFormateurNumberGenerator $contratNumberGenerator,
        private FormateurSatAssigner $formateurSatisfactionAssigner,
        private BillingGuard $billingGuard,
    ) {}


    #[Route(
        '/{session}/qcm/{qcm}/{phase}/questionnaire.pdf',
        name: 'app_administrateur_session_qcm_questionnaire_pdf',
        requirements: ['entite' => '\d+', 'session' => '\d+', 'qcm' => '\d+']
    )]
    public function qcmQuestionnairePdf(
        Entite $entite,
        Session $session,
        Qcm $qcm,
        string $phase,
        EntityManagerInterface $em,
        PdfManager $pdf // ou ton service PDF
    ): Response {


        if ($session->getEntite()?->getId() !== $entite->getId()) throw $this->createNotFoundException();
        if ($qcm->getEntite()?->getId() !== $entite->getId()) throw $this->createNotFoundException();

        // ✅ vérifie que ce QCM est bien assigné à cette session pour cette phase
        $exists = $em->getRepository(QcmAssignment::class)->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.session = :s')->setParameter('s', $session)
            ->andWhere('a.qcm = :q')->setParameter('q', $qcm)
            ->andWhere('a.phase = :p')->setParameter('p', $phase) // adapte si Enum
            ->getQuery()->getSingleScalarResult();

        if ((int)$exists === 0) throw $this->createNotFoundException();

        $questions = $qcm->getQuestions(); // options déjà ordonnées via mapping

        $html = $this->renderView('pdf/qcm_questionnaire.html.twig', [
            'entite' => $entite,
            'session' => $session,
            'qcm' => $qcm,
            'phase' => $phase,
            'questions' => $questions,
            'generatedAt' => new \DateTimeImmutable(),
        ]);

        $filename = sprintf('QCM_%s_%s_%s.pdf', $session->getCode(), $phase, $qcm->getId());

        // adapte à TON PdfManager :
        return $pdf->streamPdfFromHtml($html, $filename, 'portrait');
    }



    #[Route('', name: 'app_administrateur_session_index', methods: ['GET'])]
    public function index(Entite $entite): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();


        return $this->render(
            'administrateur/session/index.html.twig',
            [
                'entite' => $entite,

            ]
        );
    }



    #[Route('/ajax', name: 'app_administrateur_session_ajax', methods: ['POST'])]
    public function ajax(Entite $entite, Request $request, EntityManagerInterface $em): JsonResponse
    {


        try {
            $draw   = $request->request->getInt('draw', 1);
            $start  = $request->request->getInt('start', 0);
            $length = $request->request->getInt('length', 10);


            $dossierFilter   = (string)$request->request->get('dossierFilter', 'all'); // all|complete|missing
            $dateFrom        = (string)$request->request->get('dateFrom', '');
            $dateTo          = (string)$request->request->get('dateTo', '');
            $formateurFilter = trim((string)$request->request->get('formateurFilter', ''));

            $order = $request->request->all('order');
            $columns = $request->request->all('columns');
            $orderColIdx = (int)($order[0]['column'] ?? 0);
            $orderDir = strtolower((string)($order[0]['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
            $orderName = (string)($columns[$orderColIdx]['name'] ?? '');

            $search  = $request->request->all('search');
            $searchV = trim((string)($search['value'] ?? ''));

            $statusFilter    = (string)$request->request->get('statusFilter', 'all');
            $formationFilter = (string)$request->request->get('formationFilter', 'all');

            $repo = $em->getRepository(Session::class);

            $baseQb = $repo->createQueryBuilder('s')
                ->leftJoin('s.formation', 'f')->addSelect('f')
                ->leftJoin('s.site', 'site')->addSelect('site')
                ->leftJoin('s.engin', 'b')->addSelect('b')
                ->leftJoin('s.formateur', 'sk')->addSelect('sk')
                ->leftJoin('sk.utilisateur', 'u')->addSelect('u')
                ->andWhere('s.entite = :entite')
                ->setParameter('entite', $entite);

            $recordsTotal = (int)(clone $baseQb)
                ->select('COUNT(DISTINCT s.id)')
                ->getQuery()
                ->getSingleScalarResult();

            $filteredQb = clone $baseQb;

            // ===== Search global =====
            if ($searchV !== '') {
                $filteredQb
                    ->andWhere('(s.code LIKE :q OR f.titre LIKE :q OR s.formationIntituleLibre LIKE :q OR site.nom LIKE :q OR b.nom LIKE :q OR u.nom LIKE :q OR u.prenom LIKE :q)')
                    ->setParameter('q', '%' . $searchV . '%');
            }

            // ===== Filtres =====
            if ($statusFilter !== 'all') {
                $st = StatusSession::tryFrom($statusFilter);
                if ($st) {
                    $filteredQb->andWhere('s.status = :st')->setParameter('st', $st);
                }
            }

            if ($formationFilter !== 'all') {
                $fid = (int)$formationFilter;
                if ($fid > 0) {
                    $filteredQb->andWhere('f.id = :fid')->setParameter('fid', $fid);
                }
            }

            // ===== Période (chevauchement via SessionJour) =====
            if ($dateFrom !== '' || $dateTo !== '') {
                $from = $dateFrom !== '' ? new \DateTimeImmutable($dateFrom . ' 00:00:00') : null;
                $to   = $dateTo   !== '' ? new \DateTimeImmutable($dateTo   . ' 23:59:59') : null;

                $subQb = $em->createQueryBuilder()
                    ->select('1')
                    ->from(SessionJour::class, 'sjf')
                    ->where('sjf.session = s');

                if ($from && $to) {
                    $subQb->andWhere('sjf.dateDebut <= :to AND sjf.dateFin >= :from');
                    $filteredQb->setParameter('from', $from)->setParameter('to', $to);
                } elseif ($from) {
                    $subQb->andWhere('sjf.dateFin >= :from');
                    $filteredQb->setParameter('from', $from);
                } elseif ($to) {
                    $subQb->andWhere('sjf.dateDebut <= :to');
                    $filteredQb->setParameter('to', $to);
                }

                $filteredQb->andWhere('EXISTS(' . $subQb->getDQL() . ')');
            }

            // ===== Formateur (nom/prénom) =====
            if ($formateurFilter !== '') {
                $filteredQb
                    ->andWhere('(LOWER(u.nom) LIKE :fu OR LOWER(u.prenom) LIKE :fu)')
                    ->setParameter('fu', '%' . mb_strtolower($formateurFilter) . '%');
            }

            // ===== Filtre dossier (EXISTS) =====
            // ===== Filtre dossier (EXISTS) =====
            if ($dossierFilter !== 'all') {

                // 0) au moins une inscription
                $subHasIns = $em->createQueryBuilder()
                    ->select('1')
                    ->from(Inscription::class, 'i0')
                    ->where('i0.session = s')
                    ->getDQL();

                // 1) dossier inscription manquant
                $subMissingDossier = $em->createQueryBuilder()
                    ->select('1')
                    ->from(Inscription::class, 'i1')
                    // ⚠️ ICI : mets le BON nom d’association !
                    ->leftJoin('i1.dossier', 'd1') // <- adapte si besoin
                    ->where('i1.session = s')
                    ->andWhere('d1.id IS NULL')
                    ->getDQL();

                // 2) contrat formateur : absent OU brouillon (avec entite)
                $subHasContrat = $em->createQueryBuilder()
                    ->select('1')
                    ->from(ContratFormateur::class, 'c0')
                    ->where('c0.session = s')
                    ->andWhere('c0.entite = :entite')
                    ->getDQL();

                $subContratNotSigned = $em->createQueryBuilder()
                    ->select('1')
                    ->from(ContratFormateur::class, 'c1')
                    ->where('c1.session = s')
                    ->andWhere('c1.entite = :entite')
                    ->andWhere('c1.status = :cf_brouillon')
                    ->getDQL();

                // 3) conventions : manquantes ou non signées
                // On part des entreprises impliquées dans la session
                $subExistsConv = $em->createQueryBuilder()
                    ->select('1')
                    ->from(ConventionContrat::class, 'cc2')
                    ->where('cc2.session = s')
                    ->andWhere('cc2.entite = :entite')
                    ->andWhere('cc2.entreprise = e2')
                    ->getDQL();

                $subEntrepriseSansConvention = $em->createQueryBuilder()
                    ->select('1')
                    ->from(Inscription::class, 'i2')
                    ->innerJoin('i2.entreprise', 'e2')
                    ->where('i2.session = s')
                    ->andWhere('e2 IS NOT NULL')
                    ->andWhere('NOT EXISTS(' . $subExistsConv . ')')
                    ->getDQL();


                $subEntrepriseConventionNonSignee = $em->createQueryBuilder()
                    ->select('1')
                    ->from(Inscription::class, 'i3')
                    ->innerJoin('i3.entreprise', 'e3')
                    ->innerJoin(
                        ConventionContrat::class,
                        'cc3',
                        'WITH',
                        'cc3.session = s AND cc3.entite = :entite AND cc3.entreprise = e3'
                    )
                    ->where('i3.session = s')
                    ->andWhere('e3 IS NOT NULL')
                    ->andWhere('cc3.dateSignatureStagiaire IS NULL')
                    ->andWhere('cc3.dateSignatureEntreprise IS NULL')
                    ->andWhere('cc3.dateSignatureOf IS NULL')
                    ->getDQL();

                // 4) émargement non signé (approx) + override “scan déposé”
                $subEmargNotSigned = $em->createQueryBuilder()
                    ->select('1')
                    ->from(Emargement::class, 'em2')
                    ->where('em2.session = s')
                    ->andWhere('em2.signedAt IS NULL')
                    ->andWhere('(em2.signatureDataUrl IS NULL OR em2.signatureDataUrl = \'\')')
                    ->andWhere('(em2.signaturePath IS NULL OR em2.signaturePath = \'\')')
                    ->getDQL();

                $subEmargUploaded = $em->createQueryBuilder()
                    ->select('1')
                    ->from(SessionPiece::class, 'spem')
                    ->where('spem.session = s')
                    ->andWhere('spem.entite = :entite')
                    ->andWhere('spem.type = :emarg_type')
                    ->getDQL();


                // overrides upload convention / contrat formateur signé
                $subConvUploaded = $em->createQueryBuilder()
                    ->select('1')
                    ->from(SessionPiece::class, 'spcv')
                    ->where('spcv.session = s')
                    ->andWhere('spcv.entite = :entite')
                    ->andWhere('spcv.type = :conv_type')
                    ->getDQL();

                $subCfUploaded = $em->createQueryBuilder()
                    ->select('1')
                    ->from(SessionPiece::class, 'spcf')
                    ->where('spcf.session = s')
                    ->andWhere('spcf.entite = :entite')
                    ->andWhere('spcf.type = :cf_type')
                    ->getDQL();

                $incompleteDql =
                    'EXISTS(' . $subMissingDossier . ')'
                    . ' OR (s.formateur IS NOT NULL AND NOT EXISTS(' . $subCfUploaded . ') AND (NOT EXISTS(' . $subHasContrat . ') OR EXISTS(' . $subContratNotSigned . ')))'
                    . ' OR (((EXISTS(' . $subEntrepriseSansConvention . ') OR EXISTS(' . $subEntrepriseConventionNonSignee . ')) AND NOT EXISTS(' . $subConvUploaded . ')))'
                    . ' OR (EXISTS(' . $subEmargNotSigned . ') AND NOT EXISTS(' . $subEmargUploaded . '))';

                if ($dossierFilter === 'missing') {
                    $filteredQb
                        ->andWhere('EXISTS(' . $subHasIns . ')')
                        ->andWhere('(' . $incompleteDql . ')');
                } elseif ($dossierFilter === 'complete') {
                    $filteredQb
                        ->andWhere('EXISTS(' . $subHasIns . ')')
                        ->andWhere('NOT (' . $incompleteDql . ')');
                }

                // Paramètres du filtre dossier
                $filteredQb
                    ->setParameter('cf_brouillon', ContratFormateurStatus::BROUILLON)
                    ->setParameter('emarg_type', SessionPieceType::EMARGEMENT_SIGNE)
                    ->setParameter('conv_type', SessionPieceType::CONVENTION_SIGNEE)
                    ->setParameter('cf_type', SessionPieceType::CONTRAT_FORMATEUR_SIGNE);
            }


            $recordsFiltered = (int)(clone $filteredQb)
                ->select('COUNT(DISTINCT s.id)')
                ->getQuery()
                ->getSingleScalarResult();

            // ===== Tri dates (MIN / MAX via sous-requêtes) =====
            $subMinStart = $em->createQueryBuilder()
                ->select('MIN(sjmin.dateDebut)')
                ->from(SessionJour::class, 'sjmin')
                ->where('sjmin.session = s')
                ->getDQL();


            $subMaxEnd = $em->createQueryBuilder()
                ->select('MAX(sjmax.dateFin)')
                ->from(SessionJour::class, 'sjmax')
                ->where('sjmax.session = s')
                ->getDQL();

            switch ($orderName) {
                case 'id':
                    $filteredQb->orderBy('s.id', $orderDir);
                    break;
                case 'code':
                    $filteredQb->orderBy('s.code', $orderDir);
                    break;
                case 'formation':
                    $filteredQb->orderBy('f.titre', $orderDir);
                    break;
                case 'site':
                    $filteredQb->orderBy('site.nom', $orderDir);
                    break;
                case 'formateur':
                    $filteredQb->orderBy('u.nom', $orderDir)->addOrderBy('u.prenom', $orderDir);
                    break;
                case 'dates':
                    $filteredQb
                        ->addSelect("($subMinStart) AS HIDDEN start_sort")
                        ->addSelect("($subMaxEnd)  AS HIDDEN end_sort")
                        ->orderBy('start_sort', $orderDir)
                        ->addOrderBy('end_sort', $orderDir);
                    break;
                case 'status':
                    $filteredQb->orderBy('s.status', $orderDir);
                    break;
                case 'tarif':
                    // tri = montantCents si défini, sinon prix formation
                    $filteredQb->addSelect('COALESCE(s.montantCents, f.prixBaseCents) AS HIDDEN tarif_sort');
                    $filteredQb->orderBy('tarif_sort', $orderDir);
                    break;

                case 'dossier':
                    // tri "complet d'abord" basé UNIQUEMENT sur dossiers manquants (comme ton code)
                    $subMissing = $em->createQueryBuilder()
                        ->select('1')
                        ->from(Inscription::class, 'i2')
                        ->leftJoin('i2.dossier', 'd2')
                        ->where('i2.session = s')
                        ->andWhere('d2.id IS NULL')
                        ->getDQL();

                    $filteredQb->addSelect("(CASE WHEN NOT EXISTS($subMissing) THEN 1 ELSE 0 END) AS HIDDEN dossier_complete");
                    $filteredQb->orderBy('dossier_complete', $orderDir)->addOrderBy('s.id', 'DESC');
                    break;
                default:
                    // ✅ tri par défaut = date de début (1ère journée), puis date de fin, puis id
                    $filteredQb
                        ->addSelect("($subMinStart) AS HIDDEN start_sort")
                        ->addSelect("($subMaxEnd)  AS HIDDEN end_sort")
                        ->orderBy('start_sort', 'DESC')     // ou 'ASC' si tu veux les plus anciennes d’abord
                        ->addOrderBy('end_sort', 'DESC')
                        ->addOrderBy('s.id', 'DESC');
                    break;
            }

            // ===== Pagination + fetch =====
            $rows = $filteredQb
                ->setFirstResult($start)
                ->setMaxResults($length)
                ->getQuery()
                ->getResult();

            $ids = array_map(fn(Session $s) => (int)$s->getId(), $rows);

            // =========================
            // STATS (batch DBAL)
            // =========================
            $stats = [
                'ins' => [],
                'dossiers' => [],
                'contrats' => [],
                'emarg' => [],
                'emarg_expected' => [],
                'fact' => [],
                'conv' => [],
                'satisfaction' => [],
                'pieces' => [],
                'participants' => [],
            ];

            if (!empty($ids)) {
                $conn = $em->getConnection();

                // 1) Inscriptions
                $rowsIns = $conn->executeQuery(
                    "SELECT session_id AS sid, COUNT(*) AS total
                 FROM inscription
                 WHERE session_id IN (?)
                 GROUP BY session_id",
                    [$ids],
                    [\Doctrine\DBAL\ArrayParameterType::INTEGER]
                )->fetchAllAssociative();

                foreach ($rowsIns as $r) {
                    $stats['ins'][(int)$r['sid']] = ['total' => (int)$r['total']];
                }

                // 2) Dossiers
                $rowsDos = $conn->executeQuery(
                    "SELECT i.session_id AS sid,
                        SUM(CASE WHEN d.id IS NOT NULL THEN 1 ELSE 0 END) AS with_dossier,
                        SUM(CASE WHEN d.id IS NULL THEN 1 ELSE 0 END) AS missing_dossier
                 FROM inscription i
                 LEFT JOIN dossier_inscription d ON d.inscription_id = i.id
                 WHERE i.session_id IN (?)
                 GROUP BY i.session_id",
                    [$ids],
                    [\Doctrine\DBAL\ArrayParameterType::INTEGER]
                )->fetchAllAssociative();

                foreach ($rowsDos as $r) {
                    $stats['dossiers'][(int)$r['sid']] = [
                        'with'    => (int)$r['with_dossier'],
                        'missing' => (int)$r['missing_dossier'],
                    ];
                }

                // 3) Contrats formateurs
                $rowsContrats = $conn->executeQuery(
                    "SELECT session_id AS sid,
                        COUNT(*) AS total,
                        SUM(CASE WHEN status = 'BROUILLON' THEN 1 ELSE 0 END) AS draft,
                        SUM(CASE WHEN status IN ('SIGNE','SIGNED','VALIDE','VALIDATED') THEN 1 ELSE 0 END) AS signed
                 FROM contrat_formateur
                 WHERE session_id IN (?)
                 GROUP BY session_id",
                    [$ids],
                    [\Doctrine\DBAL\ArrayParameterType::INTEGER]
                )->fetchAllAssociative();

                foreach ($rowsContrats as $r) {
                    $stats['contrats'][(int)$r['sid']] = [
                        'total'  => (int)$r['total'],
                        'draft'  => (int)$r['draft'],
                        'signed' => (int)$r['signed'],
                    ];
                }

                // 4A) Émargements signés réels
                $rowsEmSigned = $conn->executeQuery(
                    "SELECT e.session_id AS sid,
                        SUM(CASE
                              WHEN e.signed_at IS NOT NULL THEN 1
                              WHEN e.signature_data_url IS NOT NULL AND e.signature_data_url <> '' THEN 1
                              WHEN e.signature_path IS NOT NULL AND e.signature_path <> '' THEN 1
                              ELSE 0
                            END) AS signed
                 FROM emargement e
                 WHERE e.session_id IN (?)
                 GROUP BY e.session_id",
                    [$ids],
                    [\Doctrine\DBAL\ArrayParameterType::INTEGER]
                )->fetchAllAssociative();

                foreach ($rowsEmSigned as $r) {
                    $stats['emarg'][(int)$r['sid']] = ['signed' => (int)$r['signed']];
                }

                // 4B) Émargements attendus = (nb jours * 2) * (stagiaires + formateur)
                $rowsExpected = $conn->executeQuery(
                    "SELECT s.id AS sid,
                        (COUNT(DISTINCT sj.id) * 2) AS slots,
                        COUNT(DISTINCT i.stagiaire_id) AS stagiaires,
                        CASE WHEN s.formateur_id IS NULL THEN 0 ELSE 1 END AS formateur_count
                 FROM session s
                 LEFT JOIN session_jour sj ON sj.session_id = s.id
                 LEFT JOIN inscription i   ON i.session_id = s.id
                 WHERE s.id IN (?)
                 GROUP BY s.id, s.formateur_id",
                    [$ids],
                    [\Doctrine\DBAL\ArrayParameterType::INTEGER]
                )->fetchAllAssociative();

                foreach ($rowsExpected as $r) {
                    $sid = (int)$r['sid'];
                    $slots = (int)$r['slots'];
                    $people = (int)$r['stagiaires'] + (int)$r['formateur_count'];
                    $stats['emarg_expected'][$sid] = max(0, $slots * $people);
                }

                // 5) Factures (via inscription)
                $rowsFact = $conn->executeQuery(
                    "SELECT i.session_id AS sid,
                            COUNT(DISTINCT f.id) AS total,
                            SUM(CASE WHEN f.status = 'DUE' THEN 1 ELSE 0 END) AS due
                    FROM inscription i
                    INNER JOIN facture_inscription fi ON fi.inscription_id = i.id
                    INNER JOIN facture f ON f.id = fi.facture_id
                    WHERE i.session_id IN (?)
                    GROUP BY i.session_id",
                    [$ids],
                    [\Doctrine\DBAL\ArrayParameterType::INTEGER]
                )->fetchAllAssociative();



                foreach ($rowsFact as $r) {
                    $stats['fact'][(int)$r['sid']] = [
                        'total' => (int)$r['total'],
                        'due'   => (int)$r['due'],
                    ];
                }

                // 6) Conventions attendues/presentes/signees
                $rowsConv = $conn->executeQuery(
                    "SELECT i.session_id AS sid,
                        COUNT(DISTINCT i.entreprise_id) AS expected,
                        SUM(CASE WHEN cc.id IS NOT NULL THEN 1 ELSE 0 END) AS present,
                        SUM(CASE
                            WHEN cc.id IS NULL THEN 0
                            WHEN cc.date_signature_stagiaire IS NOT NULL THEN 1
                            WHEN cc.date_signature_entreprise IS NOT NULL THEN 1
                            WHEN cc.date_signature_of IS NOT NULL THEN 1
                            ELSE 0
                        END) AS signed_any
                 FROM inscription i
                 LEFT JOIN convention_contrat cc
                        ON cc.session_id = i.session_id
                       AND cc.entreprise_id = i.entreprise_id
                       AND cc.entite_id = ?
                 WHERE i.session_id IN (?)
                   AND i.entreprise_id IS NOT NULL
                 GROUP BY i.session_id",
                    [$entite->getId(), $ids],
                    [\PDO::PARAM_INT, \Doctrine\DBAL\ArrayParameterType::INTEGER]
                )->fetchAllAssociative();

                foreach ($rowsConv as $r) {
                    $stats['conv'][(int)$r['sid']] = [
                        'expected' => (int)$r['expected'],
                        'present'  => (int)$r['present'],
                        'signed'   => (int)$r['signed_any'],
                    ];
                }



                $rowsPieces = $conn->executeQuery(
                    "SELECT sp.session_id AS sid,
                            sp.type       AS type,
                            COUNT(*)      AS total,
                            SUM(CASE WHEN sp.valide = 1 THEN 1 ELSE 0 END) AS validated,
                            MAX(sp.uploaded_at) AS last_upload
                    FROM session_piece sp
                    WHERE sp.session_id IN (?)
                    AND sp.entite_id = ?
                    GROUP BY sp.session_id, sp.type",
                    [$ids, $entite->getId()],
                    [\Doctrine\DBAL\ArrayParameterType::INTEGER, \PDO::PARAM_INT]
                )->fetchAllAssociative();

                foreach ($rowsPieces as $r) {
                    $sid  = (int)$r['sid'];
                    $type = (string)$r['type'];

                    $stats['pieces'][$sid][$type] = [
                        'total'      => (int)$r['total'],
                        'validated'  => (int)$r['validated'],
                        'last_upload' => $r['last_upload'] ? (string)$r['last_upload'] : null,
                    ];
                }



                // ✅ Participants (6 premiers + total) via window functions (MySQL 8)
                $rowsPart = $conn->executeQuery(
                    "SELECT sid, prenom, nom, total
                    FROM (
                        SELECT 
                        i.session_id AS sid,
                        us.prenom    AS prenom,
                        us.nom       AS nom,
                        ROW_NUMBER() OVER (PARTITION BY i.session_id ORDER BY us.nom ASC, us.prenom ASC, us.id ASC) AS rn,
                        COUNT(*)     OVER (PARTITION BY i.session_id) AS total
                        FROM inscription i
                        INNER JOIN utilisateur us ON us.id = i.stagiaire_id
                        WHERE i.session_id IN (?)
                    ) x
                    WHERE x.rn <= 6
                    ORDER BY sid ASC, rn ASC",
                    [$ids],
                    [\Doctrine\DBAL\ArrayParameterType::INTEGER]
                )->fetchAllAssociative();

                foreach ($rowsPart as $r) {
                    $sid = (int)$r['sid'];
                    if (!isset($stats['participants'][$sid])) {
                        $stats['participants'][$sid] = [
                            'total' => (int)$r['total'],
                            'list'  => [],
                        ];
                    }
                    $stats['participants'][$sid]['total'] = (int)$r['total'];
                    $stats['participants'][$sid]['list'][] = [
                        'prenom' => (string)($r['prenom'] ?? ''),
                        'nom'    => (string)($r['nom'] ?? ''),
                    ];
                }






                // 7) Satisfaction : expected / started / completed + moyennes KPI + NPS
                $rowsSat = $conn->executeQuery(
                    "SELECT sa.session_id AS sid,
                        COUNT(sa.id) AS expected,

                        SUM(CASE
                            WHEN sat.id IS NULL THEN 0
                            WHEN sat.started_at IS NOT NULL THEN 1
                            ELSE 0
                        END) AS started,

                        SUM(CASE
                            WHEN sat.id IS NULL THEN 0
                            WHEN sat.submitted_at IS NOT NULL THEN 1
                            ELSE 0
                        END) AS completed,

                        /* Moyennes (uniquement si soumis) */
                        AVG(CASE WHEN sat.submitted_at IS NOT NULL THEN sat.note_globale END)     AS avg_overall,
                        AVG(CASE WHEN sat.submitted_at IS NOT NULL THEN sat.note_formateur END)  AS avg_trainer,
                        AVG(CASE WHEN sat.submitted_at IS NOT NULL THEN sat.note_organisme END)  AS avg_organisme,
                        AVG(CASE WHEN sat.submitted_at IS NOT NULL THEN sat.note_contenu END)    AS avg_content,
                        AVG(CASE WHEN sat.submitted_at IS NOT NULL THEN sat.note_site END)       AS avg_site,
                        AVG(CASE WHEN sat.submitted_at IS NOT NULL THEN sat.recommendation_score END) AS avg_reco,

                        /* NPS buckets (uniquement si soumis + reco renseigné) */
                        SUM(CASE
                            WHEN sat.submitted_at IS NOT NULL AND sat.recommendation_score IS NOT NULL AND sat.recommendation_score >= 9 THEN 1
                            ELSE 0
                        END) AS nps_promoters,

                        SUM(CASE
                            WHEN sat.submitted_at IS NOT NULL AND sat.recommendation_score IS NOT NULL AND sat.recommendation_score BETWEEN 7 AND 8 THEN 1
                            ELSE 0
                        END) AS nps_passives,

                        SUM(CASE
                            WHEN sat.submitted_at IS NOT NULL AND sat.recommendation_score IS NOT NULL AND sat.recommendation_score <= 6 THEN 1
                            ELSE 0
                        END) AS nps_detractors

                FROM satisfaction_assignment sa
                LEFT JOIN satisfaction_attempt sat ON sat.assignment_id = sa.id
                WHERE sa.session_id IN (?)
                GROUP BY sa.session_id",
                    [$ids],
                    [\Doctrine\DBAL\ArrayParameterType::INTEGER]
                )->fetchAllAssociative();

                foreach ($rowsSat as $r) {
                    $sid = (int)$r['sid'];

                    $expected  = (int)$r['expected'];
                    $started   = (int)$r['started'];
                    $completed = (int)$r['completed'];

                    $prom = (int)$r['nps_promoters'];
                    $pas = (int)$r['nps_passives'];
                    $det = (int)$r['nps_detractors'];
                    $npsCount = $prom + $pas + $det;

                    // NPS score = %promoters - %detractors (sur les réponses avec reco renseignée)
                    $npsScore = null;
                    if ($npsCount > 0) {
                        $npsScore = (int) round((($prom / $npsCount) * 100) - (($det / $npsCount) * 100));
                    }

                    $stats['satisfaction'][$sid] = [
                        'expected'  => $expected,
                        'started'   => $started,
                        'completed' => $completed,

                        'avg_overall'   => $r['avg_overall']   !== null ? (float)$r['avg_overall']   : null,
                        'avg_trainer'   => $r['avg_trainer']   !== null ? (float)$r['avg_trainer']   : null,
                        'avg_organisme' => $r['avg_organisme'] !== null ? (float)$r['avg_organisme'] : null,
                        'avg_content'   => $r['avg_content']   !== null ? (float)$r['avg_content']   : null,
                        'avg_site'      => $r['avg_site']      !== null ? (float)$r['avg_site']      : null,
                        'avg_reco'      => $r['avg_reco']      !== null ? (float)$r['avg_reco']      : null,

                        'nps' => [
                            'promoters' => $prom,
                            'passives'  => $pas,
                            'detractors' => $det,
                            'count'     => $npsCount,
                            'score'     => $npsScore,
                        ],
                    ];
                }
            }

            // =========================
            // DATA (rows) - SUIVI = TOUS LES DOCUMENTS DANS 1 SEULE CELLULE
            // =========================
            $data = array_map(function (Session $s) use ($entite, $stats) {

                $sid = (int)$s->getId();



                $hasPiece = static function (array $stats, int $sid, SessionPieceType $type): bool {
                    return (($stats['pieces'][$sid][$type->value]['total'] ?? 0) > 0);
                };

                $hasValidPiece = static function (array $stats, int $sid, SessionPieceType $type): bool {
                    return (($stats['pieces'][$sid][$type->value]['validated'] ?? 0) > 0);
                };

                $getLastPieceUpload = static function (array $stats, int $sid, SessionPieceType $type): ?string {
                    return $stats['pieces'][$sid][$type->value]['last_upload'] ?? null;
                };

                // ===== Uploads “papier/scan” =====
                $convUploaded = $hasPiece($stats, $sid, SessionPieceType::CONVENTION_SIGNEE);
                $convValid    = $hasValidPiece($stats, $sid, SessionPieceType::CONVENTION_SIGNEE);
                $convLast     = $getLastPieceUpload($stats, $sid, SessionPieceType::CONVENTION_SIGNEE);

                $factUploaded = $hasPiece($stats, $sid, SessionPieceType::FACTURE);
                $factValid    = $hasValidPiece($stats, $sid, SessionPieceType::FACTURE);
                $factLast     = $getLastPieceUpload($stats, $sid, SessionPieceType::FACTURE);

                $cfUploaded   = $hasPiece($stats, $sid, SessionPieceType::CONTRAT_FORMATEUR_SIGNE);
                $cfValid      = $hasValidPiece($stats, $sid, SessionPieceType::CONTRAT_FORMATEUR_SIGNE);
                $cfLast       = $getLastPieceUpload($stats, $sid, SessionPieceType::CONTRAT_FORMATEUR_SIGNE);



                $insTotal = $stats['ins'][$sid]['total'] ?? 0;
                $cap = (int)($s->getCapacite() ?? 0);
                $capLabel = $cap > 0 ? "{$insTotal}/{$cap}" : (string)$insTotal;

                $fillPct = ($cap > 0) ? min(100, (int) round(($insTotal / $cap) * 100)) : null;
                $placesRestantes = ($cap > 0) ? max(0, $cap - $insTotal) : null;

                $dosWith    = $stats['dossiers'][$sid]['with'] ?? 0;
                $dosMissing = $stats['dossiers'][$sid]['missing'] ?? 0;

                $ctTotal  = $stats['contrats'][$sid]['total'] ?? 0;
                $ctSigned = $stats['contrats'][$sid]['signed'] ?? 0;
                $ctDraft  = $stats['contrats'][$sid]['draft'] ?? 0;

                $emSigned   = $stats['emarg'][$sid]['signed'] ?? 0;
                $emExpected = $stats['emarg_expected'][$sid] ?? 0;

                $fTotal = $stats['fact'][$sid]['total'] ?? 0;
                $fDue   = $stats['fact'][$sid]['due'] ?? 0;

                $convExpected = $stats['conv'][$sid]['expected'] ?? 0;
                $convPresent  = $stats['conv'][$sid]['present']  ?? 0;
                $convSigned   = $stats['conv'][$sid]['signed']   ?? 0;

                $satExpected  = $stats['satisfaction'][$sid]['expected']  ?? 0;
                $satCompleted = $stats['satisfaction'][$sid]['completed'] ?? 0;
                $satAvg = $stats['satisfaction'][$sid]['avg_overall'] ?? null;



                $min = $s->getDateDebut();
                $max = $s->getDateFin();



                $now = new \DateTimeImmutable();

                // ===== Badge temporel (À venir / En cours / Terminé) =====
                $timeClass = 'bg-light text-muted';
                $timeIcon  = 'bi-question-circle';
                $timeLabel = '-';

                if ($min && $max) {
                    if ($min > $now) {
                        $timeClass = 'bg-info-subtle text-info';
                        $timeIcon  = 'bi-hourglass-split';
                        $timeLabel = 'À venir';
                    } elseif ($max < $now) {
                        $timeClass = 'bg-secondary-subtle text-secondary';
                        $timeIcon  = 'bi-check2-circle';
                        $timeLabel = 'Terminé';
                    } else {
                        $timeClass = 'bg-success-subtle text-success';
                        $timeIcon  = 'bi-play-circle';
                        $timeLabel = 'En cours';
                    }
                } elseif ($min && !$max) {
                    // si tu as juste une date de début
                    if ($min > $now) {
                        $timeClass = 'bg-info-subtle text-info';
                        $timeIcon  = 'bi-hourglass-split';
                        $timeLabel = 'À venir';
                    } else {
                        $timeClass = 'bg-success-subtle text-success';
                        $timeIcon  = 'bi-play-circle';
                        $timeLabel = 'En cours';
                    }
                } elseif (!$min && $max) {
                    // si tu as juste une date de fin
                    if ($max < $now) {
                        $timeClass = 'bg-secondary-subtle text-secondary';
                        $timeIcon  = 'bi-check2-circle';
                        $timeLabel = 'Terminé';
                    } else {
                        $timeClass = 'bg-success-subtle text-success';
                        $timeIcon  = 'bi-play-circle';
                        $timeLabel = 'En cours';
                    }
                } else {
                    // aucune date => planning manquant
                    $timeClass = 'bg-warning-subtle text-warning';
                    $timeIcon  = 'bi-calendar-x';
                    $timeLabel = 'À venir';
                }

                $timeBadgeHtml = '<span class="badge ' . $timeClass . '"><i class="bi ' . $timeIcon . ' me-1"></i>' . $timeLabel . '</span>';


                $u = $s->getFormateur()?->getUtilisateur();
                $formateur = $u ? trim(($u->getPrenom() ?? '') . ' ' . ($u->getNom() ?? '')) : '-';

                $tarifCents = $s->getTarifEffectifCents();
                $tarifHtml  = number_format(($tarifCents ?? 0) / 100, 2, ',', ' ') . '&nbsp;€';

                $nbJours = $s->getJours()->count();
                $pieces = method_exists($s, 'getPiecesObligatoires') ? ($s->getPiecesObligatoires() ?? []) : [];
                $hasPiecesRules = !empty($pieces);



                // --- CNI requise sur cette session ?
                $requiresCni = in_array('cni_recto', $pieces, true) || in_array('CNI_RECTO', $pieces, true); // selon ton stockage
                // si tu stockes PieceType::CNI->value = 'cni', garde juste la première

                // --- CNI déposée ? (session_piece)
                $idRecto = $hasPiece($stats, $sid, SessionPieceType::CARTE_ID_RECTO);
                $idVerso = $hasPiece($stats, $sid, SessionPieceType::CARTE_ID_VERSO);
                $cniUploaded = $idRecto && $idVerso;

                $idRectoValid = $hasValidPiece($stats, $sid, SessionPieceType::CARTE_ID_RECTO);
                $idVersoValid = $hasValidPiece($stats, $sid, SessionPieceType::CARTE_ID_VERSO);
                $cniValid = $idRectoValid && $idVersoValid;

                $idRectoLast = $getLastPieceUpload($stats, $sid, SessionPieceType::CARTE_ID_RECTO);
                $idVersoLast = $getLastPieceUpload($stats, $sid, SessionPieceType::CARTE_ID_VERSO);
                $cniLast = null;
                if ($idRectoLast) $cniLast = $idRectoLast;
                if ($idVersoLast && (!$cniLast || $idVersoLast > $cniLast)) $cniLast = $idVersoLast;



                // ===== Dates HTML =====
                $dateDebutLabel = $min?->format('d/m/Y') ?? '-';
                $dateFinLabel   = $max?->format('d/m/Y') ?? '-';

                $datesHtml  = '<div class="d-flex flex-column gap-1">';
                $datesHtml .= '<div><span class="badge bg-light text-dark"><i class="bi bi-calendar-event me-1"></i>'
                    . $dateDebutLabel . ' → ' . $dateFinLabel . '</span></div>';
                $datesHtml .= ($nbJours > 0)
                    ? '<div><span class="badge bg-success-subtle text-success">' . (int)$nbJours . ' jour(s)</span></div>'
                    : '<div><span class="badge bg-warning-subtle text-warning">Planning manquant</span></div>';
                $datesHtml .= '</div>';

                // ===== Capacité HTML =====
                $capCell = [];
                if ($cap > 0) {
                    $badgeClass = ($fillPct === 100)
                        ? 'bg-success-subtle text-success'
                        : ($fillPct >= 80
                            ? 'bg-warning-subtle text-warning'
                            : 'bg-success-subtle text-success');



                    $capCell[] = '<span class="badge ' . $badgeClass . '">Inscrits ' . $capLabel . '</span>';
                    $bar = '<div class="progress" style="height:7px; width:110px">
                          <div class="progress-bar" role="progressbar" style="width:' . (int)$fillPct . '%"></div>
                        </div>';
                    $capCell[] = '<span class="d-inline-flex align-items-center gap-2 justify-content-end">'
                        . $bar
                        . '<span class="small text-muted">' . (int)$fillPct . '%</span></span>';
                    $capCell[] = '<span class="badge bg-light text-dark">Places restantes : ' . (int)$placesRestantes . '</span>';
                } else {
                    $capCell[] = '<span class="badge bg-light text-dark">Inscrits : ' . (int)$insTotal . '</span>';
                }
                $capaciteHtml  = '<div class="d-flex flex-column gap-1">';
                $capaciteHtml .= implode('', array_map(fn($x) => '<div>' . $x . '</div>', $capCell));
                $capaciteHtml .= '</div>';

                // ===== Infos HTML =====
                $code = htmlspecialchars((string)($s->getCode() ?? '-'), ENT_QUOTES);
                if ($s->getOrganismeFormation() !== null)
                    $ofLabel = '<div><span class="badge bg-primary text-light"><i class="bi bi-building me-1"></i>' . htmlspecialchars((string)($s->getOrganismeFormation()?->getRaisonSociale() ?? '-'), ENT_QUOTES) . '</span></div>';
                else
                    $ofLabel = '';
                $siteLabel = htmlspecialchars((string)($s->getSite()?->getNom() ?? '-'), ENT_QUOTES);
                $formateurLabel = htmlspecialchars((string)($formateur ?? '-'), ENT_QUOTES);
                $formationTitre = $s->getFormation()
                    ? $s->getFormation()->getTitre()
                    : $s->getFormationIntituleLibre();
                $formationLibre = method_exists($s, 'getFormationIntituleLibre')
                    ? trim((string)($s->getFormationIntituleLibre() ?? ''))
                    : '';

                $formationAffichage = $formationTitre ?: ($formationLibre !== '' ? $formationLibre : '-');
                $formationLabel = htmlspecialchars($formationAffichage, ENT_QUOTES);

                $infosHtml  = '<div class="d-flex flex-column gap-1">';
                $infosHtml .= '<div>' . $timeBadgeHtml . '</div>';
                $infosHtml .= '<div><span class="badge bg-dark-subtle text-dark"><i class="bi bi-hash me-1"></i>' . $code . '</span></div>';
                $infosHtml .= '<div><span class="badge bg-light text-dark"><i class="bi bi-mortarboard me-1"></i>' . $formationLabel . '</span></div>';
                $infosHtml .= $ofLabel;
                $infosHtml .= '<div class="text-muted small"><i class="bi bi-geo-alt me-1"></i>' . $siteLabel . '</div>';
                $infosHtml .= '<div class="text-muted small"><i class="bi bi-person-badge me-1"></i>' . $formateurLabel . '</div>';
                $infosHtml .= '</div>';

                // =========================
                // AVANCEMENT / DOCUMENTS (tout dans 1 cellule)
                // =========================
                $badge = fn(string $class, string $html) => '<span class="badge ' . $class . '">' . $html . '</span>';

                $line = static fn(string $left, string $right = '') =>
                '<div class="d-flex justify-content-between align-items-center gap-2">'
                    .   '<div class="text-start"><span class="d-inline-flex align-items-center gap-1 text-nowrap">' . $left . '</span></div>'
                    .   '<div class="text-end">' . $right . '</div>'
                    . '</div>';


                // Pièces / Dossiers stagiaires
                if (!$hasPiecesRules) {
                    $piecesLine = $line(
                        '<i class="bi bi-paperclip me-1"></i> Pièces requises',
                        $badge('bg-secondary-subtle text-secondary', 'Aucune')
                    );
                } elseif ($insTotal <= 0) {
                    $piecesLine = $line(
                        '<i class="bi bi-paperclip me-1"></i> Dossiers stagiaires',
                        $badge('bg-light text-muted', '-')
                    );
                } else {
                    if ($dosMissing > 0) {
                        $piecesLine = $line(
                            '<i class="bi bi-paperclip me-1"></i> Dossiers stagiaires',
                            $badge('bg-warning-subtle text-warning', $dosWith . '/' . $insTotal . ' OK')
                                . ' ' . $badge('bg-danger-subtle text-danger', $dosMissing . ' manquant')
                        );
                    } else {
                        $piecesLine = $line(
                            '<i class="bi bi-paperclip me-1"></i> Dossiers stagiaires',
                            $badge('bg-success-subtle text-success', $dosWith . '/' . $insTotal . ' OK')
                        );
                    }
                }


                // Contrat formateur
                if (!$s->getFormateur()) {
                    $contratLine = $line(
                        '<i class="bi bi-person-badge me-1"></i> Contrat formateur',
                        $badge('bg-warning-subtle text-warning', 'Formateur manquant')
                    );
                } else {
                    if ($cfUploaded) {
                        $label = $cfValid ? 'Déposé (validé)' : 'Déposé';
                        $hint  = $cfLast ? ('<span class="ms-2 small text-muted">(' . (new \DateTimeImmutable($cfLast))->format('d/m/Y') . ')</span>') : '';
                        $contratLine = $line(
                            '<i class="bi bi-file-earmark-check me-1"></i> Contrat formateur',
                            $badge($cfValid ? 'bg-success-subtle text-success' : 'bg-info-subtle text-info', $label) . $hint
                        );
                    } else {
                        if ($ctTotal <= 0) {
                            $contratLine = $line(
                                '<i class="bi bi-file-earmark-text me-1"></i> Contrat formateur',
                                $badge('bg-danger-subtle text-danger', 'Absent')
                            );
                        } elseif ($ctSigned >= $ctTotal) {
                            $contratLine = $line(
                                '<i class="bi bi-file-earmark-check me-1"></i> Contrat formateur',
                                $badge('bg-success-subtle text-success', $ctSigned . '/' . $ctTotal . ' signé')
                            );
                        } else {
                            $contratLine = $line(
                                '<i class="bi bi-file-earmark-text me-1"></i> Contrat formateur',
                                $badge('bg-warning-subtle text-warning', $ctSigned . '/' . $ctTotal . ' signés')
                                    . ($ctDraft > 0 ? ' ' . $badge('bg-light text-muted', $ctDraft . ' brouillon') : '')
                            );
                        }
                    }
                }


                // Conventions

                if ($convUploaded) {
                    $label = $convValid ? 'Déposé (validé)' : 'Déposé';
                    $hint  = $convLast ? ('<span class="ms-2 small text-muted">(' . (new \DateTimeImmutable($convLast))->format('d/m/Y') . ')</span>') : '';

                    $conventionsLine = $line(
                        '<i class="bi bi-check2-circle me-1"></i> Conventions',
                        $badge($convValid ? 'bg-success-subtle text-success' : 'bg-info-subtle text-info', $label) . $hint
                    );
                } elseif ($convExpected <= 0) {
                    $conventionsLine = $line(
                        '<i class="bi bi-building me-1"></i> Conventions',
                        $badge('bg-light text-muted', '-')
                    );
                } else {
                    $presentOk = ($convPresent >= $convExpected);
                    $signedOk  = ($convSigned >= $convExpected);

                    if (!$presentOk) {
                        $conventionsLine = $line(
                            '<i class="bi bi-building me-1"></i> Conventions',
                            $badge('bg-danger-subtle text-danger', $convPresent . '/' . $convExpected . ' présentes')
                        );
                    } elseif (!$signedOk) {
                        $conventionsLine = $line(
                            '<i class="bi bi-pen me-1"></i> Conventions',
                            $badge('bg-warning-subtle text-warning', $convSigned . '/' . $convExpected . ' signées')
                        );
                    } else {
                        $conventionsLine = $line(
                            '<i class="bi bi-check2-circle me-1"></i> Conventions',
                            $badge('bg-success-subtle text-success', $convSigned . '/' . $convExpected . ' signées')
                        );
                    }
                }



                // Émargements
                $emargUploaded = $hasPiece($stats, $sid, SessionPieceType::EMARGEMENT_SIGNE);
                $emargValid    = $hasValidPiece($stats, $sid, SessionPieceType::EMARGEMENT_SIGNE);
                $emargLast     = $getLastPieceUpload($stats, $sid, SessionPieceType::EMARGEMENT_SIGNE);

                if ($emExpected <= 0) {
                    $emargLine = $line(
                        '<i class="bi bi-pencil-square me-1"></i> Émargements',
                        $badge('bg-light text-muted', '-')
                    );
                } else {
                    if ($emargUploaded) {
                        $label = $emargValid ? 'Déposé (validé)' : 'Déposé';
                        $hint  = $emargLast ? ('<span class="ms-2 small text-muted">(' . (new \DateTimeImmutable($emargLast))->format('d/m/Y') . ')</span>') : '';
                        $emargLine = $line(
                            '<i class="bi bi-check2-circle me-1"></i> Émargements',
                            $badge($emargValid ? 'bg-success-subtle text-success' : 'bg-info-subtle text-info', $label) . $hint
                        );
                    } else {
                        if ($emSigned >= $emExpected) {
                            $emargLine = $line(
                                '<i class="bi bi-check2-circle me-1"></i> Émargements',
                                $badge('bg-success-subtle text-success', $emSigned . '/' . $emExpected . ' signés')
                            );
                        } else {
                            $emargLine = $line(
                                '<i class="bi bi-exclamation-triangle me-1"></i> Émargements',
                                $badge('bg-warning-subtle text-warning', $emSigned . '/' . $emExpected . ' signés')
                            );
                        }
                    }
                }



                // Factures (priorité au scan déposé)
                if ($factUploaded) {
                    $label = $factValid ? 'Déposé (validé)' : 'Déposé';
                    $hint  = $factLast ? ('<span class="ms-2 small text-muted">(' . (new \DateTimeImmutable($factLast))->format('d/m/Y') . ')</span>') : '';

                    $factLine = $line(
                        '<i class="bi bi-receipt me-1"></i> Factures',
                        $badge($factValid ? 'bg-success-subtle text-success' : 'bg-info-subtle text-info', $label) . $hint
                    );
                } elseif ($fTotal > 0) {
                    if ($fDue > 1) {
                        $factLine = $line(
                            '<i class="bi bi-receipt-cutoff me-1"></i> Factures',
                            $badge('bg-warning-subtle text-warning', $fTotal . ' (due: ' . $fDue . ' stagiaires)')
                        );
                    } elseif ($fDue > 0) {
                        $factLine = $line(
                            '<i class="bi bi-receipt-cutoff me-1"></i> Factures',
                            $badge('bg-warning-subtle text-warning', $fTotal . ' (due: ' . $fDue . ' stagiaire)')
                        );
                    } else {
                        $factLine = $line(
                            '<i class="bi bi-receipt me-1"></i> Factures',
                            $badge('bg-success-subtle text-success', (string)$fTotal)
                        );
                    }
                } else {
                    $factLine = $line(
                        '<i class="bi bi-receipt me-1"></i> Factures',
                        $badge('bg-light text-muted', '-')
                    );
                }



                // Global OK / À compléter + tooltip
                $missing = [];
                if ($hasPiecesRules && $insTotal > 0 && $dosMissing > 0) $missing[] = "Dossiers: $dosWith/$insTotal";
                if ($s->getFormateur()) {
                    if (!$cfUploaded) { // ✅ si scan déposé, on ne met pas “manquant”
                        if ($ctTotal <= 0) $missing[] = "Contrat formateur: absent";
                        elseif ($ctSigned < $ctTotal) $missing[] = "Contrat(s): $ctSigned/$ctTotal";
                    }
                } else {
                    $missing[] = "Formateur: manquant";
                }

                if ($convExpected > 0 && !$convUploaded) { // ✅ scan neutralise le manque
                    if ($convPresent < $convExpected) $missing[] = "Conventions: $convPresent/$convExpected";
                    elseif ($convSigned < $convExpected) $missing[] = "Conventions signées: $convSigned/$convExpected";
                }

                $emargUploaded = $hasPiece($stats, $sid, SessionPieceType::EMARGEMENT_SIGNE);

                if ($emExpected > 0 && !$emargUploaded && $emSigned < $emExpected) {
                    $missing[] = "Émargements: $emSigned/$emExpected";
                }

                if ($fTotal <= 0 && !$factUploaded) {
                    $missing[] = "Facture: absente";
                }



                if ($fTotal > 0 && $fDue > 0) $missing[] = "Factures dues: $fDue";

                $isComplete = empty($missing);
                $tooltip = $isComplete ? '' : htmlspecialchars(implode(" • ", $missing), ENT_QUOTES);

                $headerBadge = $isComplete
                    ? '<span class="badge bg-success-subtle text-success"><i class="bi bi-check2-circle me-1"></i>OK</span>'
                    : '<span class="badge bg-warning-subtle text-warning" data-bs-toggle="tooltip" data-bs-placement="top" title="' . $tooltip . '"><i class="bi bi-exclamation-triangle me-1"></i>À compléter</span>';

                $suiviHtml =
                    '<div class="d-flex flex-column gap-2 text-start">'
                    . '<div class="d-flex justify-content-between align-items-center">'
                    . '<strong class="small text-muted text-uppercase" style="letter-spacing:.06em">Documents</strong>'
                    . $headerBadge
                    . '</div>'
                    . '<div class="d-flex flex-column gap-1">'
                    . $piecesLine
                    . $contratLine
                    . $conventionsLine
                    . $emargLine
                    . $factLine
                    . '</div>'
                    . '</div>';



                // ===== Satisfaction HTML (chips) =====
                // ===== Satisfaction HTML (détaillé) =====
                $satisfactionHtml = '<span class="text-muted">-</span>';

                $sat = $stats['satisfaction'][$sid] ?? null;

                $badge = static fn(string $class, string $html) => '<span class="badge ' . $class . '">' . $html . '</span>';

                $line = static fn(string $left, string $right = '') =>
                '<div class="d-flex justify-content-between align-items-center gap-2">'
                    . '<div class="text-start">' . $left . '</div>'
                    . '<div class="text-end">' . $right . '</div>'
                    . '</div>';

                $fmt1 = static function (?float $v): ?float {
                    return $v === null ? null : round((float)$v, 1);
                };

                $progress = static function (?float $v, int $max = 10): string {
                    if ($v === null) {
                        return '<div class="progress" style="height:7px;width:110px"><div class="progress-bar" style="width:0%"></div></div>';
                    }
                    $pct = (int) max(0, min(100, round(($v / max(1, $max)) * 100)));
                    return '<div class="progress" style="height:7px;width:110px"><div class="progress-bar" role="progressbar" style="width:' . $pct . '%"></div></div>';
                };

                $barWithNote = static function (?float $v): string {
                    if ($v === null) {
                        $percent = 0;
                        $label = '-';
                        $barClass = 'bg-secondary';
                    } else {
                        $percent = (int) max(0, min(100, round(($v / 10) * 100)));
                        $label = number_format($v, 1, ',', ' ');

                        if ($percent < 33) {
                            $barClass = 'bg-danger';     // 🔴
                        } elseif ($percent < 66) {
                            $barClass = 'bg-warning';    // 🟠
                        } else {
                            $barClass = 'bg-success';    // 🟢
                        }
                    }

                    return
                        '<span class="d-inline-flex align-items-center gap-2 justify-content-end w-100">'
                        . '<div class="progress" style="height:7px;width:140px;background:rgba(0,0,0,.08)">'
                        .   '<div class="progress-bar smooth-bar ' . $barClass . '" role="progressbar"'
                        .        ' style="width:' . $percent . '%"></div>'
                        . '</div>'
                        . '<span class="small text-muted text-nowrap"'
                        .       ' style="min-width:42px;text-align:right">'
                        .   $label . '/10'
                        . '</span>'
                        . '</span>';
                };



                $chipClass10 = static function (?float $v): string {
                    if ($v === null) return 'mid';
                    if ($v >= 8) return 'good';
                    if ($v >= 6) return 'mid';
                    return 'bad';
                };

                if ($sat && (int)($sat['expected'] ?? 0) > 0) {

                    $expected  = (int)$sat['expected'];
                    $started   = (int)($sat['started'] ?? 0);
                    $completed = (int)($sat['completed'] ?? 0);

                    $pctCompleted = (int) round(($completed / max(1, $expected)) * 100);
                    $pctStarted   = (int) round(($started   / max(1, $expected)) * 100);

                    $rateClass = $pctCompleted >= 80 ? 'good' : ($pctCompleted >= 50 ? 'mid' : 'bad');

                    $overall   = $fmt1($sat['avg_overall'] ?? null);
                    $trainer   = $fmt1($sat['avg_trainer'] ?? null);
                    $org       = $fmt1($sat['avg_organisme'] ?? null);
                    $content   = $fmt1($sat['avg_content'] ?? null);
                    $siteAvg   = $fmt1($sat['avg_site'] ?? null);
                    $reco      = $fmt1($sat['avg_reco'] ?? null);

                    $nps = $sat['nps'] ?? [];
                    $npsScore = $nps['score'] ?? null;
                    $npsCount = (int)($nps['count'] ?? 0);

                    // Entête “taux réponses”
                    $header =
                        '<div class="d-flex justify-content-between align-items-center">'
                        . '<strong class="small text-uppercase sat-title">Satisfaction</strong>'
                        . '<span class="note-chip ' . $rateClass . '">'
                        . '<i class="bi bi-clipboard-check"></i> ' . $completed . '/' . $expected . ' (' . $pctCompleted . '%)'
                        . '</span>'
                        . '</div>';

                    // Ligne “démarrés” utile quand ils ont ouvert mais pas soumis
                    $startedLine = $line(
                        '<i class="bi bi-play-circle me-1"></i> Démarrés',
                        $badge('bg-light text-dark', $started . '/' . $expected . ' (' . $pctStarted . '%)')
                    );

                    // Détails notes
                    $details =
                        '<div class="d-flex flex-column gap-1">'
                        . $startedLine
                        . $line('<i class="bi bi-star-fill me-1"></i> Globale',     $barWithNote($overall))
                        . $line('<i class="bi bi-person-badge me-1"></i> Formateur', $barWithNote($trainer))
                        . $line('<i class="bi bi-building me-1"></i> Organisme',    $barWithNote($org))
                        . $line('<i class="bi bi-mortarboard me-1"></i> Contenu',   $barWithNote($content))
                        . $line('<i class="bi bi-geo-alt me-1"></i> Site',          $barWithNote($siteAvg))
                        . $line('<i class="bi bi-graph-up-arrow me-1"></i> Recommandation',   $barWithNote($reco))
                        . '</div>';

                    // Petit bloc NPS
                    $npsHtml = '';
                    if ($npsCount > 0 && $npsScore !== null) {
                        $npsClass = ($npsScore >= 30) ? 'good' : (($npsScore >= 0) ? 'mid' : 'bad');
                        $npsHtml =
                            '<div class="d-flex justify-content-between align-items-center mt-1">'
                            . '<div class="small sat-muted"><i class="bi bi-graph-up me-1"></i>NPS</div>'
                            . '<span class="note-chip ' . $npsClass . '">'
                            . 'Score <b>' . (int)$npsScore . '</b>'
                            . '<span class="muted"> (' . (int)($nps['promoters'] ?? 0) . '/' . (int)($nps['passives'] ?? 0) . '/' . (int)($nps['detractors'] ?? 0) . ')</span>'
                            . '</span>'
                            . '</div>';
                    } else {
                        $npsHtml =
                            '<div class="text-muted small mt-1"><i class="bi bi-graph-up me-1"></i>NPS : -</div>';
                    }

                    // Badge global note (optionnel, mais joli en tête)
                    $globalBadge = $overall === null
                        ? '<span class="badge" style="background:var(--theme-color-tertiaire);color:var(--theme-color-principal);border:1px solid rgba(0,0,0,.08)">-</span>'
                        : '<span class="badge" style="background:var(--theme-color-secondaire);color:var(--theme-color-principal);border:1px solid rgba(0,0,0,.10)">'
                        . '<i class="bi bi-star-fill me-1"></i> Note <b>' . htmlspecialchars((string)$overall, ENT_QUOTES) . '</b><span style="opacity:.75">/10</span>'
                        . '</span>';




                    $satisfactionHtml =
                        '<div class="sat-card d-flex flex-column gap-2 text-start">'
                        . $header
                        . '<div class="sat-divider"></div>'
                        . '<div class="d-flex justify-content-between align-items-center">'
                        .   '<div class="small sat-muted">Détails</div>'
                        .   $globalBadge
                        . '</div>'
                        . $details
                        . $npsHtml
                        . '</div>';
                }





                // =========================
                // ✅ Participants HTML (centré + 1 par ligne)
                // =========================
                $parts = $stats['participants'][$sid] ?? ['total' => 0, 'list' => []];
                $partsTotal = (int)($parts['total'] ?? 0);
                $partsList  = (array)($parts['list'] ?? []);

                if ($partsTotal <= 0) {
                    $participantsHtml = '<span class="text-muted">-</span>';
                } else {
                    $names = [];
                    foreach ($partsList as $p) {
                        $full = trim(((string)($p['prenom'] ?? '')) . ' ' . ((string)($p['nom'] ?? '')));
                        if ($full !== '') $names[] = htmlspecialchars($full, ENT_QUOTES);
                    }

                    $extra = max(0, $partsTotal - count($names));

                    $participantsHtml  = '<div class="participants-cell">';
                    $participantsHtml .= '<div class="participants-list">';

                    foreach ($names as $label) {
                        $participantsHtml .= '<span class="badge participant-badge">' . $label . '</span>';
                    }

                    if ($extra > 0) {
                        $participantsHtml .= '<span class="badge participant-more">+' . $extra . '</span>';
                    }

                    $participantsHtml .= '</div>';

                    $participantsHtml .= '<div class="participants-total">'
                        . '<i class="bi bi-people me-1"></i>'
                        . $partsTotal . ' ' . ($partsTotal > 1 ? 'participants' : 'participant')
                        . '</div>';

                    $participantsHtml .= '</div>';
                }







                return [
                    'infos'     => $infosHtml,
                    'dates'     => $datesHtml,
                    'dateStartSort' => $min?->format('Y-m-d H:i:s') ?? '0000-00-00 00:00:00',
                    'dateEndSort'   => $max?->format('Y-m-d') ?? '0000-00-00',
                    'capacite'  => $capaciteHtml,
                    'participants' => $participantsHtml,
                    'status'    => $this->renderStatusBadge($s->getStatus()),
                    'tarif'     => $tarifHtml,
                    'satisfaction' => $satisfactionHtml,
                    'dossier'   => $suiviHtml, // ✅ tout dans "suivi"
                    'actions'   => $this->renderView('administrateur/session/_actions.html.twig', [
                        'session' => $s,
                        'entite'  => $entite,
                    ]),
                ];
            }, $rows);

            return new JsonResponse([
                'draw'            => $draw,
                'recordsTotal'    => $recordsTotal,
                'recordsFiltered' => $recordsFiltered,
                'data'            => $data,
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'draw' => (int)$request->request->get('draw', 1),
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => [],
                'error' => $e->getMessage(),
            ], 500);
        }
    }



    private function renderDossierSuiviCell(string $dossierHtml, array $missingBadges, bool $isComplete): string
    {
        // Si complet : juste "Dossier complet" + petit OK
        if ($isComplete) {
            return '
          <div class="d-flex flex-column gap-1">
            <div>' . $dossierHtml . '</div>
            <div><span class="badge bg-success-subtle text-success"><i class="bi bi-check2-circle me-1"></i>Aucun manque</span></div>
          </div>
        ';
        }

        // Sinon : dossier "à compléter" + détails manquants
        $details = '';
        if (!empty($missingBadges)) {
            $details = implode('', array_map(fn($b) => '<div>' . $b . '</div>', $missingBadges));
        }

        return '
      <div class="d-flex flex-column gap-1">
        <div>' . $dossierHtml . '</div>
        <div class="d-flex flex-column gap-1">' . $details . '</div>
      </div>
    ';
    }





    private function renderStatusBadge(?StatusSession $st): string
    {
        if (!$st) {
            return '<span class="badge text-bg-secondary">-</span>';
        }

        $class = match ($st) {
            StatusSession::DRAFT     => 'text-bg-secondary',
            StatusSession::PUBLISHED => 'text-bg-success',
            StatusSession::FULL      => 'text-bg-warning',
            StatusSession::CANCELED  => 'text-bg-dark',
            StatusSession::DONE      => 'text-bg-primary',
        };

        $label = method_exists($st, 'label') ? $st->label() : $st->value;
        $label = htmlspecialchars((string)$label, ENT_QUOTES);

        return sprintf('<span class="badge %s">%s</span>', $class, $label);
    }



    private function renderInscriptionStatusBadge(?StatusInscription $st): string
    {
        if (!$st) {
            return '<span class="badge text-bg-secondary">-</span>';
        }

        $class = match ($st) {
            StatusInscription::PREINSCRIT => 'text-bg-secondary',
            StatusInscription::CONFIRME   => 'text-bg-primary',
            StatusInscription::EN_COURS   => 'text-bg-success',
            StatusInscription::TERMINE    => 'text-bg-success',
            StatusInscription::ANNULE     => 'text-bg-dark',
            StatusInscription::ABSENT     => 'text-bg-warning',
        };


        $label = $st->label(); // ✅ tu l’as dans ton enum
        $label = htmlspecialchars($label, ENT_QUOTES);

        return sprintf('<span class="badge %s">%s</span>', $class, $label);
    }





    #[Route('/kpis', name: 'app_administrateur_session_kpis', methods: ['GET'])]
    public function kpis(Entite $entite, Request $request, EntityManagerInterface $em): JsonResponse
    {


        $status = (string)$request->query->get('status', 'all');
        $formation = (string)$request->query->get('formation', 'all');

        $qb = $em->getRepository(Session::class)->createQueryBuilder('s')
            ->leftJoin('s.formation', 'f')
            ->leftJoin('s.jours', 'j')
            ->andWhere('s.entite = :entite')
            ->setParameter('entite', $entite);

        if ($status !== 'all') {
            $st = StatusSession::tryFrom($status);
            if ($st) {
                $qb->andWhere('s.status = :st')->setParameter('st', $st);
            }
        }

        if ($formation !== 'all') {
            $fid = (int)$formation;
            if ($fid > 0) {
                $qb->andWhere('f.id = :fid')->setParameter('fid', $fid);
            }
        }

        // On calcule min(dateDebut) et max(dateFin) via les jours
        // (MySQL ok : on utilise MIN/MAX)
        $now = new \DateTimeImmutable();

        $count = (int)(clone $qb)->select('COUNT(DISTINCT s.id)')->getQuery()->getSingleScalarResult();

        $upcomingRows = (clone $qb)
            ->select('COUNT(DISTINCT s.id) AS c')
            ->groupBy('s.id')
            ->having('MIN(j.dateDebut) > :now')
            ->setParameter('now', $now)
            ->getQuery()
            ->getScalarResult();

        $runningRows = (clone $qb)
            ->select('COUNT(DISTINCT s.id) AS c')
            ->groupBy('s.id')
            ->having('MIN(j.dateDebut) <= :now AND MAX(j.dateFin) >= :now')
            ->setParameter('now', $now)
            ->getQuery()
            ->getScalarResult();

        $upcoming = count($upcomingRows);
        $running  = count($runningRows);


        $done = (int)(clone $qb)
            ->select('COUNT(DISTINCT s.id)')
            ->andWhere('s.status = :done')
            ->setParameter('done', StatusSession::DONE)
            ->getQuery()->getSingleScalarResult();

        return new JsonResponse([
            'count'    => $count,
            'upcoming' => $upcoming,
            'running'  => $running,
            'done'     => $done,
        ]);
    }


    #[Route('/kpis/meta', name: 'app_administrateur_session_kpis_meta', methods: ['GET'])]
    public function kpisMeta(Entite $entite, EntityManagerInterface $em): JsonResponse
    {


        // formations utilisées par les sessions de l’entité
        $rows = $em->getRepository(Session::class)->createQueryBuilder('s')
            ->select('DISTINCT f.id AS id, f.titre AS titre')
            ->leftJoin('s.formation', 'f')
            ->andWhere('s.entite = :entite')
            ->setParameter('entite', $entite)
            ->orderBy('f.titre', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $formations = array_map(fn($r) => [
            'id' => (int)$r['id'],
            'label' => (string)$r['titre'],
        ], $rows);

        return new JsonResponse([
            'formations' => $formations,
        ]);
    }






    #[Route('/ajouter', name: 'app_administrateur_session_ajouter', methods: ['GET', 'POST'])]
    #[Route('/modifier/{id}', name: 'app_administrateur_session_modifier', methods: ['GET', 'POST'])]
    public function addEdit(
        Entite $entite,
        Request $request,
        EntityManagerInterface $em,
        SessionNumberGenerator $sessionGen,
        ?Session $session = null
    ): Response {


        /** @var Utilisateur $user */
        $user = $this->getUser();
        $isEdit = (bool) $session;

        if (!$session) {
            $session = new Session();
            $session->setCreateur($user);
            $session->setEntite($entite);

            // ✅ Génération serveur dès la création (avant validation)
            if (!$session->getCode() || !trim($session->getCode())) {
                $year = (int) (new \DateTimeImmutable())->format('Y');
                $session->setCode($sessionGen->nextForEntite($entite->getId(), $year));
            }
        } else {
            if ($session->getEntite()?->getId() !== $entite->getId()) {
                throw $this->createNotFoundException();
            }
        }

        // ✅ on mémorise l'ancien statut
        $oldStatus = $session->getId() ? $session->getStatus() : null;




        $form = $this->createForm(SessionType::class, $session, [
            'is_edit' => $isEdit,
            'entite'  => $entite,
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {



            // ✅ Normalise les journées
            foreach ($session->getJours() as $jour) {
                if ($jour->getSession() !== $session) {
                    $jour->setSession($session);
                }
                if ($jour->getEntite() === null) {
                    $jour->setEntite($entite);
                }
                if ($jour->getCreateur() === null) {
                    $jour->setCreateur($user);
                }
                if ($jour->getDateCreation() === null) {
                    $jour->setDateCreation(new \DateTimeImmutable());
                }
            }


            // ✅ (Optionnel) Normalise les inscriptions uniquement si ton entity le nécessite
            foreach ($session->getInscriptions() as $inscription) {
                if ($inscription->getSession() !== $session) {
                    $inscription->setSession($session);
                }

                // si ces champs existent chez toi
                if (method_exists($inscription, 'getEntite') && $inscription->getEntite() === null) {
                    $inscription->setEntite($entite);
                }
                if (method_exists($inscription, 'getCreateur') && $inscription->getCreateur() === null) {
                    $inscription->setCreateur($user);
                }
                if (method_exists($inscription, 'getDateCreation') && $inscription->getDateCreation() === null) {
                    $inscription->setDateCreation(new \DateTimeImmutable());
                }

                // ✅ juste vérifier qu'il y a un stagiaire
                if (method_exists($inscription, 'getStagiaire') && !$inscription->getStagiaire()) {
                    throw new \RuntimeException('Inscription sans stagiaire.');
                }
            }


            if ($session->getTypeFinancement() !== TypeFinancement::OF) {
                $this->syncEmargementsWithInscriptions($session, $em);
            }


            if ($session->getTypeFinancement() !== TypeFinancement::OF) {
                $session->setOrganismeFormation(null);
                $session->setFormationIntituleLibre(null);
            }



            $em->persist($session);
            $em->flush(); // ✅ IMPORTANT : session doit avoir un id

            // ✅ si on vient de passer à FULL => créer les assignments
            // ✅ si on vient de passer à FULL => créer les assignments
            if ($session->getTypeFinancement() !== TypeFinancement::OF) {
                if ($oldStatus !== StatusSession::FULL && $session->getStatus() === StatusSession::FULL) {

                    $createdStagiaires = $this->satisfactionAssigner->assignForSession($session, $user, $entite);
                    $createdFormateurs = $this->formateurSatisfactionAssigner->assignForSession($session, $user, $entite);

                    $em->flush();

                    $this->addFlash('success', sprintf(
                        'Session passée à "Complète" : %d affectation(s) stagiaire + %d affectation(s) formateur créées.',
                        $createdStagiaires,
                        $createdFormateurs
                    ));
                }
            }

            $this->addFlash('success', $isEdit ? 'Session modifiée.' : 'Session créée.');
            return $this->redirectToRoute('app_administrateur_session_index', [
                'entite' => $entite->getId(),
            ]);
        }

        return $this->render('administrateur/session/form.html.twig', [
            'form'        => $form->createView(),
            'modeEdition' => $isEdit,
            'session'     => $session,
            'entite'      => $entite,
            'googleMapsBrowserKey' => $this->getParameter('GOOGLE_MAPS_BROWSER_KEY'),
        ]);
    }

    #[Route('/dupliquer/{id}', name: 'app_administrateur_session_duplicate', methods: ['GET'])]
    public function duplicate(Entite $entite, EntityManagerInterface $em, Session $session): RedirectResponse
    {


        if ($session->getEntite()?->getId() !== $entite->getId()) {
            throw $this->createNotFoundException();
        }
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $copy = new Session();
        $copy->setCreateur($user);
        $copy->setEntite($entite);
        $copy->setFormation($session->getFormation());
        $copy->setSite($session->getSite());
        $copy->setEngin($session->getEngin());
        $copy->setFormateur($session->getFormateur());
        $copy->setCode($session->getCode() . '-CP' . substr(md5(uniqid()), 0, 4));
        foreach ($session->getJours() as $jour) {
            $j = new SessionJour();
            $j->setCreateur($user);
            $j->setEntite($entite);
            $j->setSession($copy)
                ->setDateDebut($jour->getDateDebut())
                ->setDateFin($jour->getDateFin());
            $em->persist($j);
        }
        $copy->setCapacite($session->getCapacite());
        $copy->setMontantCents($session->getMontantCents());
        $copy->setStatus($session->getStatus());

        $em->persist($copy);
        $em->flush();

        $this->addFlash('success', 'Session dupliquée (#' . $copy->getId() . ').');
        return $this->redirectToRoute('app_administrateur_session_index', [
            'entite' => $entite->getId(),
        ]);
    }

    #[Route('/supprimer/{id}', name: 'app_administrateur_session_supprimer', methods: ['POST'])]
    public function delete(Entite $entite, Request $request, EntityManagerInterface $em, Session $session): RedirectResponse
    {


        if ($session->getEntite()?->getId() !== $entite->getId()) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('delete_session_' . $session->getId(), (string)$request->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF invalide.');
        }

        $id = $session->getId();
        $em->remove($session);
        $em->flush();

        $this->addFlash('success', 'Session #' . $id . ' supprimée.');
        return $this->redirectToRoute('app_administrateur_session_index', [
            'entite' => $entite->getId()
        ]);
    }


    #[Route('/{id}', name: 'app_administrateur_session_show', methods: ['GET'])]
    public function show(
        Entite $entite,
        Session $session,
        EntityManagerInterface $em,
        HttpClientInterface $http
    ): Response {
        /** @var Utilisateur $user */
        $user = $this->getUser();


        if ($session->getEntite()?->getId() !== $entite->getId()) {
            throw $this->createNotFoundException();
        }

        // --- Formateurs impliqués ---
        $formateurs = [];

        if ($session->getFormateur()) {
            $f = $session->getFormateur();
            if ($f->getId()) $formateurs[$f->getId()] = $f;
        }
        foreach ($session->getJours() as $jour) {
            if (method_exists($jour, 'getFormateur')) {
                $jf = $jour->getFormateur();
                if ($jf && $jf->getId()) $formateurs[$jf->getId()] = $jf;
            }
        }
        $formateursSession = array_values($formateurs);

        // --- Contrats ---
        $repoContrat = $em->getRepository(ContratFormateur::class);
        $contrats = $repoContrat->createQueryBuilder('c')
            ->where('c.session = :session')->setParameter('session', $session)
            ->getQuery()->getResult();

        $contratsByFormateur = [];
        foreach ($contrats as $contrat) {
            if ($contrat->getFormateur() && $contrat->getFormateur()->getId()) {
                $contratsByFormateur[$contrat->getFormateur()->getId()] = $contrat;
            }
        }

        // =====================================================================
        // ✅ ITINÉRAIRES FORMATEURS -> SITE (distance / durée / frais / embed)
        // =====================================================================
        $serverKey  = (string) $this->getParameter('GOOGLE_MAPS_SERVER_KEY');  // Routes API (server)
        $browserKey = (string) $this->getParameter('GOOGLE_MAPS_BROWSER_KEY'); // Maps Embed (browser)

        // tarif km (mets ce que tu veux) : 0.40€ / km par exemple
        $kmRate = (float) ($this->getParameter('KM_RATE_EUR') ?? 0.40);

        // adresse destination = site session
        $destAddress = null;
        if ($session->getSite()) {
            $site = $session->getSite();
            $destParts = array_filter([
                $site->getAdresse(),
                trim(($site->getCodePostal() ?? '') . ' ' . ($site->getVille() ?? '')),
                $site->getPays(),
            ]);
            $destAddress = implode(', ', $destParts);
        }

        $travelByFormateurId = [];

        // helper: formater durée "4065s" -> "1 h 07 min"
        $formatDuration = static function (?string $dur): ?string {
            if (!$dur) return null;
            if (preg_match('/^(\d+)s$/', $dur, $m)) {
                $sec = (int) $m[1];
                $min = intdiv($sec, 60);
                $h = intdiv($min, 60);
                $m2 = $min % 60;
                if ($h > 0) return sprintf('%d h %02d min', $h, $m2);
                return sprintf('%d min', $m2);
            }
            return $dur;
        };

        $formatKm = static function (?int $meters): ?string {
            if (!$meters) return null;
            $km = $meters / 1000;
            return ($km < 10)
                ? number_format($km, 1, ',', ' ') . ' km'
                : number_format($km, 0, ',', ' ') . ' km';
        };

        $computeRoutes = function (string $origin, string $destination) use ($http, $serverKey, $formatDuration): array {
            // Si pas de clé serveur, on ne tente pas Routes API
            if (!$serverKey) {
                return ['ok' => false, 'error' => 'Clé serveur Routes API manquante'];
            }

            try {
                $resp = $http->request('POST', 'https://routes.googleapis.com/directions/v2:computeRoutes', [
                    'headers' => [
                        'Content-Type'       => 'application/json',
                        'X-Goog-Api-Key'     => $serverKey,
                        'X-Goog-FieldMask'   => 'routes.distanceMeters,routes.duration',
                    ],
                    'json' => [
                        'origin' => [
                            'address' => $origin,
                        ],
                        'destination' => [
                            'address' => $destination,
                        ],
                        'travelMode' => 'DRIVE',
                    ],
                ]);

                $data = $resp->toArray(false);
                $route = $data['routes'][0] ?? null;

                if (!$route) {
                    return ['ok' => false, 'error' => $data['error']['message'] ?? 'Aucune route trouvée'];
                }

                $meters = (int) ($route['distanceMeters'] ?? 0);
                $duration = $route['duration'] ?? null; // ex: "4065s"

                return [
                    'ok' => true,
                    'distanceMeters' => $meters,
                    'durationRaw' => $duration,
                    'durationText' => $formatDuration($duration),
                ];
            } catch (\Throwable $e) {
                return ['ok' => false, 'error' => $e->getMessage()];
            }
        };

        foreach ($formateursSession as $f) {
            $fid = $f->getId();
            $u = $f->getUtilisateur();

            // Construis l'adresse du formateur depuis Utilisateur (adapte les getters si besoin)
            $originParts = [];
            if ($u) {
                // ⚠️ adapte si tes champs s'appellent autrement
                if (method_exists($u, 'getAdresse') && $u->getAdresse()) $originParts[] = $u->getAdresse();
                $cpVille = trim(
                    (method_exists($u, 'getCodePostal') ? ($u->getCodePostal() ?? '') : '')
                        . ' ' .
                        (method_exists($u, 'getVille') ? ($u->getVille() ?? '') : '')
                );
                if ($cpVille !== '') $originParts[] = $cpVille;

                if (method_exists($u, 'getPays') && $u->getPays()) $originParts[] = $u->getPays();
            }

            $originAddress = implode(', ', array_filter($originParts));

            $travelByFormateurId[$fid] = [
                'originAddress' => $originAddress ?: null,
                'destAddress'   => $destAddress ?: null,
                'distanceText'  => null,
                'durationText'  => null,
                'costText'      => null,
                'gmapsEmbedUrl' => null,
                'gmapsError'    => null,
            ];

            if (!$originAddress || !$destAddress) {
                $travelByFormateurId[$fid]['gmapsError'] = !$originAddress
                    ? "Adresse formateur manquante"
                    : "Adresse site manquante";
                continue;
            }

            // 1) distance / durée via Routes API (server key)
            $r = $computeRoutes($originAddress, $destAddress);
            if ($r['ok'] ?? false) {
                $meters = (int) ($r['distanceMeters'] ?? 0);
                $km = $meters > 0 ? ($meters / 1000) : 0;

                $travelByFormateurId[$fid]['distanceText'] = $formatKm($meters);
                $travelByFormateurId[$fid]['durationText'] = $r['durationText'] ?? null;

                // frais : aller simple (si tu veux A/R => $km*2)
                $cost = $km * $kmRate;
                $travelByFormateurId[$fid]['costText'] = number_format($cost, 2, ',', ' ') . ' €';
            } else {
                $travelByFormateurId[$fid]['gmapsError'] = $r['error'] ?? 'Erreur Routes API';
            }

            // 2) url iframe directions via Maps Embed (browser key)
            if ($browserKey) {
                $travelByFormateurId[$fid]['gmapsEmbedUrl'] =
                    'https://www.google.com/maps/embed/v1/directions?key=' . rawurlencode($browserKey)
                    . '&origin=' . rawurlencode($originAddress)
                    . '&destination=' . rawurlencode($destAddress)
                    . '&mode=driving';
            }
        }



        // --- Conventions ---
        $conventionsByEntrepriseId = [];
        $conventionsByStagiaireId  = [];

        foreach ($session->getConventionContrats() as $cc) {
            if ($cc->getEntreprise()) $conventionsByEntrepriseId[$cc->getEntreprise()->getId()] = $cc;
            if ($cc->getStagiaire())  $conventionsByStagiaireId[$cc->getStagiaire()->getId()] = $cc;
        }

        // --- Inscriptions groupées par entreprise (pour bulk conventions) ---
        $inscriptions = $em->getRepository(Inscription::class)->createQueryBuilder('i')
            ->leftJoin('i.entreprise', 'e')->addSelect('e')
            ->leftJoin('i.stagiaire', 'u')->addSelect('u')
            ->andWhere('i.session = :s')
            ->setParameter('s', $session)
            ->andWhere('e.id IS NOT NULL') // uniquement les financements entreprise
            ->orderBy('e.raisonSociale', 'ASC')
            ->addOrderBy('u.nom', 'ASC')
            ->addOrderBy('u.prenom', 'ASC')
            ->getQuery()
            ->getResult();

        $byEntreprise = [];
        foreach ($inscriptions as $i) {
            $e = $i->getEntreprise();
            if (!$e) continue;

            $eid = $e->getId();
            if (!isset($byEntreprise[$eid])) {
                $byEntreprise[$eid] = [
                    'entreprise'   => $e,
                    'inscriptions' => [],
                ];
            }
            $byEntreprise[$eid]['inscriptions'][] = $i;
        }




        $assignments = $em->getRepository(QcmAssignment::class)->createQueryBuilder('a')
            ->leftJoin('a.qcm', 'q')->addSelect('q')
            ->leftJoin('a.inscription', 'i')->addSelect('i')
            ->leftJoin('i.stagiaire', 's')->addSelect('s')
            ->leftJoin('a.attempt', 'att')->addSelect('att') // si relation OneToOne existe côté QcmAssignment
            ->andWhere('a.session = :session')->setParameter('session', $session)
            ->orderBy('a.phase', 'ASC')
            ->addOrderBy('q.titre', 'ASC')
            ->getQuery()->getResult();

        $byPhase = [
            'pre'  => [],
            'post' => [],
        ];

        foreach ($assignments as $a) {
            $key = $a->getPhase()?->value ?? 'pre'; // adapte selon ton Enum (PRE/POST => pre/post)
            if (!isset($byPhase[$key])) $byPhase[$key] = [];
            $byPhase[$key][] = $a;
        }




        $inscriptionsAll = $em->getRepository(Inscription::class)->createQueryBuilder('i')
            ->leftJoin('i.stagiaire', 'u')->addSelect('u')
            ->leftJoin('i.dossier', 'd')->addSelect('d')
            ->leftJoin('d.pieces', 'p')->addSelect('p')
            ->andWhere('i.session = :s')->setParameter('s', $session)
            ->orderBy('u.nom', 'ASC')->addOrderBy('u.prenom', 'ASC')
            ->addOrderBy('p.uploadedAt', 'DESC')
            ->getQuery()->getResult();

        $pieceTypes = PieceType::cases();


        // use App\Entity\SupportAsset; // si besoin

        // ---------------------------
        // SUPPORTS ASSIGNÉS À LA SESSION
        // ---------------------------
        $supportAssignSessions = $em->getRepository(SupportAssignSession::class)->createQueryBuilder('sas')
            ->leftJoin('sas.asset', 'a')->addSelect('a')
            ->leftJoin('sas.createur', 'c')->addSelect('c')
            ->andWhere('sas.session = :session')->setParameter('session', $session)
            ->andWhere('sas.entite = :entite')->setParameter('entite', $entite)
            ->andWhere('sas.isActive = 1')
            ->orderBy('sas.createdAt', 'DESC')
            ->addOrderBy('sas.dateCreation', 'DESC')
            ->getQuery()
            ->getResult();


        // ---------------------------
        // SUPPORTS ASSIGNÉS AUX UTILISATEURS (stagiaires de la session)
        // ---------------------------
        $stagiaireIds = [];
        foreach ($inscriptionsAll as $ins) {
            if ($ins->getStagiaire()?->getId()) {
                $stagiaireIds[] = $ins->getStagiaire()->getId();
            }
        }
        $stagiaireIds = array_values(array_unique($stagiaireIds));

        $supportAssignUsers = [];
        if (!empty($stagiaireIds)) {
            $supportAssignUsers = $em->getRepository(SupportAssignUser::class)->createQueryBuilder('sau')
                ->leftJoin('sau.asset', 'a')->addSelect('a')
                ->leftJoin('sau.user', 'u')->addSelect('u')
                ->leftJoin('sau.createur', 'c')->addSelect('c')
                ->andWhere('sau.entite = :entite')->setParameter('entite', $entite)
                ->andWhere('sau.isActive = 1')
                ->andWhere('u.id IN (:uids)')->setParameter('uids', $stagiaireIds)
                ->orderBy('u.nom', 'ASC')
                ->addOrderBy('u.prenom', 'ASC')
                ->addOrderBy('sau.createdAt', 'DESC')
                ->addOrderBy('sau.dateCreation', 'DESC')
                ->getQuery()
                ->getResult();
        }


        // Index par userId pour affichage rapide dans Twig
        $supportAssignUsersByUserId = [];
        foreach ($supportAssignUsers as $sau) {
            $uid = $sau->getUser()?->getId();
            if (!$uid) continue;
            $supportAssignUsersByUserId[$uid] ??= [];
            $supportAssignUsersByUserId[$uid][] = $sau;
        }








        return $this->render('administrateur/session/show.html.twig', [
            'session' => $session,
            'entite'  => $entite,
            'byEntreprise' => $byEntreprise,
            'formateursSession' => $formateursSession,
            'contratsByFormateur' => $contratsByFormateur,
            'conventionsByEntrepriseId' => $conventionsByEntrepriseId,
            'conventionsByStagiaireId'  => $conventionsByStagiaireId,
            'travelByFormateurId' => $travelByFormateurId,
            'kmRate' => $kmRate,
            'assignmentsByPhase' => $byPhase,
            'inscriptionsAll' => $inscriptionsAll,
            'pieceTypes' => $pieceTypes,
            'supportAssignSessions' => $supportAssignSessions,
            'supportAssignUsersByUserId' => $supportAssignUsersByUserId,
            'utilisateurEntite' => $this->utilisateurEntiteManager
                ->getRepository()
                ->findOneBy([
                    'entite'      => $entite->getId(),
                    'utilisateur' => $user->getId()
                ]),
        ]);
    }








    #[Route('/ajax/stagiaire/new', name: 'app_administrateur_session_stagiaire_new', methods: ['POST'])]
    public function newStagiaireAjax(
        Entite $entite,
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher
    ): JsonResponse {

        /** @var Utilisateur $creator */
        $creator = $this->getUser();

        $prenom    = trim((string) $request->request->get('prenom', ''));
        $nom       = trim((string) $request->request->get('nom', ''));
        $email     = mb_strtolower(trim((string) $request->request->get('email', '')));
        $telephone = trim((string) $request->request->get('telephone', ''));
        $civilite  = trim((string) $request->request->get('civilite', ''));

        if ($prenom === '' || $nom === '' || $email === '' || $civilite === '') {
            return new JsonResponse([
                'success' => false,
                'message' => 'Civilité, prénom, nom et email sont obligatoires.'
            ], 400);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Email invalide.'
            ], 400);
        }

        $userRepo = $em->getRepository(Utilisateur::class);
        $ueRepo   = $em->getRepository(UtilisateurEntite::class);

        try {

            $em->beginTransaction();

            /** @var Utilisateur|null $user */
            $user = $userRepo->findOneBy(['email' => $email]);

            // ===============================
            // CAS 1 : UTILISATEUR EXISTANT
            // ===============================
            if ($user) {

                if ($telephone !== '' && !$user->getTelephone()) {
                    $user->setTelephone($telephone);
                }

                if ($civilite !== '' && !$user->getCivilite()) {
                    $user->setCivilite($civilite);
                }

                $ue = $ueRepo->findOneBy([
                    'entite' => $entite,
                    'utilisateur' => $user
                ]);

                $isNewForEntite = !$ue;

                $this->ensureUserEntiteRole(
                    $em,
                    $entite,
                    $user,
                    $creator,
                    UtilisateurEntite::TENANT_STAGIAIRE
                );

                if ($isNewForEntite) {
                    $this->billingGuard->assertCanAddApprenantAndConsume($entite, 1);
                }

                $em->flush();
                $em->commit();

                return new JsonResponse([
                    'success' => true,
                    'id'      => $user->getId(),
                    'label'   => trim(sprintf('%s %s (%s)', $user->getPrenom(), $user->getNom(), $user->getEmail())),
                    'already' => true,
                ]);
            }

            // ===============================
            // CAS 2 : NOUVEL UTILISATEUR
            // ===============================
            $user = new Utilisateur();
            $user->setPrenom($prenom);
            $user->setNom($nom);
            $user->setEmail($email);
            $user->setCivilite($civilite);
            $user->setTelephone($telephone !== '' ? $telephone : null);
            $user->setCreateur($creator);
            $user->setEntite($entite);
            $user->setRoles(["ROLE_USER"]);
        

            $plainPassword = bin2hex(random_bytes(8));
            $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));

            // quota apprenant annuel
            $this->billingGuard->assertCanAddApprenantAndConsume($entite, 1);

            $em->persist($user);

            $this->ensureUserEntiteRole(
                $em,
                $entite,
                $user,
                $creator,
                UtilisateurEntite::TENANT_STAGIAIRE
            );

            $em->flush();
            $em->commit();

            return new JsonResponse([
                'success' => true,
                'id'      => $user->getId(),
                'label'   => trim(sprintf('%s %s (%s)', $user->getPrenom(), $user->getNom(), $user->getEmail())),
                'already' => false,
            ]);
        }

        // ===============================
        // CAS CONCURRENCE EMAIL
        // ===============================
        catch (UniqueConstraintViolationException $e) {

            if ($em->getConnection()->isTransactionActive()) {
                $em->rollback();
            }

            $existing = $userRepo->findOneBy(['email' => $email]);

            if ($existing) {

                $em->beginTransaction();

                try {

                    $ue = $ueRepo->findOneBy([
                        'entite' => $entite,
                        'utilisateur' => $existing
                    ]);

                    $isNewForEntite = !$ue;

                    $this->ensureUserEntiteRole(
                        $em,
                        $entite,
                        $existing,
                        $creator,
                        UtilisateurEntite::TENANT_STAGIAIRE
                    );

                    if ($isNewForEntite) {
                        $this->billingGuard->assertCanAddApprenantAndConsume($entite, 1);
                    }

                    $em->flush();
                    $em->commit();

                    return new JsonResponse([
                        'success' => true,
                        'id'      => $existing->getId(),
                        'label'   => trim(sprintf('%s %s (%s)', $existing->getPrenom(), $existing->getNom(), $existing->getEmail())),
                        'already' => true,
                    ]);

                } catch (\Throwable $e2) {

                    if ($em->getConnection()->isTransactionActive()) {
                        $em->rollback();
                    }

                    throw $e2;
                }
            }

            return new JsonResponse([
                'success' => false,
                'message' => "Impossible de créer le stagiaire."
            ], 409);
        }

        // ===============================
        // QUOTA BILLING
        // ===============================
        catch (BillingQuotaExceededException $e) {

            if ($em->getConnection()->isTransactionActive()) {
                $em->rollback();
            }

            return new JsonResponse([
                'success' => false,
                'limitReached' => true,
                'message' => $e->getMessage(),
                'redirect' => $this->generateUrl(
                    'app_administrateur_billing',
                    ['entite' => $entite->getId()]
                ),
            ], 409);
        }

        // ===============================
        // ERREUR GENERALE
        // ===============================
        catch (\Throwable $e) {

            if ($em->getConnection()->isTransactionActive()) {
                $em->rollback();
            }

            return new JsonResponse([
                'success' => false,
                'message' => 'Erreur serveur.'
            ], 500);
        }
    }




    #[Route('/ajax/formateur/new', name: 'app_administrateur_session_formateur_new', methods: ['POST'])]
    public function newFormateurAjax(
        Entite $entite,
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher
    ): JsonResponse {

        $prenom    = trim((string) $request->request->get('prenom', ''));
        $nom       = trim((string) $request->request->get('nom', ''));
        $email     = mb_strtolower(trim((string) $request->request->get('email', '')));
        $telephone = trim((string) $request->request->get('telephone', ''));
        $certif    = trim((string) $request->request->get('certifications', ''));

        if ($prenom === '' || $nom === '' || $email === '') {
            return new JsonResponse([
                'success' => false,
                'message' => 'Prénom, nom et email sont obligatoires.'
            ], 400);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Email invalide.'
            ], 400);
        }

        /** @var Utilisateur $creator */
        $creator = $this->getUser();

        $userRepo      = $em->getRepository(Utilisateur::class);
        $formateurRepo = $em->getRepository(Formateur::class);

        /** @var Utilisateur|null $user */
        $user = $userRepo->findOneBy(['email' => $email]);

        // ===== CAS 1 : utilisateur existant =====
        if ($user) {
            // ✅ (Optionnel) complète le téléphone si vide
            if ($telephone !== '' && method_exists($user, 'getTelephone') && !$user->getTelephone()) {
                $user->setTelephone($telephone);
            }

            // ✅ lien UE + rôle tenant FORMATEUR sur cette entité
            $this->ensureUserEntiteRole($em, $entite, $user, $creator, UtilisateurEntite::TENANT_FORMATEUR);

            /** @var Formateur|null $formateur */
            $formateur = $formateurRepo->findOneBy(['utilisateur' => $user]);
            if (!$formateur) {
                $formateur = new Formateur();
                $formateur->setCreateur($creator);
                $formateur->setEntite($entite);
                $formateur->setUtilisateur($user);
            } else {
                // si ton Formateur est “tenantisé”, tu peux forcer l’entité au besoin :
                if (method_exists($formateur, 'getEntite') && $formateur->getEntite()?->getId() !== $entite->getId()) {
                    $formateur->setEntite($entite);
                }
            }

            if ($certif !== '') {
                $formateur->setCertifications($certif);
            }

            $em->persist($formateur);
            $em->flush();

            return new JsonResponse([
                'success'          => true,
                'id'               => $formateur->getId(),
                'label'            => trim(($user->getPrenom() ?? '') . ' ' . ($user->getNom() ?? '')),
                'alreadyUser'      => true,
                'alreadyFormateur' => true,
            ]);
        }

        // ===== CAS 2 : nouvel utilisateur + nouveau formateur =====
        $user = new Utilisateur();
        $user->setPrenom($prenom);
        $user->setNom($nom);
        $user->setEmail($email);
        if (method_exists($user, 'setTelephone')) {
            $user->setTelephone($telephone !== '' ? $telephone : null);
        }
        $user->setCreateur($creator);
        $user->setEntite($entite);

        // ✅ IMPORTANT : rôle Symfony global minimal (ex: ROLE_USER)
        // (ne mets pas ROLE_USER ici, car c’est désormais un rôle tenant)
        if (method_exists($user, 'setRoles')) {
            $roles = $user->getRoles();
            if (!in_array('ROLE_USER', $roles, true)) {
                $roles[] = 'ROLE_USER';
            }
            $user->setRoles($roles);
        }

        $plainPassword = bin2hex(random_bytes(8));
        $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));

        $em->persist($user);

        // ✅ lien UE + rôle tenant FORMATEUR
        $this->ensureUserEntiteRole($em, $entite, $user, $creator, UtilisateurEntite::TENANT_FORMATEUR);

        $formateur = new Formateur();
        $formateur->setCreateur($creator);
        $formateur->setEntite($entite);
        $formateur->setUtilisateur($user);
        if ($certif !== '') {
            $formateur->setCertifications($certif);
        }

        $em->persist($formateur);
        $em->flush();

        // 👉 Email de création de compte formateur
        $this->mailerManager->sendNewAccountEmail($user, $plainPassword, $entite, true);

        return new JsonResponse([
            'success'          => true,
            'id'               => $formateur->getId(),
            'label'            => trim(($user->getPrenom() ?? '') . ' ' . ($user->getNom() ?? '')),
            'alreadyUser'      => false,
            'alreadyFormateur' => false,
        ]);
    }


    private function ensureUserEntiteRole(
        EntityManagerInterface $em,
        Entite $entite,
        Utilisateur $user,
        Utilisateur $creator,
        string $tenantRole
    ): UtilisateurEntite {

        $ueRepo = $em->getRepository(UtilisateurEntite::class);

        /** @var UtilisateurEntite|null $ue */
        $ue = $ueRepo->findOneBy([
            'entite' => $entite,
            'utilisateur' => $user,
        ]);

        $isNew = !$ue;

        if (!$ue) {

            $ue = new UtilisateurEntite();
            $ue->setCreateur($creator);
            $ue->setUtilisateur($user);
            $ue->setEntite($entite);
            $ue->setStatus(UtilisateurEntite::STATUS_ACTIVE);

            $em->persist($ue);

            if (method_exists($ue, 'ensureCouleur')) {
                $ue->ensureCouleur();
            }
        }

        $currentRoles  = $isNew ? [] : $ue->getRoles();
        $currentStatus = $isNew
            ? UtilisateurEntite::STATUS_INVITED
            : ($ue->getStatus() ?: UtilisateurEntite::STATUS_ACTIVE);

        $futureRoles = $currentRoles;

        if (!in_array($tenantRole, $futureRoles, true)) {
            $futureRoles[] = $tenantRole;
        }

        if (empty($futureRoles)) {
            $futureRoles = [UtilisateurEntite::TENANT_STAGIAIRE];
        }

        $futureStatus = UtilisateurEntite::STATUS_ACTIVE;

        $this->billingGuard->assertCanTransitionUtilisateurEntite(
            entite: $entite,
            currentRoles: $currentRoles,
            currentStatus: $currentStatus,
            futureRoles: $futureRoles,
            futureStatus: $futureStatus,
            excludeUtilisateurEntiteId: $ue->getId(),
        );

        $ue->setRoles($futureRoles);
        $ue->setStatus($futureStatus);

        return $ue;
    }


    #[Route('/ajax/site/new', name: 'app_administrateur_session_site_new', methods: ['POST'])]
    public function newSiteAjax(
        Entite $entite,
        Request $request,
        EntityManagerInterface $em,
        NominatimGeocoder $geocoder
    ): JsonResponse {


        /** @var Utilisateur $user */
        $user = $this->getUser();
        $nom         = trim((string) $request->request->get('nom', ''));
        $adresse     = trim((string) $request->request->get('adresse', ''));
        $complement  = trim((string) $request->request->get('complement', ''));
        $codePostal  = trim((string) $request->request->get('codePostal', ''));
        $ville       = trim((string) $request->request->get('ville', ''));
        $departement = trim((string) $request->request->get('departement', ''));
        $region      = trim((string) $request->request->get('region', ''));
        $pays        = trim((string) $request->request->get('pays', '')) ?: 'France';
        $timezone    = trim((string) $request->request->get('timezone', '')) ?: 'Europe/Paris';
        $latitude    = $request->request->get('latitude');
        $longitude   = $request->request->get('longitude');

        if (!$nom) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Le nom du site est obligatoire.'
            ], 400);
        }

        $siteRepo = $em->getRepository(Site::class);

        // Génération d’un slug unique
        $slugger  = new AsciiSlugger();
        $baseSlug = strtolower($slugger->slug($nom)->toString());
        if ($baseSlug === '') {
            $baseSlug = 'site';
        }
        $slug   = $baseSlug;
        $suffix = 1;
        while ($siteRepo->findOneBy(['slug' => $slug])) {
            $slug = $baseSlug . '-' . $suffix++;
        }

        $site = new Site();
        $site
            ->setNom($nom)
            ->setSlug($slug)
            ->setCreateur($user)
            ->setAdresse($adresse ?: null)
            ->setComplement($complement ?: null)
            ->setCodePostal($codePostal ?: null)
            ->setVille($ville ?: null)
            ->setDepartement($departement ?: null)
            ->setRegion($region ?: null)
            ->setPays($pays ?: 'France')
            ->setTimezone($timezone ?: 'Europe/Paris')
            ->setEntite($entite)
        ;

        // 1) Si l’admin a déjà saisi lat/lng, on respecte
        if ($latitude !== null && $latitude !== '') {
            $site->setLatitude((float) $latitude);
        }
        if ($longitude !== null && $longitude !== '') {
            $site->setLongitude((float) $longitude);
        }

        // 2) Sinon, on tente un géocodage Nominatim à partir de l’adresse
        if ($site->getLatitude() === null || $site->getLongitude() === null) {
            $parts = [];
            if ($adresse) {
                $parts[] = $adresse;
            }
            if ($codePostal || $ville) {
                $parts[] = trim(($codePostal ?: '') . ' ' . ($ville ?: ''));
            }
            if ($pays) {
                $parts[] = $pays;
            }

            $fullAddress = implode(', ', $parts);

            if ($fullAddress !== '') {
                $coords = $geocoder->geocode($fullAddress);
                if ($coords) {
                    $site->setLatitude($coords['lat']);
                    $site->setLongitude($coords['lng']);
                }
            }
        }

        $em->persist($site);
        $em->flush();

        // Label pour TomSelect
        $labelParts = [$site->getNom()];
        if ($site->getCodePostal() || $site->getVille()) {
            $labelParts[] = trim(($site->getCodePostal() ?? '') . ' ' . ($site->getVille() ?? ''));
        }
        $label = implode(' - ', $labelParts);

        return new JsonResponse([
            'success' => true,
            'id'      => $site->getId(),
            'label'   => $label,
        ]);
    }



    private function syncEmargementsWithInscriptions(Session $session, EntityManagerInterface $em): void
    {
        // ✅ Session pas encore enregistrée => aucun émargement en DB à synchroniser/supprimer
        if (null === $session->getId()) {
            return;
        }

        $stagiaireIds = [];
        foreach ($session->getInscriptions() as $inscription) {
            if ($inscription->getStagiaire()) {
                $stagiaireIds[] = $inscription->getStagiaire()->getId();
            }
        }

        $qb = $em->getRepository(Emargement::class)->createQueryBuilder('e')
            ->where('e.session = :session')
            ->setParameter('session', $session)
            ->andWhere('e.role = :role')
            ->setParameter('role', 'stagiaire');

        if (!empty($stagiaireIds)) {
            $qb->andWhere('e.utilisateur NOT IN (:stagiaires)')
                ->setParameter('stagiaires', $stagiaireIds);
        }

        foreach ($qb->getQuery()->getResult() as $emargement) {
            $em->remove($emargement);
        }
    }



    #[Route(
        '/{id}/contrat-formateur/{formateur}',
        name: 'app_administrateur_session_contrat_formateur',
        methods: ['GET']
    )]
    public function generateContratFormateur(
        Entite $entite,
        Session $session,
        Formateur $formateur,
        EntityManagerInterface $em
    ): Response {


        /** @var Utilisateur $user */
        $user = $this->getUser();
        // 🔒 Sécurité entité
        if ($session->getEntite()?->getId() !== $entite->getId()) {
            throw $this->createNotFoundException();
        }
        if ($formateur->getEntite()?->getId() !== $entite->getId()) {
            $this->addFlash('danger', 'Ce formateur n’appartient pas à cette entité.');
            return $this->redirectToRoute('app_administrateur_session_show', [
                'entite' => $entite->getId(),
                'id'     => $session->getId(),
            ]);
        }

        if (!$formateur->getUtilisateur()) {
            $this->addFlash('danger', 'Le formateur sélectionné n’a pas de compte utilisateur associé.');
            return $this->redirectToRoute('app_administrateur_session_show', [
                'entite' => $entite->getId(),
                'id'     => $session->getId(),
            ]);
        }

        $repoContrat = $em->getRepository(ContratFormateur::class);

        /** @var ContratFormateur|null $contrat */
        $contrat = $repoContrat->findOneBy([
            'entite'    => $entite,
            'session'   => $session,
            'formateur' => $formateur,
        ]);

        // 👉 Si le contrat existe déjà, on ouvre directement son édition
        if ($contrat) {
            $this->addFlash('info', sprintf(
                'Contrat formateur n°%s déjà existant : ouverture de la fiche.',
                $contrat->getNumero() ?: $contrat->getId()
            ));

            return $this->redirectToRoute('app_administrateur_formateurs_contrats_edit', [
                'entite' => $entite->getId(),
                'id'     => $contrat->getId(),
            ]);
        }

        // 👉 Sinon, on crée un contrat PRÉ-REMPLI pour ce formateur & cette session

        // 1) Numéro séquentiel
        $numero = $this->contratNumberGenerator->nextForEntite($entite->getId());


        // 2) Montant prévisionnel par défaut : tarif effectif de la session
        $montantPrevu = $session->getTarifEffectifCents() ?? 0;

        $contrat = new ContratFormateur();
        $contrat
            ->setCreateur($user)
            ->setEntite($entite)
            ->setSession($session)
            ->setFormateur($formateur)
            ->setStatus(ContratFormateurStatus::BROUILLON)
            ->setNumero($numero)
            ->setMontantPrevuCents($montantPrevu)
            ->setFraisMissionCents(0);

        // 3) Snapshot TVA depuis le profil formateur
        $contrat
            ->setAssujettiTva($formateur->isAssujettiTva())
            ->setTauxTva($formateur->getTauxTvaParDefaut())
            ->setNumeroTvaIntra($formateur->getNumeroTvaIntra());


        $prefs = $entite->getPreferences();
        if ($prefs) {
            if (!$contrat->getConditionsGenerales()) {
                $contrat->setConditionsGenerales($prefs->getContratFormateurConditionsGeneralesDefault());
            }
            if (!$contrat->getConditionsParticulieres()) {
                $contrat->setConditionsParticulieres($prefs->getContratFormateurConditionsParticulieresDefault());
            }
            if (!$contrat->getClauseEngagement()) {
                $contrat->setClauseEngagement($prefs->getContratFormateurClauseEngagementDefault());
            }
            if (!$contrat->getClauseObjet()) {
                $contrat->setClauseObjet($prefs->getContratFormateurClauseObjetDefault());
            }
            if (!$contrat->getClauseObligations()) {
                $contrat->setClauseObligations($prefs->getContratFormateurClauseObligationsDefault());
            }
            if (!$contrat->getClauseNonConcurrence()) {
                $contrat->setClauseNonConcurrence($prefs->getContratFormateurClauseNonConcurrenceDefault());
            }
            if (!$contrat->getClauseInexecution()) {
                $contrat->setClauseInexecution($prefs->getContratFormateurClauseInexecutionDefault());
            }
            if (!$contrat->getClauseAssurance()) {
                $contrat->setClauseAssurance($prefs->getContratFormateurClauseAssuranceDefault());
            }
            if (!$contrat->getClauseFinContrat()) {
                $contrat->setClauseFinContrat($prefs->getContratFormateurClauseFinContratDefault());
            }
            if (!$contrat->getClauseProprieteIntellectuelle()) {
                $contrat->setClauseProprieteIntellectuelle($prefs->getContratFormateurClauseProprieteIntellectuelleDefault());
            }
        }


        $em->persist($contrat);
        $em->flush();

        $this->addFlash(
            'success',
            sprintf(
                'Contrat formateur pré-rempli n°%s créé pour %s. Tu peux maintenant le compléter.',
                $contrat->getNumero(),
                $formateur->getUtilisateur()
                    ? trim(($formateur->getUtilisateur()->getPrenom() ?? '') . ' ' . ($formateur->getUtilisateur()->getNom() ?? ''))
                    : 'le formateur'
            )
        );

        // 🎯 Redirection vers l’édition du contrat pré-rempli
        return $this->redirectToRoute('app_administrateur_formateurs_contrats_edit', [
            'entite' => $entite->getId(),
            'id'     => $contrat->getId(),
        ]);
    }




    #[Route('/{id}/precheck-full', name: 'app_administrateur_session_precheck_full', methods: ['POST'])]
    public function precheckFull(Entite $entite, Session $session, EntityManagerInterface $em): JsonResponse
    {


        if ($session->getEntite()?->getId() !== $entite->getId()) {
            return new JsonResponse(['ok' => false, 'message' => 'Not found'], 404);
        }

        // ====== CHECKS ======
        $issues = [];
        $warnings = [];
        $oks = [];

        // A) Template satisfaction
        $tpl = $session->getFormation()?->getSatisfactionTemplate();
        if (!$tpl) {
            $warnings[] = "Aucun questionnaire de satisfaction n’est défini sur la formation : aucune affectation satisfaction ne sera créée.";
        } else {
            $oks[] = "Questionnaire de satisfaction trouvé : “{$tpl->getTitre()}”.";
        }

        // B) Inscriptions
        $inscriptionsCount = $em->getRepository(Inscription::class)->count(['session' => $session]);
        if ($inscriptionsCount <= 0) {
            $issues[] = "Aucune inscription dans la session : aucune affectation stagiaire ne sera créée (si tu utilises des réservations, elles ne comptent pas).";
        } else {
            $oks[] = "{$inscriptionsCount} inscription(s) détectée(s).";
        }

        // C) Emails stagiaires (utile si tu envoies des notifs)
        if ($inscriptionsCount > 0) {
            $rows = $em->createQueryBuilder()
                ->select('COUNT(i.id) AS total, SUM(CASE WHEN u.email IS NULL OR u.email = \'\' THEN 1 ELSE 0 END) AS missingEmail')
                ->from(Inscription::class, 'i')
                ->join('i.stagiaire', 'u')
                ->where('i.session = :s')->setParameter('s', $session)
                ->getQuery()->getSingleResult();

            $missingEmail = (int)($rows['missingEmail'] ?? 0);
            if ($missingEmail > 0) {
                $warnings[] = "{$missingEmail} stagiaire(s) n’ont pas d’email : notifications / accès pourront être impactés.";
            } else {
                $oks[] = "Tous les stagiaires ont un email.";
            }
        }

        // D) Capacité vs inscrits (optionnel, mais utile)
        $cap = (int)$session->getCapacite();
        if ($cap > 0 && $inscriptionsCount > $cap) {
            $issues[] = "Incohérence : {$inscriptionsCount} inscrits pour une capacité de {$cap}.";
        }

        // ====== CONSEQUENCES ======
        $consequences = [
            "La session sera marquée comme “Complète”.",
            "Les affectations de satisfaction seront créées pour chaque inscription (si un template est défini).",
            "Les stagiaires pourront voir / démarrer les questionnaires selon ta règle d’ouverture (SatisfactionAccess).",
            "Toute automatisation liée au statut (emails, verrouillages, etc.) s’appliquera.",
        ];

        // “blocking” = on empêche la bascule si issues non vides
        $blocking = count($issues) > 0;

        return new JsonResponse([
            'ok' => true,
            'blocking' => $blocking,
            'issues' => $issues,
            'warnings' => $warnings,
            'oks' => $oks,
            'consequences' => $consequences,
            'stats' => [
                'inscriptions' => $inscriptionsCount,
                'cap' => $cap,
                'hasTemplate' => (bool)$tpl,
            ],
        ]);
    }
}
