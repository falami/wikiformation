<?php
// src/Controller/Administrateur/DashboardAdministrateurController.php
declare(strict_types=1);

namespace App\Controller\Administrateur;

use App\Entity\{Session, Entite, Utilisateur, Facture, Paiement, Inscription, Emargement, PieceDossier};
use App\Enum\{LabelledEnum, PieceType, StatusSession, StatusInscription, FactureStatus, DemiJournee};
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use Doctrine\ORM\EntityManagerInterface as EM;
use App\Security\Permission\TenantPermission;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;


#[Route('/administrateur/{entite}/dashboard', name: 'app_administrateur_dashboard_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::ADMIN_DASHBOARD_MANAGE, subject: 'entite')]
final class DashboardController extends AbstractController
{
  public function __construct(
    private readonly EM $em,
    private readonly UtilisateurEntiteManager $utilisateurEntiteManager,
  ) {}

  #[Route('', name: 'index', methods: ['GET'])]
  public function index(Entite $entite): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();


    return $this->render('administrateur/dashboard/index.html.twig', [
      'title'  => 'Tableau de bord',
      'entite' => $entite,

    ]);
  }

  /**
   * META filtres : formations et formateurs
   * - Formation n'a pas entite => on récupère les formations via sessions.entite
   */
  #[Route('/meta', name: 'meta', methods: ['GET'])]
  public function meta(Entite $entite): JsonResponse
  {

    // Formations réellement utilisées par cette entité (via sessions)
    $formations = $this->em->createQueryBuilder()
      ->select('DISTINCT f.id AS id, f.titre AS label')
      ->from(Session::class, 's')
      ->join('s.formation', 'f')
      ->where('s.entite = :e')
      ->setParameter('e', $entite)
      ->orderBy('f.titre', 'ASC')
      ->getQuery()
      ->getArrayResult();

    // Formateurs (Formateur possède bien entite chez toi)
    $formateurs = $this->em->createQueryBuilder()
      ->select('fo.id AS id, u.nom AS nom, u.prenom AS prenom')
      ->from('App\Entity\Formateur', 'fo')
      ->join('fo.utilisateur', 'u')
      ->where('fo.entite = :e')
      ->setParameter('e', $entite)
      ->orderBy('u.nom', 'ASC')
      ->addOrderBy('u.prenom', 'ASC')
      ->getQuery()
      ->getArrayResult();

    return $this->json([
      'formations' => $formations,
      'formateurs' => array_map(fn($x) => [
        'id' => $x['id'],
        'label' => trim(($x['prenom'] ?? '') . ' ' . ($x['nom'] ?? '')),
      ], $formateurs),
    ]);
  }

  #[Route('/kpis', name: 'kpis', methods: ['GET'])]
  public function kpis(Request $request, Entite $entite): JsonResponse
  {

    [$from, $to] = $this->readDateRange($request);
    $formationId = $request->query->get('formation', 'all');
    $formateurId = $request->query->get('formateur', 'all');
    $sessionStatus = $request->query->get('sessionStatus', 'all');
    $inscriptionStatus = $request->query->get('inscriptionStatus', 'all');

    $today = new \DateTimeImmutable('today');

    /**
     * Sessions filtrées (base)
     */
    $qbSessions = $this->em->createQueryBuilder()
      ->select('COUNT(DISTINCT s.id)')
      ->from(Session::class, 's')
      ->where('s.entite = :e')
      ->setParameter('e', $entite);

    $this->applySessionFilters($qbSessions, $from, $to, $formationId, $formateurId, $sessionStatus);
    $sessionsCount = (int) $qbSessions->getQuery()->getSingleScalarResult();

    /**
     * Sessions à venir / passées
     * On se base sur s.getDateDebut/getDateFin => pas utilisable en DQL facilement
     * => on filtre via SessionJour (min/max).
     */
    $conn = $this->em->getConnection();

    // NB jours et demi-journées sur la période / filtres de session (via SQL)
    // => on calcule à partir des session_jour liées à sessions filtrées.
    $sqlBaseSess = "
            SELECT DISTINCT s.id
            FROM session s
            LEFT JOIN session_jour j ON j.session_id = s.id
            WHERE s.entite_id = :eid
        ";
    $params = ['eid' => $entite->getId()];

    if ($formationId !== 'all') {
      $sqlBaseSess .= " AND s.formation_id = :fid";
      $params['fid'] = (int) $formationId;
    }
    if ($formateurId !== 'all') {
      $sqlBaseSess .= " AND s.formateur_id = :foid";
      $params['foid'] = (int) $formateurId;
    }
    if ($sessionStatus !== 'all') {
      $sqlBaseSess .= " AND s.status = :sst";
      $params['sst'] = $sessionStatus;
    }
    if ($from) {
      $sqlBaseSess .= " AND j.date_debut >= :from";
      $params['from'] = $from->format('Y-m-d H:i:s');
    }
    if ($to) {
      $sqlBaseSess .= " AND j.date_debut <= :to";
      $params['to'] = $to->format('Y-m-d H:i:s');
    }

    $ids = $conn->executeQuery($sqlBaseSess, $params)->fetchFirstColumn();
    $sessionIds = array_map('intval', $ids ?: []);

    $sessionsAVenir = 0;
    $sessionsPassees = 0;
    $nbJours = 0;

    if (!empty($sessionIds)) {
      $in = implode(',', array_fill(0, count($sessionIds), '?'));

      // sessions à venir : min(j.date_debut) >= today
      $sqlUpcoming = "
                SELECT COUNT(*) AS c
                FROM (
                    SELECT s.id, MIN(j.date_debut) AS dmin
                    FROM session s
                    LEFT JOIN session_jour j ON j.session_id = s.id
                    WHERE s.id IN ($in)
                    GROUP BY s.id
                ) x
                WHERE x.dmin >= ?
            ";
      $sessionsAVenir = (int) $conn->executeQuery($sqlUpcoming, array_merge($sessionIds, [$today->format('Y-m-d 00:00:00')]))->fetchOne();

      // sessions passées : max(j.date_fin) < today
      $sqlPast = "
                SELECT COUNT(*) AS c
                FROM (
                    SELECT s.id, MAX(j.date_fin) AS dmax
                    FROM session s
                    LEFT JOIN session_jour j ON j.session_id = s.id
                    WHERE s.id IN ($in)
                    GROUP BY s.id
                ) x
                WHERE x.dmax < ?
            ";
      $sessionsPassees = (int) $conn->executeQuery($sqlPast, array_merge($sessionIds, [$today->format('Y-m-d 00:00:00')]))->fetchOne();

      // nb jours (distinct session_jour)
      $sqlDays = "SELECT COUNT(*) FROM session_jour j WHERE j.session_id IN ($in)";
      $nbJours = (int) $conn->executeQuery($sqlDays, $sessionIds)->fetchOne();
    }

    $nbDemiJournees = $nbJours * 2;

    /**
     * Inscriptions filtrées
     */
    $qbIns = $this->em->createQueryBuilder()
      ->select('COUNT(DISTINCT i.id)')
      ->from(Inscription::class, 'i')
      ->join('i.session', 's')
      ->where('s.entite = :e')
      ->setParameter('e', $entite);

    $this->applySessionFilters($qbIns, $from, $to, $formationId, $formateurId, $sessionStatus);

    if ($inscriptionStatus !== 'all') {
      $qbIns->andWhere('i.status = :ist')
        ->setParameter('ist', StatusInscription::from($inscriptionStatus));
    }

    $inscriptionsCount = (int) $qbIns->getQuery()->getSingleScalarResult();

    /**
     * Réussite
     */
    $qbReussi = $this->em->createQueryBuilder()
      ->select('COALESCE(SUM(CASE WHEN i.reussi = true THEN 1 ELSE 0 END), 0) AS ok')
      ->from(Inscription::class, 'i')
      ->join('i.session', 's')
      ->where('s.entite = :e')
      ->setParameter('e', $entite);

    $this->applySessionFilters($qbReussi, $from, $to, $formationId, $formateurId, $sessionStatus);

    if ($inscriptionStatus !== 'all') {
      $qbReussi->andWhere('i.status = :ist')->setParameter('ist', StatusInscription::from($inscriptionStatus));
    }

    $reussis = (int) ($qbReussi->getQuery()->getSingleScalarResult() ?? 0);
    $tauxReussite = $inscriptionsCount > 0 ? round(($reussis / $inscriptionsCount) * 100, 1) : 0.0;

    /**
     * Factures / Paiements : on filtre via i.session.entite (robuste)
     */
    $conn = $this->em->getConnection();

    // CA facturé (toutes factures)
    $sqlCa = "
  SELECT COALESCE(SUM(x.montant_ttc_cents), 0) AS cents
  FROM (
    SELECT DISTINCT f.id, f.montant_ttc_cents
    FROM facture f
    INNER JOIN facture_inscription fi ON fi.facture_id = f.id
    INNER JOIN inscription i ON i.id = fi.inscription_id
    INNER JOIN session s ON s.id = i.session_id
    LEFT JOIN session_jour j ON j.session_id = s.id
    WHERE s.entite_id = :eid
  ) x
";
    $params = ['eid' => $entite->getId()];

    // 👉 applique ici tes filtres formation/formateur/status/from/to exactement comme dans charts SQL
    // (même logique que ton $sqlBaseSess)

    $caFactureCents = (int) $conn->executeQuery($sqlCa, $params)->fetchOne();



    $qbPaid = $this->em->createQueryBuilder()
      ->select('COALESCE(SUM(p.montantCents), 0)')
      ->from(Paiement::class, 'p')
      ->join('p.facture', 'f')
      ->join('f.inscriptions', 'i')   // ManyToMany Facture<->Inscription
      ->join('i.session', 's')
      ->where('s.entite = :e')
      ->setParameter('e', $entite);

    $this->applySessionFilters($qbPaid, $from, $to, $formationId, $formateurId, $sessionStatus);

    $encaisseCents = (int) $qbPaid->getQuery()->getSingleScalarResult();

    $resteCents = max(0, $caFactureCents - $encaisseCents);

    // Factures en attente de paiement (DUE)
    $qbDueCount = $this->em->createQueryBuilder()
      ->select('COUNT(DISTINCT f.id)')
      ->from(Facture::class, 'f')
      ->join('f.inscriptions', 'i')
      ->join('i.session', 's')
      ->where('s.entite = :e')
      ->andWhere('f.status = :st')
      ->setParameter('e', $entite)
      ->setParameter('st', FactureStatus::DUE);

    $this->applySessionFilters($qbDueCount, $from, $to, $formationId, $formateurId, $sessionStatus);
    $facturesEnAttente = (int) $qbDueCount->getQuery()->getSingleScalarResult();

    // somme des factures DUE (ton JS attend facturesDueCents)
    $sqlDue = "
  SELECT COALESCE(SUM(x.montant_ttc_cents), 0) AS cents
  FROM (
    SELECT DISTINCT f.id, f.montant_ttc_cents
    FROM facture f
    INNER JOIN facture_inscription fi ON fi.facture_id = f.id
    INNER JOIN inscription i ON i.id = fi.inscription_id
    INNER JOIN session s ON s.id = i.session_id
    LEFT JOIN session_jour j ON j.session_id = s.id
    WHERE s.entite_id = :eid
      AND f.status = :due
  ) x
";
    $params = ['eid' => $entite->getId(), 'due' => FactureStatus::DUE->value /* ou ->name selon ton mapping */];

    $facturesDueCents = (int) $conn->executeQuery($sqlDue, $params)->fetchOne();



    /**
     * Dossiers incomplets (nombre)
     */
    $qbDossierKo = $this->em->createQueryBuilder()
      ->select('COUNT(DISTINCT i.id)')
      ->from(Inscription::class, 'i')
      ->join('i.session', 's')
      ->leftJoin('i.dossier', 'd')
      ->where('s.entite = :e')
      ->setParameter('e', $entite);

    $this->applySessionFilters($qbDossierKo, $from, $to, $formationId, $formateurId, $sessionStatus);

    // On limite à inscriptions où session a des pièces obligatoires
    // => JSON non trivial en DQL, on fera un KPI approximatif côté PHP via endpoint /todo
    // Ici on compte juste celles sans dossier (ça repère déjà un gros manque)
    $qbDossierKo->andWhere('d.id IS NULL');
    $dossiersSansDossier = (int) $qbDossierKo->getQuery()->getSingleScalarResult();

    /**
     * Émargements non signés (KPI)
     */
    $qbUnsigned = $this->em->createQueryBuilder()
      ->select('COUNT(DISTINCT e.id)')
      ->from(Emargement::class, 'e')
      ->join('e.session', 's')
      ->where('s.entite = :e')
      ->andWhere('e.signedAt IS NULL')
      ->setParameter('e', $entite);

    $this->applySessionFilters($qbUnsigned, $from, $to, $formationId, $formateurId, $sessionStatus);

    if ($from) $qbUnsigned->andWhere('e.dateJour >= :efrom')->setParameter('efrom', $from->setTime(0, 0));
    if ($to)   $qbUnsigned->andWhere('e.dateJour <= :eto')->setParameter('eto', $to->setTime(0, 0));

    $unsignedEmargementsCount = (int) $qbUnsigned->getQuery()->getSingleScalarResult();

    return $this->json([
      'sessions' => $sessionsCount,
      'sessionsAVenir' => $sessionsAVenir,
      'sessionsPassees' => $sessionsPassees,
      'jours' => $nbJours,
      'demiJournees' => $nbDemiJournees,

      'inscriptions' => $inscriptionsCount,
      'reussis' => $reussis,
      'tauxReussite' => $tauxReussite,

      'caFactureCents' => $caFactureCents,
      'encaisseCents' => $encaisseCents,
      'resteCents' => $resteCents,
      'facturesEnAttente' => $facturesEnAttente,

      'dossiersSansDossier' => $dossiersSansDossier,
      'unsignedEmargements' => $unsignedEmargementsCount,
    ]);
  }

  /**
   * Charts : tu peux garder ta version SQL cashByMonth,
   * je ne la recopie pas ici pour éviter un pavé (on peut la remettre après).
   */

  /**
   * TODO : docs manquants + émargements non signés (corrigé avec i.stagiaire)
   */
  #[Route('/todo', name: 'todo', methods: ['GET'])]
  public function todo(Request $request, Entite $entite): JsonResponse
  {

    [$from, $to] = $this->readDateRange($request);

    /**
     * Helper pour transformer enum / objet / string en label lisible
     */

    $toLabel = static function (mixed $e): string {

      // déjà un enum PieceType
      if ($e instanceof PieceType) {
        return $e->label();
      }

      // string => tentative de conversion PieceType
      if (is_string($e)) {
        $enum = PieceType::tryFrom($e);
        return $enum ? $enum->label() : $e;
      }

      // autres enums qui savent se "labelliser"
      if ($e instanceof LabelledEnum) {
        return $e->label();
      }

      // enum "classique" (value)
      if ($e instanceof \BackedEnum) {
        return (string) $e->value;
      }

      // objet avec label()
      if (is_object($e) && method_exists($e, 'label')) {
        return (string) $e->label();
      }

      return (string) $e;
    };


    /**
     * Inscriptions => docs manquants (Top 20)
     */
    $inscriptions = $this->em->createQueryBuilder()
      ->select('i', 's', 'u', 'd', 'en')
      ->from(Inscription::class, 'i')
      ->join('i.session', 's')
      ->join('i.stagiaire', 'u')
      ->leftJoin('i.dossier', 'd')
      ->leftJoin('i.entreprise', 'en')
      ->where('s.entite = :e')
      ->setParameter('e', $entite)
      ->orderBy('i.id', 'DESC')
      ->setMaxResults(300)
      ->getQuery()
      ->getResult();

    $docsMissing = [];

    foreach ($inscriptions as $i) {
      /** @var Inscription $i */
      $session = $i->getSession();
      if (!$session) continue;

      $piecesObligatoires = $session->getPiecesObligatoires();
      if (empty($piecesObligatoires)) continue;

      $stagiaire = $i->getStagiaire();
      $userId = $stagiaire?->getId();

      $userLabel = $stagiaire
        ? trim(($stagiaire->getPrenom() ?? '') . ' ' . ($stagiaire->getNom() ?? ''))
        : '—';

      if ($i->getEntreprise()) {
        $nomEntreprise = method_exists($i->getEntreprise(), 'getRaisonSociale')
          ? (string) $i->getEntreprise()->getRaisonSociale()
          : (string) ($i->getEntreprise()->__toString() ?? 'Entreprise');

        $userLabel = trim($userLabel . ' — ' . $nomEntreprise);
      }

      $dossier = $i->getDossier();

      // CAS 1 : aucun dossier
      if (!$dossier) {
        $docsMissing[] = [
          'sessionId'     => $session->getId(),
          'sessionLabel'  => $session->getCode() ?? ('Session #' . $session->getId()),
          'userId'        => $userId,
          'userLabel'     => $userLabel,
          'missingCount'  => count($piecesObligatoires),
          'missingLabels' => array_map($toLabel, $piecesObligatoires),
        ];
        continue;
      }


      // ...

      // CAS 2 : dossier existant => on regarde pièce par pièce
      $pieces = $dossier->getPieces(); // Collection<PieceDossier>

      // index par type (on prend la plus récente si plusieurs)
      $byType = [];
      foreach ($pieces as $p) {
        if (!$p instanceof PieceDossier) continue;

        $type = $p->getType();
        $tKey = $type instanceof \BackedEnum ? $type->value : (string) $type;

        if (!isset($byType[$tKey])) {
          $byType[$tKey] = $p;
          continue;
        }
        // garde la plus récente
        if ($p->getUploadedAt() > $byType[$tKey]->getUploadedAt()) {
          $byType[$tKey] = $p;
        }
      }

      $missingTypes = [];
      $pendingPieces = []; // pièces déposées mais pas validées

      foreach ($piecesObligatoires as $reqType) {
        // $reqType est probablement un enum PieceType
        $key = $reqType instanceof \BackedEnum ? $reqType->value : (string) $reqType;

        $piece = $byType[$key] ?? null;

        if (!$piece) {
          $missingTypes[] = $reqType;
          continue;
        }

        if (!$piece->isValide()) {
          $pendingPieces[] = [
            'pieceId'    => $piece->getId(),
            'label'      => $toLabel($reqType),
            'uploadedAt' => $piece->getUploadedAt()?->format('d/m/Y') ?? null,
          ];
        }
      }

      if (!empty($missingTypes) || !empty($pendingPieces)) {
        $docsMissing[] = [
          'sessionId'      => $session->getId(),
          'sessionLabel'   => $session->getCode() ?? ('Session #' . $session->getId()),
          'userId'         => $userId,
          'userLabel'      => $userLabel,

          'missingCount'   => count($missingTypes),
          'missingLabels'  => array_map($toLabel, $missingTypes),

          'pendingCount'   => count($pendingPieces),
          'pendingPieces'  => $pendingPieces,
        ];
      }
    }

    usort($docsMissing, fn($a, $b) => $b['missingCount'] <=> $a['missingCount']);
    $docsMissing = array_slice($docsMissing, 0, 20);

    /**
     * Émargements : MANQUANTS (sessions passées) + NON SIGNÉS (Top 20)
     */
    $today = new \DateTimeImmutable('today');
    $conn = $this->em->getConnection();

    // ⚠️ adapte ces 2 valeurs si ton enum DemiJournee stocke autre chose en DB
    $periode1 = 'AM';
    $periode2 = 'PM';

    // 1) Manquants (on calcule les attendus et on garde ceux qui n’existent pas)
    $sql = "
        SELECT
            s.id   AS session_id,
            s.code AS session_code,
            u.id   AS user_id,
            u.prenom AS prenom,
            u.nom  AS nom,
            DATE(j.date_debut) AS date_jour,
            p.periode AS periode
        FROM session s
        INNER JOIN (
            SELECT session_id, MAX(date_fin) AS dmax
            FROM session_jour
            GROUP BY session_id
        ) sj ON sj.session_id = s.id
        INNER JOIN inscription i ON i.session_id = s.id
        INNER JOIN utilisateur u ON u.id = i.stagiaire_id
        INNER JOIN session_jour j ON j.session_id = s.id
        INNER JOIN (
            SELECT :p1 AS periode
            UNION ALL
            SELECT :p2 AS periode
        ) p
        LEFT JOIN emargement e
            ON e.session_id = s.id
           AND e.utilisateur_id = u.id
           AND e.date_jour = DATE(j.date_debut)
           AND e.periode = p.periode
        WHERE s.entite_id = :eid
          AND sj.dmax < :today
          AND e.id IS NULL
    ";

    $params = [
      'eid'   => $entite->getId(),
      'today' => $today->format('Y-m-d 00:00:00'),
      'p1'    => $periode1,
      'p2'    => $periode2,
    ];

    if ($from) {
      $sql .= " AND j.date_debut >= :from";
      $params['from'] = $from->format('Y-m-d 00:00:00');
    }
    if ($to) {
      $sql .= " AND j.date_debut <= :to";
      $params['to'] = $to->format('Y-m-d 23:59:59');
    }

    $sql .= " ORDER BY j.date_debut ASC, p.periode ASC LIMIT 20";

    $missingRows = $conn->executeQuery($sql, $params)->fetchAllAssociative();

    $missing = array_map(function (array $r) {
      $periode = (string) $r['periode'];
      $periodeLabel = match (strtoupper($periode)) {
        'AM' => 'Matin',
        'PM' => 'Après-midi',
        default => $periode,
      };

      return [
        'type'         => 'missing',
        'badge'        => 'Manquant',
        'sessionId'    => (int) $r['session_id'],
        'sessionLabel' => $r['session_code'] ?: ('Session #' . $r['session_id']),
        'userId'       => (int) $r['user_id'],
        'userLabel'    => trim(($r['prenom'] ?? '') . ' ' . ($r['nom'] ?? '')),
        'date'         => $r['date_jour'] ? (new \DateTimeImmutable($r['date_jour']))->format('d/m/Y') : '—',
        'periode'      => $periodeLabel,
      ];
    }, $missingRows);

    // 2) Non signés (lignes existantes)
    $qbUnsigned = $this->em->createQueryBuilder()
      ->select('e', 's', 'u')
      ->from(Emargement::class, 'e')
      ->join('e.session', 's')
      ->join('e.utilisateur', 'u')
      ->where('s.entite = :e')
      ->andWhere('e.signedAt IS NULL')
      ->setParameter('e', $entite)
      ->orderBy('e.dateJour', 'ASC')
      ->addOrderBy('e.periode', 'ASC')
      ->setMaxResults(20);

    if ($from) {
      $qbUnsigned->andWhere('e.dateJour >= :efrom')
        ->setParameter('efrom', $from->setTime(0, 0));
    }
    if ($to) {
      $qbUnsigned->andWhere('e.dateJour <= :eto')
        ->setParameter('eto', $to->setTime(0, 0));
    }

    $unsignedRows = $qbUnsigned->getQuery()->getResult();
    $unsigned = [];

    foreach ($unsignedRows as $eRow) {
      /** @var Emargement $eRow */
      $sess = $eRow->getSession();
      $usr  = $eRow->getUtilisateur();

      $unsigned[] = [
        'type'         => 'unsigned',
        'badge'        => 'Non signé',
        'sessionId'    => $sess?->getId(),
        'sessionLabel' => $sess?->getCode() ?? ($sess ? ('Session #' . $sess->getId()) : '—'),
        'userId'       => $usr?->getId(),
        'userLabel'    => $usr ? trim(($usr->getPrenom() ?? '') . ' ' . ($usr->getNom() ?? '')) : '—',
        'date'         => $eRow->getDateJour()?->format('d/m/Y') ?? '—',
        'periode'      => $eRow->getPeriode() instanceof DemiJournee
          ? $eRow->getPeriode()->label()
          : (string) $eRow->getPeriode(),
      ];
    }

    // Fusion : manquants d’abord, puis non signés
    $unsignedAll = array_merge($missing, $unsigned);

    return $this->json([
      'docsMissing'         => $docsMissing,
      'unsignedEmargements' => $unsignedAll,
    ]);
  }



  #[Route('/piece/{id}/validate', name: 'piece_validate', methods: ['POST'])]
  public function validatePiece(Request $request, Entite $entite, PieceDossier $piece): JsonResponse
  {

    // ✅ plus robuste : la pièce peut être liée via dossier -> inscription
    $ins  = $piece->getInscription() ?? $piece->getDossier()?->getInscription();
    $sess = $ins?->getSession();

    if (!$sess || $sess->getEntite()?->getId() !== $entite->getId()) {
      return $this->json(['ok' => false, 'error' => 'Accès refusé'], 403);
    }

    // CSRF
    $token = $request->headers->get('X-CSRF-TOKEN');
    if (!$this->isCsrfTokenValid('validate_piece', (string) $token)) {
      return $this->json(['ok' => false, 'error' => 'CSRF invalide'], 419);
    }

    $piece->setValide(true);
    $this->em->flush();

    return $this->json(['ok' => true, 'pieceId' => $piece->getId()]);
  }




  /**
   * Liste "sessions incomplètes" (DataTable)
   * - pas de jours
   * - pas de formateur
   * - pieces obligatoires absentes alors qu'on veut du qualiopi
   */
  #[Route('/sessions-incompletes', name: 'sessions_incompletes', methods: ['GET'])]
  public function sessionsIncompletes(Request $request, Entite $entite): JsonResponse
  {

    $qb = $this->em->createQueryBuilder()
      ->select('s', 'f', 'fo', 'j')
      ->from(Session::class, 's')
      ->join('s.formation', 'f')
      ->leftJoin('s.formateur', 'fo')
      ->leftJoin('s.jours', 'j')
      ->where('s.entite = :e')
      ->setParameter('e', $entite)
      ->orderBy('s.id', 'DESC')
      ->setMaxResults(200);

    $sessions = $qb->getQuery()->getResult();

    $rows = [];
    foreach ($sessions as $s) {
      /** @var Session $s */
      $issues = [];

      if ($s->getJours()->isEmpty()) {
        $issues[] = 'Aucune journée';
      }
      if (!$s->getFormateur()) {
        $issues[] = 'Formateur manquant';
      }
      if (empty($s->getPiecesObligatoires())) {
        $issues[] = 'Pièces obligatoires non définies';
      }
      if (!$s->getSite()) {
        $issues[] = 'Site manquant';
      }
      if (!$s->getFormation()) {
        $issues[] = 'Formation manquante';
      }

      if (empty($issues)) continue;

      $dateDebut = $s->getDateDebut()?->format('d/m/Y') ?? '—';
      $dateFin   = $s->getDateFin()?->format('d/m/Y') ?? '—';

      $rows[] = [
        'id' => $s->getId(),
        'code' => $s->getCode() ?? ('Session #' . $s->getId()),
        'formation' => $s->getFormation()?->getTitre() ?? '—',
        'formateur' => $s->getFormateur()
          ? (method_exists($s->getFormateur(), 'getUtilisateur') && $s->getFormateur()->getUtilisateur()
            ? trim(($s->getFormateur()->getUtilisateur()->getPrenom() ?? '') . ' ' . ($s->getFormateur()->getUtilisateur()->getNom() ?? ''))
            : ('Formateur #' . $s->getFormateur()->getId()))
          : '—',
        'dates' => $dateDebut . ' → ' . $dateFin,
        'status' => $s->getStatus()->label(),
        'issues' => $issues,
        'issuesCount' => count($issues),
      ];
    }

    usort($rows, fn($a, $b) => $b['issuesCount'] <=> $a['issuesCount']);

    return $this->json(['data' => array_slice($rows, 0, 100)]);
  }

  /**
   * Liste sessions à venir (DataTable)
   */
  #[Route('/sessions-avenir', name: 'sessions_avenir', methods: ['GET'])]
  public function sessionsAvenir(Entite $entite): JsonResponse
  {

    $today = new \DateTimeImmutable('today');
    $conn = $this->em->getConnection();

    $sql = "
        SELECT s.id, s.code, s.status, f.titre AS formation,
               x.dmin, x.dmax
        FROM session s
        INNER JOIN formation f ON f.id = s.formation_id
        INNER JOIN (
            SELECT session_id, MIN(date_debut) AS dmin, MAX(date_fin) AS dmax
            FROM session_jour
            GROUP BY session_id
        ) x ON x.session_id = s.id
        WHERE s.entite_id = :eid
          AND x.dmin >= :today
        ORDER BY x.dmin ASC
        LIMIT 100
    ";

    $rows = $conn->executeQuery($sql, [
      'eid'   => $entite->getId(),
      'today' => $today->format('Y-m-d 00:00:00'),
    ])->fetchAllAssociative();

    $out = array_map(function (array $r) {
      $dmin = $r['dmin'] ? (new \DateTimeImmutable($r['dmin']))->format('d/m/Y') : '—';
      $dmax = $r['dmax'] ? (new \DateTimeImmutable($r['dmax']))->format('d/m/Y') : '—';
      $status = StatusSession::tryFrom((string) $r['status']);

      return [
        'id'        => (int) $r['id'],
        'code'      => $r['code'] ?? ('Session #' . $r['id']),
        'formation' => $r['formation'] ?? '—',
        'dates'     => $dmin . ' → ' . $dmax,
        'status'    => $status ? $status->label() : (string) $r['status'],
        'statusRaw' => (string) $r['status'], // utile pour badge couleur
      ];
    }, $rows);

    return $this->json(['data' => $out]);
  }


  private function readDateRange(Request $request): array
  {
    $from = $request->query->get('from');
    $to = $request->query->get('to');

    $fromDt = $from ? new \DateTimeImmutable($from . ' 00:00:00') : null;
    $toDt   = $to   ? new \DateTimeImmutable($to . ' 23:59:59') : null;

    return [$fromDt, $toDt];
  }

  private function applySessionFilters($qb, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to, string $formationId, string $formateurId, string $sessionStatus): void
  {
    $aliases = $qb->getAllAliases();
    if (!in_array('s', $aliases, true)) {
      return;
    }

    // alias dédié pour éviter "j already defined"
    if ($from || $to) {
      $qb->leftJoin('s.jours', 'sjf');

      if ($from) {
        $qb->andWhere('sjf.dateDebut >= :from')->setParameter('from', $from);
      }
      if ($to) {
        $qb->andWhere('sjf.dateDebut <= :to')->setParameter('to', $to);
      }
    }

    if ($formationId !== 'all') {
      $qb->andWhere('s.formation = :f')->setParameter('f', (int) $formationId);
    }

    if ($formateurId !== 'all') {
      $qb->andWhere('s.formateur = :fo')->setParameter('fo', (int) $formateurId);
    }

    if ($sessionStatus !== 'all') {
      $qb->andWhere('s.status = :ss')->setParameter('ss', StatusSession::from($sessionStatus));
    }
  }



  #[Route('/charts', name: 'charts', methods: ['GET'])]
  public function charts(Request $request, Entite $entite): JsonResponse
  {

    [$from, $to] = $this->readDateRange($request);
    $formationId = $request->query->get('formation', 'all');
    $formateurId = $request->query->get('formateur', 'all');
    $sessionStatus = $request->query->get('sessionStatus', 'all');
    $inscriptionStatus = $request->query->get('inscriptionStatus', 'all');

    // 1) Inscriptions par statut (DQL OK)
    $qb = $this->em->createQueryBuilder()
      ->select('i.status AS st, COUNT(i.id) AS n')
      ->from(Inscription::class, 'i')
      ->join('i.session', 's')
      ->where('s.entite = :e')
      ->setParameter('e', $entite)
      ->groupBy('i.status')
      ->orderBy('n', 'DESC');

    $this->applySessionFilters($qb, $from, $to, $formationId, $formateurId, $sessionStatus);

    if ($inscriptionStatus !== 'all') {
      $qb->andWhere('i.status = :ist')->setParameter('ist', StatusInscription::from($inscriptionStatus));
    }

    $rows = $qb->getQuery()->getResult();

    $labels = [];
    $data = [];
    foreach ($rows as $r) {
      $st = $r['st'];
      if ($st instanceof StatusInscription) {
        $labels[] = $st->label();
      } else {
        $enum = StatusInscription::tryFrom((string)$st);
        $labels[] = $enum ? $enum->label() : (string)$st;
      }
      $data[] = (int)$r['n'];
    }

    // 2) Encaissements par mois (SQL natif => plus de DATE_FORMAT en DQL)
    // 2) Encaissements par mois (SQL natif)
    $conn = $this->em->getConnection();

    $sql = "
  SELECT DATE_FORMAT(p.date_paiement, '%Y-%m') AS ym,
         COALESCE(SUM(p.montant_cents), 0) AS cents
  FROM paiement p
  INNER JOIN facture f ON f.id = p.facture_id
  INNER JOIN facture_inscription fi ON fi.facture_id = f.id
  INNER JOIN inscription i ON i.id = fi.inscription_id
  INNER JOIN session s ON s.id = i.session_id
  LEFT JOIN session_jour j ON j.session_id = s.id
  WHERE s.entite_id = :eid
";

    $params = ['eid' => $entite->getId()];

    if ($formationId !== 'all') {
      $sql .= " AND s.formation_id = :fid";
      $params['fid'] = (int) $formationId;
    }
    if ($formateurId !== 'all') {
      $sql .= " AND s.formateur_id = :foid";
      $params['foid'] = (int) $formateurId;
    }
    if ($sessionStatus !== 'all') {
      $sql .= " AND s.status = :sst";
      $params['sst'] = $sessionStatus;
    }

    // Période (sur jours de session, comme chez toi)
    if ($from) {
      $sql .= " AND j.date_debut >= :from";
      $params['from'] = $from->format('Y-m-d H:i:s');
    }
    if ($to) {
      $sql .= " AND j.date_debut <= :to";
      $params['to'] = $to->format('Y-m-d H:i:s');
    }

    $sql .= "
  GROUP BY ym
  ORDER BY ym ASC
";

    $cashRows = $conn->executeQuery($sql, $params)->fetchAllAssociative();

    $cashLabels = [];
    $cashData = [];
    foreach ($cashRows as $r) {
      $cashLabels[] = (string) $r['ym'];
      $cashData[] = round(((int) $r['cents']) / 100, 2);
    }


    // 3) Sessions par formation (Top) (DQL OK)
    $qbSessF = $this->em->createQueryBuilder()
      ->select('f.titre AS t, COUNT(DISTINCT s.id) AS n')
      ->from(Session::class, 's')
      ->join('s.formation', 'f')
      ->where('s.entite = :e')
      ->setParameter('e', $entite)
      ->groupBy('f.id')
      ->orderBy('n', 'DESC')
      ->setMaxResults(10);

    $this->applySessionFilters($qbSessF, $from, $to, $formationId, $formateurId, $sessionStatus);

    $sfRows = $qbSessF->getQuery()->getArrayResult();
    $sfLabels = array_map(fn($r) => (string)$r['t'], $sfRows);
    $sfData = array_map(fn($r) => (int)$r['n'], $sfRows);

    return $this->json([
      'inscriptionsByStatus' => [
        'labels' => $labels,
        'data'   => $data,
      ],
      'cashByMonth' => [
        'labels' => $cashLabels,
        'data'   => $cashData,
      ],
      'sessionsByFormation' => [
        'labels' => $sfLabels,
        'data'   => $sfData,
      ],
    ]);
  }



  #[Route('/piece/{id}/info', name: 'piece_info', methods: ['GET'])]
  public function pieceInfo(Entite $entite, PieceDossier $piece): JsonResponse
  {

    $ins  = $piece->getInscription() ?? $piece->getDossier()?->getInscription();
    $sess = $ins?->getSession();

    if (!$sess || $sess->getEntite()?->getId() !== $entite->getId()) {
      return $this->json(['ok' => false, 'error' => 'Accès refusé'], 403);
    }


    $typeRaw = $piece->getType(); // peut être PieceType OU string
    $typeEnum = $typeRaw instanceof PieceType
      ? $typeRaw
      : (is_string($typeRaw) ? PieceType::tryFrom($typeRaw) : null);

    $label = $typeEnum?->label() ?? (is_string($typeRaw) ? $typeRaw : (string) $typeRaw);



    $fileUrl = $this->generateUrl('app_administrateur_dashboard_piece_file', [
      'entite' => $entite->getId(),
      'id'     => $piece->getId(),
    ]);

    $stag = $ins?->getStagiaire();
    $userLabel = $stag ? trim(($stag->getPrenom() ?? '') . ' ' . ($stag->getNom() ?? '')) : '';

    $mime = $piece->getMimeType();
    $isImage = $mime ? str_starts_with($mime, 'image/') : (bool) preg_match('/\.(png|jpe?g|gif|webp|bmp)$/i', $piece->getFilename());

    return $this->json([
      'ok'          => true,
      'label'       => $label,
      'meta'        => trim(($piece->getUploadedAt()?->format('d/m/Y H:i') ?? '') . ' • ' . $userLabel),
      'fileUrl'     => $fileUrl,
      'downloadUrl' => $fileUrl . '?download=1',
      'isValide'    => (bool) $piece->isValide(),
      'mime'        => $mime,
      'filename'    => $piece->getFilename(),
      'isImage'     => $isImage,
    ]);
  }




  #[Route('/piece/{id}/file', name: 'piece_file', methods: ['GET'])]
  public function pieceFile(Request $request, Entite $entite, PieceDossier $piece): Response
  {

    $ins  = $piece->getInscription() ?? $piece->getDossier()?->getInscription(); // ✅ plus robuste
    $sess = $ins?->getSession();

    if (!$sess || $sess->getEntite()?->getId() !== $entite->getId()) {
      return new Response('Accès refusé', 403);
    }

    $dir = $this->getParameter('piece_dossier_dir');
    $absPath = rtrim((string)$dir, '/') . '/' . $piece->getFilename();

    if (!is_file($absPath)) {
      return new Response('Fichier introuvable', 404);
    }

    $response = new BinaryFileResponse($absPath);

    $download = (bool) $request->query->get('download', false);
    $disposition = $download ? ResponseHeaderBag::DISPOSITION_ATTACHMENT : ResponseHeaderBag::DISPOSITION_INLINE;

    // petit plus: nom “joli”
    $ext = pathinfo($absPath, PATHINFO_EXTENSION);
    $filename = sprintf('piece_%d.%s', $piece->getId(), $ext ?: 'bin');

    $response->setContentDisposition($disposition, $filename);
    if ($piece->getMimeType()) {
      $response->headers->set('Content-Type', $piece->getMimeType());
    }

    return $response;
  }
}
