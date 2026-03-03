<?php

declare(strict_types=1);

namespace App\Controller\Entreprise;

use App\Entity\{
  Entite,
  Utilisateur,
  Entreprise,
  Devis,
  Facture,
  ConventionContrat,
  Emargement,
  Session,
  Inscription,
  PositioningAttempt,
  QcmAssignment,
  SatisfactionAssignment,
  SatisfactionAttempt,
  FormateurSatisfactionAssignment
};
use App\Enum\DemiJournee;
use App\Enum\{DevisStatus, FactureStatus, QcmPhase};
use App\Security\Voter\EntrepriseAccessVoter;
use App\Service\Pdf\PdfManager;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, JsonResponse, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use App\Security\Permission\TenantPermission;


#[Route('/entreprise/{entite}/documents', name: 'app_entreprise_documents_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::ENTREPRISE_DOCUMENTS_MANAGE, subject: 'entite')]
final class EntrepriseDocumentsController extends AbstractController
{
  public function __construct(
    private UtilisateurEntiteManager $utilisateurEntiteManager,
    private ?PdfManager $pdf = null,
  ) {}

  private function getEntrepriseUserOrFail(): Entreprise
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();
    $entreprise = $user->getEntreprise();

    if (!$entreprise) {
      throw $this->createAccessDeniedException('Aucune entreprise associée à ce compte.');
    }

    $this->denyAccessUnlessGranted(EntrepriseAccessVoter::VIEW_ENTREPRISE, $entreprise);

    return $entreprise;
  }

  private function dtParams(Request $request): array
  {
    $draw   = $request->request->getInt('draw', 1);
    $start  = max(0, $request->request->getInt('start', 0));
    $length = $request->request->getInt('length', 10);
    if ($length <= 0) $length = 10;

    $searchV = trim((string)(($request->request->all('search')['value'] ?? '') ?: ''));
    $order   = $request->request->all('order') ?? [];

    $orderColIdx = isset($order[0]['column']) ? (int)$order[0]['column'] : 0;
    $orderDir    = (isset($order[0]['dir']) && strtolower((string)$order[0]['dir']) === 'asc') ? 'ASC' : 'DESC';

    return [$draw, $start, $length, $searchV, $orderColIdx, $orderDir];
  }

  #[Route('', name: 'index', methods: ['GET'])]
  public function index(Entite $entite): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    return $this->render('entreprise/documents/index.html.twig', [
      'entite' => $entite,
      'entreprise' => $this->getEntrepriseUserOrFail(),


    ]);
  }

  // ==========================================================
  // DEVIS — DataTables serverSide
  // ==========================================================
  #[Route('/devis/ajax', name: 'devis_ajax', methods: ['POST'])]
  public function devisAjax(Entite $entite, Request $request, EM $em): JsonResponse
  {
    $entreprise = $this->getEntrepriseUserOrFail();
    [$draw, $start, $length, $searchV, $orderColIdx, $orderDir] = $this->dtParams($request);

    $statusFilter = (string)$request->request->get('statusFilter', 'all');

    $map = [
      0 => 'd.dateEmission',
      1 => 'd.numero',
      2 => 'd.montantTtcCents',
      3 => 'd.status',
    ];

    $qb = $em->getRepository(Devis::class)->createQueryBuilder('d')
      ->andWhere('d.entite = :entite')->setParameter('entite', $entite)
      ->andWhere('d.entrepriseDestinataire = :e')->setParameter('e', $entreprise);

    $recordsTotal = (int)(clone $qb)
      ->select('COUNT(DISTINCT d.id)')
      ->resetDQLPart('orderBy')
      ->getQuery()->getSingleScalarResult();

    if ($searchV !== '') {
      $qb->andWhere('(d.numero LIKE :s)')
        ->setParameter('s', '%' . $searchV . '%');
    }

    if ($statusFilter !== 'all') {
      try {
        $st = DevisStatus::from($statusFilter);
        $qb->andWhere('d.status = :st')->setParameter('st', $st);
      } catch (\ValueError $e) {
      }
    }

    $recordsFiltered = (int)(clone $qb)
      ->select('COUNT(DISTINCT d.id)')
      ->resetDQLPart('orderBy')
      ->getQuery()->getSingleScalarResult();

    $orderBy = $map[$orderColIdx] ?? 'd.dateEmission';

    /** @var Devis[] $rows */
    $rows = $qb->orderBy($orderBy, $orderDir)
      ->setFirstResult($start)
      ->setMaxResults($length)
      ->getQuery()->getResult();

    $data = [];
    foreach ($rows as $d) {
      $pdfUrl = $this->generateUrl('app_entreprise_documents_devis_pdf', [
        'entite' => $entite->getId(),
        'id' => $d->getId(),
      ]);

      $data[] = [
        'date' => $d->getDateEmission()?->format('d/m/Y') ?? '—',
        'numero' => $d->getNumero() ?: ('Devis #' . $d->getId()),
        'ttc' => number_format(((int)$d->getMontantTtcCents()) / 100, 2, ',', ' ') . ' €',
        'status' => $this->statusBadgeDevis($d->getStatus()),
        'actions' => sprintf(
          '<div class="d-flex gap-2 justify-content-end">
              <a class="btn btn-sm btn-outline-secondary" href="%s" target="_blank"><i class="bi bi-filetype-pdf"></i> PDF</a>
            </div>',
          htmlspecialchars($pdfUrl, ENT_QUOTES)
        ),
      ];
    }

    return new JsonResponse([
      'draw' => $draw,
      'recordsTotal' => $recordsTotal,
      'recordsFiltered' => $recordsFiltered,
      'data' => $data,
    ]);
  }

  // ==========================================================
  // FACTURES — DataTables serverSide
  // ==========================================================
  #[Route('/factures/ajax', name: 'factures_ajax', methods: ['POST'])]
  public function facturesAjax(Entite $entite, Request $request, EM $em): JsonResponse
  {
    $entreprise = $this->getEntrepriseUserOrFail();
    [$draw, $start, $length, $searchV, $orderColIdx, $orderDir] = $this->dtParams($request);

    $statusFilter = (string)$request->request->get('statusFilter', 'all');

    $map = [
      0 => 'f.dateEmission',
      1 => 'f.numero',
      2 => 'f.montantTtcCents',
      3 => 'f.status',
    ];

    $qb = $em->getRepository(Facture::class)->createQueryBuilder('f')
      ->andWhere('f.entite = :entite')->setParameter('entite', $entite)
      ->andWhere('f.entrepriseDestinataire = :e')->setParameter('e', $entreprise);

    $recordsTotal = (int)(clone $qb)
      ->select('COUNT(DISTINCT f.id)')
      ->resetDQLPart('orderBy')
      ->getQuery()->getSingleScalarResult();

    if ($searchV !== '') {
      $qb->andWhere('(f.numero LIKE :s OR f.note LIKE :s)')
        ->setParameter('s', '%' . $searchV . '%');
    }

    if ($statusFilter !== 'all') {
      try {
        $st = FactureStatus::from($statusFilter);
        $qb->andWhere('f.status = :st')->setParameter('st', $st);
      } catch (\ValueError $e) {
      }
    }

    $recordsFiltered = (int)(clone $qb)
      ->select('COUNT(DISTINCT f.id)')
      ->resetDQLPart('orderBy')
      ->getQuery()->getSingleScalarResult();

    $orderBy = $map[$orderColIdx] ?? 'f.dateEmission';

    /** @var Facture[] $rows */
    $rows = $qb->orderBy($orderBy, $orderDir)
      ->setFirstResult($start)
      ->setMaxResults($length)
      ->getQuery()->getResult();

    $data = [];
    foreach ($rows as $f) {
      $pdfUrl = $this->generateUrl('app_entreprise_documents_facture_pdf', [
        'entite' => $entite->getId(),
        'id' => $f->getId(),
      ]);

      $data[] = [
        'date' => $f->getDateEmission()?->format('d/m/Y') ?? '—',
        'numero' => $f->getNumero() ?: ('Facture #' . $f->getId()),
        'ttc' => number_format(((int)$f->getMontantTtcCents()) / 100, 2, ',', ' ') . ' €',
        'status' => $this->statusBadgeFacture($f->getStatus()),
        'actions' => sprintf(
          '<div class="d-flex gap-2 justify-content-end">
              <a class="btn btn-sm btn-outline-secondary" href="%s" target="_blank"><i class="bi bi-filetype-pdf"></i> PDF</a>
            </div>',
          htmlspecialchars($pdfUrl, ENT_QUOTES)
        ),
      ];
    }

    return new JsonResponse([
      'draw' => $draw,
      'recordsTotal' => $recordsTotal,
      'recordsFiltered' => $recordsFiltered,
      'data' => $data,
    ]);
  }

  // ==========================================================
  // CONVENTIONS / CONTRATS — DataTables serverSide
  // ==========================================================
  #[Route('/conventions/ajax', name: 'conventions_ajax', methods: ['POST'])]
  public function conventionsAjax(Entite $entite, Request $request, EM $em): JsonResponse
  {
    $entreprise = $this->getEntrepriseUserOrFail();
    [$draw, $start, $length, $searchV, $orderColIdx, $orderDir] = $this->dtParams($request);

    $signFilter = (string)$request->request->get('signFilter', 'all'); // all|signed|pending

    $map = [
      0 => 'cc.dateCreation',
      1 => 'cc.numero',
      2 => 's.code',
      3 => 'u.nom', // fallback
    ];

    $qb = $em->getRepository(ConventionContrat::class)->createQueryBuilder('cc')
      ->leftJoin('cc.session', 's')->addSelect('s')
      ->leftJoin('cc.stagiaire', 'u')->addSelect('u')
      ->leftJoin('cc.inscriptions', 'ins') // ManyToMany
      ->andWhere('cc.entite = :entite')->setParameter('entite', $entite)
      ->andWhere('(cc.entreprise = :e OR ins.entreprise = :e)')
      ->setParameter('e', $entreprise);

    $recordsTotal = (int)(clone $qb)
      ->select('COUNT(DISTINCT cc.id)')
      ->resetDQLPart('orderBy')
      ->getQuery()->getSingleScalarResult();

    if ($searchV !== '') {
      $qb->andWhere('(cc.numero LIKE :s OR s.code LIKE :s)')
        ->setParameter('s', '%' . $searchV . '%');
    }

    // signé = entreprise + OF + (stagiaire ou entreprise selon cas)
    if ($signFilter !== 'all') {
      if ($signFilter === 'signed') {
        $qb->andWhere('cc.dateSignatureOf IS NOT NULL')
          ->andWhere('(cc.dateSignatureEntreprise IS NOT NULL OR cc.signatureDataUrlEntreprise IS NOT NULL)')
          ->andWhere('(cc.dateSignatureStagiaire IS NOT NULL OR cc.signatureDataUrlStagiaire IS NOT NULL)');
      } elseif ($signFilter === 'pending') {
        $qb->andWhere('(cc.dateSignatureOf IS NULL OR cc.dateSignatureEntreprise IS NULL OR cc.dateSignatureStagiaire IS NULL)');
      }
    }

    $recordsFiltered = (int)(clone $qb)
      ->select('COUNT(DISTINCT cc.id)')
      ->resetDQLPart('orderBy')
      ->getQuery()->getSingleScalarResult();

    $orderBy = $map[$orderColIdx] ?? 'cc.dateCreation';

    /** @var ConventionContrat[] $rows */
    $rows = $qb->orderBy($orderBy, $orderDir)
      ->setFirstResult($start)
      ->setMaxResults($length)
      ->getQuery()->getResult();

    $data = [];
    foreach ($rows as $cc) {
      $pdfUrl = $this->generateUrl('app_entreprise_documents_convention_pdf', [
        'entite' => $entite->getId(),
        'id' => $cc->getId(),
      ]);

      $stag = $cc->getStagiaire();
      $stagLabel = $stag ? trim(($stag->getPrenom() ?? '') . ' ' . ($stag->getNom() ?? '')) : '—';

      $session = $cc->getSession();
      $sessLabel = $session ? ($session->getCode() ?? ('Session #' . $session->getId())) : '—';

      $data[] = [
        'created' => $cc->getDateCreation()?->format('d/m/Y') ?? '—',
        'numero' => $cc->getNumero() ?: ('CC #' . $cc->getId()),
        'session' => htmlspecialchars($sessLabel),
        'stagiaire' => htmlspecialchars($stagLabel ?: '—'),
        'signatures' => $this->badgeSignaturesConvention($cc),
        'actions' => sprintf(
          '<div class="d-flex gap-2 justify-content-end">
              <a class="btn btn-sm btn-outline-secondary" href="%s" target="_blank"><i class="bi bi-filetype-pdf"></i> PDF</a>
            </div>',
          htmlspecialchars($pdfUrl, ENT_QUOTES)
        ),
      ];
    }

    return new JsonResponse([
      'draw' => $draw,
      'recordsTotal' => $recordsTotal,
      'recordsFiltered' => $recordsFiltered,
      'data' => $data,
    ]);
  }

  // ==========================================================
  // EMARGEMENTS — DataTables serverSide (par Session)
  // ==========================================================
  #[Route('/emargements/ajax', name: 'emargements_ajax', methods: ['POST'])]
  public function emargementsAjax(Entite $entite, Request $request, EM $em): JsonResponse
  {
    $entreprise = $this->getEntrepriseUserOrFail();
    [$draw, $start, $length, $searchV, $orderColIdx, $orderDir] = $this->dtParams($request);

    $signedFilter = (string) $request->request->get('signedFilter', 'all'); // all|signed|missing

    // Map DataTables -> alias DQL disponibles
    $map = [
      0 => 'dateDebutSort', // calculé via MIN(j.dateDebut)
      1 => 's.code',
      2 => 'signedCount',   // agrégat
    ];

    // --- QB "base" : sessions accessibles à l’entreprise
    // (on sépare la base (pour counts) et la partie agrégats (pour rows))
    $baseQb = $em->getRepository(Session::class)->createQueryBuilder('s')
      ->innerJoin('s.inscriptions', 'ins')
      ->andWhere('s.entite = :entite')
      ->andWhere('ins.entreprise = :entreprise')
      ->setParameter('entite', $entite)
      ->setParameter('entreprise', $entreprise);

    // Recherche (⚠️ pas de s.label si tu n'as pas ce champ en DB)
    if ($searchV !== '') {
      $baseQb->andWhere('(s.code LIKE :q)')
        ->setParameter('q', '%' . $searchV . '%');
    }

    // Filtre signé / manquant (sans HAVING) via EXISTS
    // signé = il existe au moins un émargement signé pour cette session et cette entité
    if ($signedFilter === 'signed') {
      $baseQb->andWhere(
        'EXISTS (
                SELECT 1
                FROM ' . Emargement::class . ' em2
                WHERE em2.session = s
                  AND em2.entite = :entite
                  AND em2.signedAt IS NOT NULL
            )'
      );
    } elseif ($signedFilter === 'missing') {
      $baseQb->andWhere(
        'NOT EXISTS (
                SELECT 1
                FROM ' . Emargement::class . ' em2
                WHERE em2.session = s
                  AND em2.entite = :entite
                  AND em2.signedAt IS NOT NULL
            )'
      );
    }

    // recordsTotal : mêmes contraintes entite/entreprise, sans search + sans signedFilter
    $qbTotal = $em->getRepository(Session::class)->createQueryBuilder('s')
      ->select('COUNT(DISTINCT s.id)')
      ->innerJoin('s.inscriptions', 'ins')
      ->andWhere('s.entite = :entite')
      ->andWhere('ins.entreprise = :entreprise')
      ->setParameter('entite', $entite)
      ->setParameter('entreprise', $entreprise);

    $recordsTotal = (int) $qbTotal->getQuery()->getSingleScalarResult();

    // recordsFiltered : avec search + filtre signed/missing
    $qbFiltered = (clone $baseQb)
      ->select('COUNT(DISTINCT s.id)')
      ->resetDQLPart('orderBy');

    $recordsFiltered = (int) $qbFiltered->getQuery()->getSingleScalarResult();

    // --- QB Rows : on repart de baseQb, et on ajoute agrégats + dates calculées via jours
    $qb = (clone $baseQb)
      ->leftJoin('s.jours', 'j')
      ->leftJoin(Emargement::class, 'emar', 'WITH', 'emar.session = s AND emar.entite = :entite')
      ->addSelect('MIN(j.dateDebut) AS dateDebutSort')
      ->addSelect('MAX(j.dateFin) AS dateFinSort')
      ->addSelect('COUNT(emar.id) AS totalEmar')
      ->addSelect('SUM(CASE WHEN emar.signedAt IS NOT NULL THEN 1 ELSE 0 END) AS signedCount')
      ->groupBy('s.id');

    // Tri
    $orderBy = $map[$orderColIdx] ?? 'dateDebutSort';
    if (in_array($orderBy, ['signedCount', 'totalEmar', 'dateDebutSort', 'dateFinSort'], true)) {
      $qb->orderBy($orderBy, $orderDir);
    } else {
      $qb->orderBy($orderBy, $orderDir);
    }

    $rows = $qb
      ->setFirstResult($start)
      ->setMaxResults($length)
      ->getQuery()
      ->getResult(); // mix [0 => Session, dateDebutSort => ..., signedCount => ...]

    $data = [];

    foreach ($rows as $row) {
      $session = $row instanceof Session ? $row : ($row[0] ?? null);
      if (!$session instanceof Session) {
        continue;
      }

      $sessLabel = $session->getCode() ?: ('Session #' . $session->getId());

      $totalEmar   = (int) ($row['totalEmar'] ?? 0);
      $signedCount = (int) ($row['signedCount'] ?? 0);

      // --- dates calculées (Doctrine peut renvoyer string au lieu de DateTime)
      $dateDebutRaw = $row['dateDebutSort'] ?? null;
      $dateFinRaw   = $row['dateFinSort'] ?? null;

      $dateDebut = $dateDebutRaw instanceof \DateTimeInterface
        ? \DateTimeImmutable::createFromInterface($dateDebutRaw)
        : (is_string($dateDebutRaw) && $dateDebutRaw !== '' ? new \DateTimeImmutable($dateDebutRaw) : null);

      $dateFin = $dateFinRaw instanceof \DateTimeInterface
        ? \DateTimeImmutable::createFromInterface($dateFinRaw)
        : (is_string($dateFinRaw) && $dateFinRaw !== '' ? new \DateTimeImmutable($dateFinRaw) : null);

      $periode = ($dateDebut || $dateFin)
        ? sprintf('Du %s au %s', $dateDebut?->format('d/m/Y') ?? '—', $dateFin?->format('d/m/Y') ?? '—')
        : '—';


      $signaturesHtml = sprintf(
        '<span class="badge bg-light text-dark"><i class="bi bi-pen"></i> %d/%d</span>',
        $signedCount,
        $totalEmar
      );

      $statusHtml = $this->badgeSigned($signedCount > 0);

      $pdfUrl = $this->generateUrl('app_entreprise_documents_emargement_sheet_pdf', [
        'entite' => $entite->getId(),
        'id'     => $session->getId(),
      ]);

      $data[] = [
        'session'     => htmlspecialchars($sessLabel, ENT_QUOTES),
        'periode'     => htmlspecialchars($periode, ENT_QUOTES),
        'signatures'  => $signaturesHtml,
        'status'      => $statusHtml,
        'actions'     => sprintf(
          '<div class="d-flex gap-2 justify-content-end">
                    <a class="btn btn-sm btn-outline-secondary" href="%s" target="_blank">
                        <i class="bi bi-filetype-pdf"></i> PDF
                    </a>
                 </div>',
          htmlspecialchars($pdfUrl, ENT_QUOTES)
        ),
      ];
    }

    return new JsonResponse([
      'draw'            => $draw,
      'recordsTotal'    => $recordsTotal,
      'recordsFiltered' => $recordsFiltered,
      'data'            => $data,
    ]);
  }



  // ==========================================================
  // POSITIONNEMENT — DataTables serverSide (PositioningAttempt)
  // ==========================================================
  #[Route('/positionnement/ajax', name: 'positionnement_ajax', methods: ['POST'])]
  public function positionnementAjax(Entite $entite, Request $request, EM $em): JsonResponse
  {
    $entreprise = $this->getEntrepriseUserOrFail();
    [$draw, $start, $length, $searchV, $orderColIdx, $orderDir] = $this->dtParams($request);

    $stateFilter = (string)$request->request->get('stateFilter', 'all'); // all|submitted|started|pending

    $map = [
      0 => 'pa.startedAt',
      1 => 's.code',
      2 => 'u.nom',
      3 => 'pa.submittedAt',
    ];

    $qb = $em->getRepository(PositioningAttempt::class)->createQueryBuilder('pa')
      ->leftJoin('pa.session', 's')->addSelect('s')
      ->innerJoin('pa.stagiaire', 'u')->addSelect('u')
      ->leftJoin('pa.inscription', 'ins')
      ->andWhere('pa.entite = :entite')->setParameter('entite', $entite)
      ->andWhere('ins.entreprise = :e')->setParameter('e', $entreprise);

    $recordsTotal = (int)(clone $qb)
      ->select('COUNT(DISTINCT pa.id)')
      ->resetDQLPart('orderBy')
      ->getQuery()->getSingleScalarResult();

    if ($searchV !== '') {
      $qb->andWhere('(s.code LIKE :s OR u.nom LIKE :s OR u.prenom LIKE :s)')
        ->setParameter('s', '%' . $searchV . '%');
    }

    if ($stateFilter === 'submitted') {
      $qb->andWhere('pa.submittedAt IS NOT NULL');
    } elseif ($stateFilter === 'started') {
      $qb->andWhere('pa.startedAt IS NOT NULL')->andWhere('pa.submittedAt IS NULL');
    } elseif ($stateFilter === 'pending') {
      $qb->andWhere('pa.startedAt IS NULL');
    }

    $recordsFiltered = (int)(clone $qb)
      ->select('COUNT(DISTINCT pa.id)')
      ->resetDQLPart('orderBy')
      ->getQuery()->getSingleScalarResult();

    $orderBy = $map[$orderColIdx] ?? 'pa.startedAt';

    /** @var PositioningAttempt[] $rows */
    $rows = $qb->orderBy($orderBy, $orderDir)
      ->setFirstResult($start)
      ->setMaxResults($length)
      ->getQuery()->getResult();

    $data = [];
    foreach ($rows as $pa) {
      $u = $pa->getStagiaire();
      $stagLabel = $u ? trim(($u->getPrenom() ?? '') . ' ' . ($u->getNom() ?? '')) : '—';
      $s = $pa->getSession();
      $sessLabel = $s ? ($s->getCode() ?? ('Session #' . $s->getId())) : '—';

      $summary = [];
      if (method_exists($pa, 'getSuggestedLevel') && $pa->getSuggestedLevel()) {
        $summary[] = '<span class="badge bg-primary-subtle text-primary">Niv. suggéré: ' . htmlspecialchars((string)$pa->getSuggestedLevel()->name) . '</span>';
      }
      if ($pa->getFormateurConclusion()) {
        $summary[] = '<span class="badge bg-light text-dark"><i class="bi bi-chat-left-text"></i> Conclusion</span>';
      }
      $summaryHtml = $summary ? implode(' ', $summary) : '<span class="badge bg-secondary-subtle text-secondary">—</span>';

      $data[] = [
        'started' => $pa->getStartedAt()?->format('d/m/Y H:i') ?? '—',
        'session' => htmlspecialchars($sessLabel),
        'stagiaire' => htmlspecialchars($stagLabel ?: '—'),
        'submitted' => $pa->getSubmittedAt()?->format('d/m/Y H:i') ?? '—',
        'summary' => $summaryHtml,
        'actions' => '<span class="badge bg-light text-dark"><i class="bi bi-eye"></i> Voir</span>',
      ];
    }

    return new JsonResponse([
      'draw' => $draw,
      'recordsTotal' => $recordsTotal,
      'recordsFiltered' => $recordsFiltered,
      'data' => $data,
    ]);
  }

  // ==========================================================
  // QCM NIVEAU — DataTables serverSide (QcmAssignment + Attempt)
  // ==========================================================
  #[Route('/qcm/ajax', name: 'qcm_ajax', methods: ['POST'])]
  public function qcmAjax(Entite $entite, Request $request, EM $em): JsonResponse
  {
    $entreprise = $this->getEntrepriseUserOrFail();
    [$draw, $start, $length, $searchV, $orderColIdx, $orderDir] = $this->dtParams($request);

    $phaseFilter = (string)$request->request->get('phaseFilter', 'all'); // all|pre|post
    $stateFilter = (string)$request->request->get('stateFilter', 'all'); // all|assigned|submitted

    $map = [
      0 => 'qa.assignedAt',
      1 => 's.code',
      2 => 'u.nom',
    ];

    $qb = $em->getRepository(QcmAssignment::class)->createQueryBuilder('qa')
      ->innerJoin('qa.session', 's')->addSelect('s')
      ->innerJoin('qa.inscription', 'ins')->addSelect('ins')
      ->innerJoin('ins.stagiaire', 'u')->addSelect('u')
      ->leftJoin('qa.attempt', 'att')->addSelect('att')
      ->andWhere('qa.entite = :entite')->setParameter('entite', $entite)
      ->andWhere('ins.entreprise = :e')->setParameter('e', $entreprise);

    $recordsTotal = (int)(clone $qb)
      ->select('COUNT(DISTINCT qa.id)')
      ->resetDQLPart('orderBy')
      ->getQuery()->getSingleScalarResult();

    if ($searchV !== '') {
      $qb->andWhere('(s.code LIKE :s OR u.nom LIKE :s OR u.prenom LIKE :s)')
        ->setParameter('s', '%' . $searchV . '%');
    }

    if ($phaseFilter !== 'all') {
      try {
        $phEnum = QcmPhase::from(strtoupper($phaseFilter)); // pre|post
        $qb->andWhere('qa.phase = :ph')->setParameter('ph', $phEnum);
      } catch (\ValueError $e) {
        // ignore filtre invalide
      }
    }


    if ($stateFilter === 'submitted') {
      $qb->andWhere('qa.submittedAt IS NOT NULL');
    } elseif ($stateFilter === 'assigned') {
      $qb->andWhere('qa.submittedAt IS NULL');
    }

    $recordsFiltered = (int)(clone $qb)
      ->select('COUNT(DISTINCT qa.id)')
      ->resetDQLPart('orderBy')
      ->getQuery()->getSingleScalarResult();

    $orderBy = $map[$orderColIdx] ?? 'qa.assignedAt';

    /** @var QcmAssignment[] $rows */
    $rows = $qb->orderBy($orderBy, $orderDir)
      ->setFirstResult($start)
      ->setMaxResults($length)
      ->getQuery()->getResult();

    $data = [];
    foreach ($rows as $qa) {
      $s = $qa->getSession();
      $sessLabel = $s ? ($s->getCode() ?? ('Session #' . $s->getId())) : '—';

      $ins = $qa->getInscription();
      $u = $ins?->getStagiaire();
      $stagLabel = $u ? trim(($u->getPrenom() ?? '') . ' ' . ($u->getNom() ?? '')) : '—';

      $phase = method_exists($qa, 'getPhase') && $qa->getPhase() ? $qa->getPhase()->name : '—';

      $att = $qa->getAttempt();
      $scoreHtml = '<span class="badge bg-secondary-subtle text-secondary">—</span>';
      if ($att) {
        $scoreHtml = sprintf(
          '<span class="badge bg-success-subtle text-success">%s%%</span> <span class="text-muted small">(%d/%d)</span>',
          number_format((float)$att->getScorePercent(), 1, ',', ' '),
          (int)$att->getScorePoints(),
          (int)$att->getMaxPoints()
        );
      }


      $qcmQuestionnaireUrl = $this->generateUrl('app_entreprise_documents_qcm_questionnaire_pdf', [
        'entite' => $entite->getId(),
        'id' => $qa->getId(),
      ]);

      $qcmResultUrl = $this->generateUrl('app_entreprise_documents_qcm_result_pdf', [
        'entite' => $entite->getId(),
        'id' => $qa->getId(),
      ]);

      $btnQuestionnaire = sprintf(
        '<a class="btn btn-sm btn-outline-secondary" href="%s" target="_blank"><i class="bi bi-filetype-pdf"></i> Questionnaire</a>',
        htmlspecialchars($qcmQuestionnaireUrl, ENT_QUOTES)
      );

      $btnResult = ($qa->getAttempt() && $qa->getAttempt()->getSubmittedAt())
        ? sprintf(
          '<a class="btn btn-sm btn-outline-secondary" href="%s" target="_blank"><i class="bi bi-filetype-pdf"></i> Résultat</a>',
          htmlspecialchars($qcmResultUrl, ENT_QUOTES)
        )
        : '<span class="badge bg-secondary-subtle text-secondary"><i class="bi bi-hourglass"></i> Résultat indisponible</span>';

      $data[] = [
        'assigned' => $qa->getAssignedAt()?->format('d/m/Y') ?? '—',
        'session' => htmlspecialchars($sessLabel),
        'stagiaire' => htmlspecialchars($stagLabel ?: '—'),
        'phase' => '<span class="badge bg-light text-dark">' . htmlspecialchars($phase) . '</span>',
        'score' => $scoreHtml,
        'actions' => '<div class="d-flex gap-2 justify-content-end flex-wrap">' . $btnQuestionnaire . ' ' . $btnResult . '</div>',
      ];
    }

    return new JsonResponse([
      'draw' => $draw,
      'recordsTotal' => $recordsTotal,
      'recordsFiltered' => $recordsFiltered,
      'data' => $data,
    ]);
  }

  // ==========================================================
  // SATISFACTION STAGIAIRES — DataTables serverSide (Assignment + Attempt)
  // ==========================================================
  #[Route('/satisfaction/stagiaires/ajax', name: 'satisfaction_stagiaires_ajax', methods: ['POST'])]
  public function satisfactionStagiairesAjax(Entite $entite, Request $request, EM $em): JsonResponse
  {
    $entreprise = $this->getEntrepriseUserOrFail();
    [$draw, $start, $length, $searchV, $orderColIdx, $orderDir] = $this->dtParams($request);

    $stateFilter = (string)$request->request->get('stateFilter', 'all'); // all|submitted|pending

    $map = [
      0 => 'sa.createdAt',
      1 => 's.code',
      2 => 'u.nom',
    ];

    $qb = $em->getRepository(SatisfactionAssignment::class)->createQueryBuilder('sa')
      ->innerJoin('sa.session', 's')->addSelect('s')
      ->innerJoin('sa.stagiaire', 'u')->addSelect('u')
      ->leftJoin('sa.attempt', 'att')->addSelect('att')
      ->innerJoin('s.inscriptions', 'ins')
      ->andWhere('sa.entite = :entite')->setParameter('entite', $entite)
      ->andWhere('ins.entreprise = :e')->setParameter('e', $entreprise)
      ->andWhere('ins.stagiaire = u');

    $recordsTotal = (int)(clone $qb)
      ->select('COUNT(DISTINCT sa.id)')
      ->resetDQLPart('orderBy')
      ->getQuery()->getSingleScalarResult();

    if ($searchV !== '') {
      $qb->andWhere('(s.code LIKE :s OR u.nom LIKE :s OR u.prenom LIKE :s)')
        ->setParameter('s', '%' . $searchV . '%');
    }

    if ($stateFilter === 'submitted') {
      $qb->andWhere('att.submittedAt IS NOT NULL');
    } elseif ($stateFilter === 'pending') {
      $qb->andWhere('att.submittedAt IS NULL OR att.id IS NULL');
    }

    $recordsFiltered = (int)(clone $qb)
      ->select('COUNT(DISTINCT sa.id)')
      ->resetDQLPart('orderBy')
      ->getQuery()->getSingleScalarResult();

    $orderBy = $map[$orderColIdx] ?? 'sa.createdAt';

    /** @var SatisfactionAssignment[] $rows */
    $rows = $qb->orderBy($orderBy, $orderDir)
      ->setFirstResult($start)
      ->setMaxResults($length)
      ->getQuery()->getResult();

    $data = [];
    foreach ($rows as $sa) {
      $s = $sa->getSession();
      $sessLabel = $s ? ($s->getCode() ?? ('Session #' . $s->getId())) : '—';

      $u = $sa->getStagiaire();
      $stagLabel = $u ? trim(($u->getPrenom() ?? '') . ' ' . ($u->getNom() ?? '')) : '—';

      $att = $sa->getAttempt();
      $notes = '<span class="badge bg-secondary-subtle text-secondary">—</span>';
      if ($att instanceof SatisfactionAttempt) {
        $parts = [];
        if ($att->getNoteGlobale() !== null)  $parts[] = 'Globale: ' . (int)$att->getNoteGlobale() . '/10';
        if ($att->getNoteFormateur() !== null) $parts[] = 'Formateur: ' . (int)$att->getNoteFormateur() . '/10';
        if ($att->getNoteContenu() !== null)  $parts[] = 'Contenu: ' . (int)$att->getNoteContenu() . '/10';
        $notes = $parts
          ? '<span class="badge bg-success-subtle text-success"><i class="bi bi-star-fill"></i> ' . htmlspecialchars(implode(' • ', $parts)) . '</span>'
          : '<span class="badge bg-secondary-subtle text-secondary">—</span>';
      }

      $submitted = ($att && method_exists($att, 'getSubmittedAt')) ? $att->getSubmittedAt() : null;

      $data[] = [
        'created' => $sa->getCreatedAt()?->format('d/m/Y') ?? '—',
        'session' => htmlspecialchars($sessLabel),
        'stagiaire' => htmlspecialchars($stagLabel ?: '—'),
        'notes' => $notes,
        'status' => $this->badgeSigned($submitted !== null),
        'actions' => ($submitted !== null)
          ? '<span class="badge bg-light text-dark"><i class="bi bi-eye"></i> Voir</span>'
          : '<span class="badge bg-secondary-subtle text-secondary"><i class="bi bi-hourglass"></i> En attente</span>',
      ];
    }

    return new JsonResponse([
      'draw' => $draw,
      'recordsTotal' => $recordsTotal,
      'recordsFiltered' => $recordsFiltered,
      'data' => $data,
    ]);
  }

  // ==========================================================
  // SATISFACTION FORMATEUR — DataTables serverSide
  // ==========================================================
  #[Route('/satisfaction/formateur/ajax', name: 'satisfaction_formateur_ajax', methods: ['POST'])]
  public function satisfactionFormateurAjax(Entite $entite, Request $request, EM $em): JsonResponse
  {
    $entreprise = $this->getEntrepriseUserOrFail();
    [$draw, $start, $length, $searchV, $orderColIdx, $orderDir] = $this->dtParams($request);

    $stateFilter = (string)$request->request->get('stateFilter', 'all'); // all|submitted|pending

    $map = [
      0 => 'fsa.id',
      1 => 's.code',
    ];

    $qb = $em->getRepository(FormateurSatisfactionAssignment::class)->createQueryBuilder('fsa')
      ->innerJoin('fsa.session', 's')->addSelect('s')
      ->leftJoin('s.inscriptions', 'ins')
      ->andWhere('s.entite = :entite')->setParameter('entite', $entite)
      ->andWhere('ins.entreprise = :e')->setParameter('e', $entreprise);

    $recordsTotal = (int)(clone $qb)
      ->select('COUNT(DISTINCT fsa.id)')
      ->resetDQLPart('orderBy')
      ->getQuery()->getSingleScalarResult();

    if ($searchV !== '') {
      $qb->andWhere('(s.code LIKE :s)')
        ->setParameter('s', '%' . $searchV . '%');
    }

    // Si ton entity a un attempt ou submittedAt, on filtre.
    if ($stateFilter !== 'all') {
      if (method_exists(FormateurSatisfactionAssignment::class, 'getAttempt')) {
        // pas exploitable ici (méthode static), on laisse le filtre côté SQL basique si champs existent.
      }
    }

    $recordsFiltered = (int)(clone $qb)
      ->select('COUNT(DISTINCT fsa.id)')
      ->resetDQLPart('orderBy')
      ->getQuery()->getSingleScalarResult();

    $orderBy = $map[$orderColIdx] ?? 'fsa.id';

    /** @var FormateurSatisfactionAssignment[] $rows */
    $rows = $qb->orderBy($orderBy, $orderDir)
      ->setFirstResult($start)
      ->setMaxResults($length)
      ->getQuery()->getResult();

    $data = [];
    foreach ($rows as $fsa) {
      $s = $fsa->getSession();
      $sessLabel = $s ? ($s->getCode() ?? ('Session #' . $s->getId())) : '—';

      // Formateur depuis Session (dans ton entity Session tu as formateur)
      $formateurLabel = '—';
      if ($s && method_exists($s, 'getFormateur') && $s->getFormateur()) {
        $fo = $s->getFormateur();
        // adapte si tes getters diffèrent
        $formateurLabel = trim(($fo->getUtilisateur()->getPrenom() ?? '') . ' ' . ($fo->getUtilisateur()->getNom() ?? '')) ?: ('Formateur #' . $fo->getId());
      }

      // statut "soumis" si fsa a submittedAt, sinon si attempt->submittedAt, sinon —
      $submitted = null;
      if (method_exists($fsa, 'getCreatedAt')) $submitted = $fsa->getCreatedAt();
      if (!$submitted && method_exists($fsa, 'getAttempt') && $fsa->getAttempt() && method_exists($fsa->getAttempt(), 'getCreatedAt')) {
        $submitted = $fsa->getAttempt()->getSubmittedAt();
      }


      $created = null;
      if (method_exists($fsa, 'getCreatedAt')) $created = $fsa->getCreatedAt();

      $data[] = [
        'created' => $created ? $created->format('d/m/Y') : '—',
        'session' => htmlspecialchars($sessLabel),
        'formateur' => htmlspecialchars($formateurLabel),
        'summary' => '<span class="badge bg-light text-dark"><i class="bi bi-journal-text"></i> Rapport</span>',
        'status' => $this->badgeSigned($submitted !== null),
        'actions' => ($submitted !== null)
          ? '<span class="badge bg-light text-dark"><i class="bi bi-eye"></i> Voir</span>'
          : '<span class="badge bg-secondary-subtle text-secondary"><i class="bi bi-hourglass"></i> En attente</span>',
      ];
    }

    return new JsonResponse([
      'draw' => $draw,
      'recordsTotal' => $recordsTotal,
      'recordsFiltered' => $recordsFiltered,
      'data' => $data,
    ]);
  }

  // ==========================================================
  // ASSIDUITE — DataTables serverSide (Inscription)
  // ==========================================================
  #[Route('/assiduite/ajax', name: 'assiduite_ajax', methods: ['POST'])]
  public function assiduiteAjax(Entite $entite, Request $request, EM $em): JsonResponse
  {
    $entreprise = $this->getEntrepriseUserOrFail();
    [$draw, $start, $length, $searchV, $orderColIdx, $orderDir] = $this->dtParams($request);

    $stateFilter = (string)$request->request->get('stateFilter', 'all'); // all|ok|low|missing

    $map = [
      0 => 's.code',
      1 => 'u.nom',
    ];

    $qb = $em->getRepository(Inscription::class)->createQueryBuilder('ins')
      ->innerJoin('ins.session', 's')->addSelect('s')
      ->innerJoin('ins.stagiaire', 'u')->addSelect('u')
      ->andWhere('ins.entite = :entite')->setParameter('entite', $entite)
      ->andWhere('ins.entreprise = :e')->setParameter('e', $entreprise);

    $recordsTotal = (int)(clone $qb)
      ->select('COUNT(DISTINCT ins.id)')
      ->resetDQLPart('orderBy')
      ->getQuery()->getSingleScalarResult();

    if ($searchV !== '') {
      $qb->andWhere('(s.code LIKE :s OR u.nom LIKE :s OR u.prenom LIKE :s)')
        ->setParameter('s', '%' . $searchV . '%');
    }

    // filtre assiduité (tu ajustes les seuils si tu veux)
    if ($stateFilter === 'missing') {
      $qb->andWhere('ins.tauxAssiduite IS NULL');
    } elseif ($stateFilter === 'low') {
      $qb->andWhere('ins.tauxAssiduite IS NOT NULL')->andWhere('ins.tauxAssiduite < 80');
    } elseif ($stateFilter === 'ok') {
      $qb->andWhere('ins.tauxAssiduite IS NOT NULL')->andWhere('ins.tauxAssiduite >= 80');
    }

    $recordsFiltered = (int)(clone $qb)
      ->select('COUNT(DISTINCT ins.id)')
      ->resetDQLPart('orderBy')
      ->getQuery()->getSingleScalarResult();

    $orderBy = $map[$orderColIdx] ?? 's.code';

    /** @var Inscription[] $rows */
    $rows = $qb->orderBy($orderBy, $orderDir)
      ->setFirstResult($start)
      ->setMaxResults($length)
      ->getQuery()->getResult();

    $data = [];
    foreach ($rows as $ins) {
      $s = $ins->getSession();
      $sessLabel = $s ? ($s->getCode() ?? ('Session #' . $s->getId())) : '—';

      $u = $ins->getStagiaire();
      $stagLabel = $u ? trim(($u->getPrenom() ?? '') . ' ' . ($u->getNom() ?? '')) : '—';

      $taux = $ins->getTauxAssiduite();
      $tauxHtml = ($taux === null)
        ? '<span class="badge bg-secondary-subtle text-secondary">—</span>'
        : '<span class="badge bg-success-subtle text-success">' . number_format((float)$taux, 1, ',', ' ') . '%</span>';

      $statusHtml =
        ($taux === null)
        ? '<span class="badge bg-secondary-subtle text-secondary">Incomplète</span>'
        : (($taux < 80)
          ? '<span class="badge bg-warning-subtle text-warning">Assiduité faible</span>'
          : '<span class="badge bg-success-subtle text-success">OK</span>');

      $pdfUrl = $this->generateUrl('app_entreprise_documents_assiduite_pdf', [
        'entite' => $entite->getId(),
        'id' => $ins->getId(),
      ]);
      $convocationUrl = $this->generateUrl('app_entreprise_documents_convocation_pdf', [
        'entite' => $entite->getId(),
        'id' => $ins->getId(),
      ]);

      $attestationBtn = '';
      if (method_exists($ins, 'getAttestation') && $ins->getAttestation()) {
        $attestationUrl = $this->generateUrl('app_entreprise_documents_attestation_pdf', [
          'entite' => $entite->getId(),
          'id' => $ins->getId(),
        ]);
        $attestationBtn = sprintf(
          '<a class="btn btn-sm btn-outline-secondary" href="%s" target="_blank"><i class="bi bi-filetype-pdf"></i> Attestation</a>',
          htmlspecialchars($attestationUrl, ENT_QUOTES)
        );
      }

      $assiduiteBtn = sprintf(
        '<a class="btn btn-sm btn-outline-secondary" href="%s" target="_blank"><i class="bi bi-filetype-pdf"></i> Assiduité</a>',
        htmlspecialchars($pdfUrl, ENT_QUOTES)
      );

      $convocationBtn = sprintf(
        '<a class="btn btn-sm btn-outline-secondary" href="%s" target="_blank"><i class="bi bi-filetype-pdf"></i> Convocation</a>',
        htmlspecialchars($convocationUrl, ENT_QUOTES)
      );



      $data[] = [
        'session' => htmlspecialchars($sessLabel),
        'stagiaire' => htmlspecialchars($stagLabel ?: '—'),
        'taux' => $tauxHtml,
        'status' => $statusHtml,
        'actions' => '<div class="d-flex gap-2 justify-content-end flex-wrap">'
          . $assiduiteBtn . ' ' . $convocationBtn . ' ' . $attestationBtn .
          '</div>',
      ];
    }

    return new JsonResponse([
      'draw' => $draw,
      'recordsTotal' => $recordsTotal,
      'recordsFiltered' => $recordsFiltered,
      'data' => $data,
    ]);
  }

  // ==========================================================
  // PDF sécurisé entreprise (proxy)
  // ==========================================================
  #[Route('/devis/{id}/pdf', name: 'devis_pdf', methods: ['GET'], requirements: ['id' => '\d+'])]
  public function devisPdf(Entite $entite, Devis $devis): Response
  {
    $entreprise = $this->getEntrepriseUserOrFail();

    if (!$this->pdf) throw $this->createNotFoundException('Service PDF non disponible.');
    if ($devis->getEntite()?->getId() !== $entite->getId()) throw $this->createAccessDeniedException();
    if ($devis->getEntrepriseDestinataire()?->getId() !== $entreprise->getId()) throw $this->createAccessDeniedException();

    $html = $this->renderView('pdf/devis.html.twig', [
      'entite' => $entite,
      'devis' => $devis,
    ]);

    $fileName = sprintf('DEVIS-%s', $devis->getNumero() ?: $devis->getId());
    return $this->pdf->createPortrait($html, $fileName);
  }

  #[Route('/facture/{id}/pdf', name: 'facture_pdf', methods: ['GET'], requirements: ['id' => '\d+'])]
  public function facturePdf(Entite $entite, Facture $facture): Response
  {
    $entreprise = $this->getEntrepriseUserOrFail();

    if (!$this->pdf) throw $this->createNotFoundException('Service PDF non disponible.');
    if ($facture->getEntite()?->getId() !== $entite->getId()) throw $this->createAccessDeniedException();
    if ($facture->getEntrepriseDestinataire()?->getId() !== $entreprise->getId()) throw $this->createAccessDeniedException();

    $html = $this->renderView('pdf/facture.html.twig', [
      'entite'  => $entite,
      'facture' => $facture,
    ]);

    $fileName = sprintf('FACTURE_%s', $facture->getNumero() ?: $facture->getId());
    return $this->pdf->createPortrait($html, $fileName);
  }

  // ==========================================================
  // PDF Convention/Contrat sécurisé (proxy)
  // ==========================================================
  #[Route('/convention/{id}/pdf', name: 'convention_pdf', methods: ['GET'], requirements: ['id' => '\d+'])]
  public function conventionPdf(Entite $entite, ConventionContrat $cc, EM $em): Response
  {
    $entreprise = $this->getEntrepriseUserOrFail();

    if (!$this->pdf) throw $this->createNotFoundException('Service PDF non disponible.');
    if ($cc->getEntite()?->getId() !== $entite->getId()) throw $this->createAccessDeniedException();

    // sécurité entreprise : soit cc.entreprise, soit via inscriptions liées
    $allowed = false;
    if ($cc->getEntreprise()?->getId() === $entreprise->getId()) {
      $allowed = true;
    } else {
      foreach ($cc->getInscriptions() as $ins) {
        if ($ins->getEntreprise()?->getId() === $entreprise->getId()) {
          $allowed = true;
          break;
        }
      }
    }
    if (!$allowed) throw $this->createAccessDeniedException();

    $html = $this->renderView('pdf/convention_contrat.html.twig', [
      'entite' => $entite,
      'convention' => $cc,
      'conventionContrat' => $cc, // au cas où ton template attend l’un ou l’autre
      'session' => $cc->getSession(),
    ]);

    $fileName = sprintf('Convention-%s', $cc->getNumero() ?: $cc->getId());
    return $this->pdf->createPortrait($html, $fileName);
  }

  // ==========================================================
  // PDF Fiche assiduité sécurisé (proxy) — Inscription
  // ==========================================================
  #[Route('/assiduite/{id}/pdf', name: 'assiduite_pdf', methods: ['GET'], requirements: ['id' => '\d+'])]
  public function assiduitePdf(Entite $entite, Inscription $inscription): Response
  {
    $entreprise = $this->getEntrepriseUserOrFail();

    if (!$this->pdf) throw $this->createNotFoundException('Service PDF non disponible.');
    if ($inscription->getEntite()?->getId() !== $entite->getId()) throw $this->createAccessDeniedException();
    if ($inscription->getEntreprise()?->getId() !== $entreprise->getId()) throw $this->createAccessDeniedException();

    $html = $this->renderView('pdf/attestation.html.twig', [
      'entite' => $entite,
      'inscription' => $inscription,
      'session' => $inscription->getSession(),
      'stagiaire' => $inscription->getStagiaire(),
      'preferences' => $entite->getPreferences(),
    ]);

    $fileName = sprintf(
      'Assiduite-%s-%s',
      $inscription->getSession()?->getCode() ?? $inscription->getSession()?->getId(),
      $inscription->getStagiaire()?->getId() ?? $inscription->getId()
    );
    return $this->pdf->createPortrait($html, $fileName);
  }

  // ==========================================================
  // Badges HTML
  // ==========================================================
  private function statusBadgeDevis(?DevisStatus $st): string
  {
    if (!$st) return '<span class="badge bg-secondary-subtle text-secondary">—</span>';

    return match ($st) {
      DevisStatus::DRAFT    => '<span class="badge bg-secondary-subtle text-secondary">Brouillon</span>',
      DevisStatus::SENT     => '<span class="badge bg-info-subtle text-info">Envoyé</span>',
      DevisStatus::ACCEPTED => '<span class="badge bg-success-subtle text-success">Accepté</span>',
      DevisStatus::INVOICED => '<span class="badge bg-primary-subtle text-primary">Facturé</span>',
      DevisStatus::CANCELED => '<span class="badge bg-danger-subtle text-danger">Annulé</span>',
    };
  }

  private function statusBadgeFacture(?FactureStatus $st): string
  {
    if (!$st) return '<span class="badge bg-secondary-subtle text-secondary">—</span>';

    return match ($st) {
      FactureStatus::DUE      => '<span class="badge bg-warning-subtle text-warning">À payer</span>',
      FactureStatus::PAID     => '<span class="badge bg-success-subtle text-success">Payée</span>',
      FactureStatus::CANCELED => '<span class="badge bg-danger-subtle text-danger">Annulée</span>',
    };
  }

  private function badgeSigned(bool $ok): string
  {
    return $ok
      ? '<span class="badge bg-success-subtle text-success"><i class="bi bi-check2-circle"></i> Soumis</span>'
      : '<span class="badge bg-secondary-subtle text-secondary"><i class="bi bi-hourglass"></i> En attente</span>';
  }

  private function badgeSignaturesConvention(ConventionContrat $cc): string
  {
    $stagOk = ($cc->getDateSignatureStagiaire() !== null) || ((string)($cc->getSignatureDataUrlStagiaire() ?? '') !== '');
    $entOk  = ($cc->getDateSignatureEntreprise() !== null) || ((string)($cc->getSignatureDataUrlEntreprise() ?? '') !== '');
    $ofOk   = ($cc->getDateSignatureOf() !== null);

    $html = '<div class="d-flex flex-wrap gap-1">';
    $html .= $stagOk ? '<span class="badge bg-success-subtle text-success">Stagiaire</span>' : '<span class="badge bg-secondary-subtle text-secondary">Stagiaire</span>';
    $html .= $entOk  ? '<span class="badge bg-success-subtle text-success">Entreprise</span>' : '<span class="badge bg-secondary-subtle text-secondary">Entreprise</span>';
    $html .= $ofOk   ? '<span class="badge bg-success-subtle text-success">OF</span>' : '<span class="badge bg-secondary-subtle text-secondary">OF</span>';
    $html .= '</div>';

    return $html;
  }


  #[Route('/convocation/{id}/pdf', name: 'convocation_pdf', methods: ['GET'], requirements: ['id' => '\d+'])]
  public function convocationPdf(Entite $entite, Inscription $inscription): Response
  {
    $entreprise = $this->getEntrepriseUserOrFail();

    if (!$this->pdf) throw $this->createNotFoundException('Service PDF non disponible.');
    if ($inscription->getEntite()?->getId() !== $entite->getId()) throw $this->createAccessDeniedException();
    if ($inscription->getEntreprise()?->getId() !== $entreprise->getId()) throw $this->createAccessDeniedException();

    $html = $this->renderView('pdf/convocation.html.twig', [
      'entite' => $entite,
      'inscription' => $inscription,
      'session' => $inscription->getSession(),
      'stagiaire' => $inscription->getStagiaire(),
      'entreprise' => $entreprise,
    ]);

    $fileName = sprintf('Convocation-%s', $inscription->getId());
    return $this->pdf->createPortrait($html, $fileName);
  }



  #[Route('/attestation/{id}/pdf', name: 'attestation_pdf', methods: ['GET'], requirements: ['id' => '\d+'])]
  public function attestationPdf(Entite $entite, Inscription $inscription): Response
  {
    $entreprise = $this->getEntrepriseUserOrFail();

    if (!$this->pdf) throw $this->createNotFoundException('Service PDF non disponible.');
    if ($inscription->getEntite()?->getId() !== $entite->getId()) throw $this->createAccessDeniedException();
    if ($inscription->getEntreprise()?->getId() !== $entreprise->getId()) throw $this->createAccessDeniedException();

    if (!$inscription->getAttestation()) {
      throw $this->createNotFoundException('Aucune attestation disponible.');
    }

    $html = $this->renderView('pdf/attestation.html.twig', [
      'entite' => $entite,
      'inscription' => $inscription,
      'attestation' => $inscription->getAttestation(),
      'session' => $inscription->getSession(),
      'stagiaire' => $inscription->getStagiaire(),
      'entreprise' => $entreprise,
    ]);

    $fileName = sprintf('Attestation-%s', $inscription->getId());
    return $this->pdf->createPortrait($html, $fileName);
  }


  #[Route('/emargement/session/{id}/pdf', name: 'emargement_sheet_pdf', methods: ['GET'], requirements: ['id' => '\d+'])]
  public function emargementSheetPdf(Entite $entite, Session $session, Request $request, EM $em): Response
  {
    $entreprise = $this->getEntrepriseUserOrFail();

    if (!$this->pdf) throw $this->createNotFoundException('Service PDF non disponible.');
    if ($session->getEntite()?->getId() !== $entite->getId()) throw $this->createAccessDeniedException();

    // ✅ sécurité : la session doit concerner AU MOINS une inscription de cette entreprise
    $ok = false;
    foreach ($session->getInscriptions() as $ins) {
      if ($ins->getEntreprise()?->getId() === $entreprise->getId()) {
        $ok = true;
        break;
      }
    }
    if (!$ok) throw $this->createAccessDeniedException();

    // --- Formateur session
    $formateur     = $session->getFormateur();
    $trainerUser   = $formateur?->getUtilisateur();
    $trainerUserId = $trainerUser?->getId();
    $trainerName   = $trainerUser
      ? trim(($trainerUser->getPrenom() ?? '') . ' ' . ($trainerUser->getNom() ?? ''))
      : null;

    // --- Liste des jours
    $joursCol  = $session->getJours();
    $joursList = [];
    $dateIndex = [];

    foreach ($joursCol as $j) {
      $dYmd = $j->getDateDebut()->format('Y-m-d');
      $joursList[] = [
        'ymd'   => $dYmd,
        'label' => $j->getDateDebut()->format('d/m/Y'),
        'debut' => $j->getDateDebut()->format('H:i'),
        'fin'   => $j->getDateFin()->format('H:i'),
      ];
      $dateIndex[$dYmd] = end($joursList);
    }

    // Bornes globales
    [$minDebut, $maxFin] = $this->computeGlobalBounds($joursCol);

    $allDates = array_keys($dateIndex);
    if (empty($allDates)) {
      throw $this->createNotFoundException('Aucun jour associé à cette session.');
    }

    // --- Charger émargements (filtrés sur entreprise : seulement stagiaires de l’entreprise + formateur si présent)
    $stagiairesIds = [];
    foreach ($session->getInscriptions() as $ins) {
      if ($ins->getEntreprise()?->getId() === $entreprise->getId() && $ins->getStagiaire()) {
        $stagiairesIds[] = $ins->getStagiaire()->getId();
      }
    }
    $stagiairesIds = array_values(array_unique(array_filter($stagiairesIds)));

    $emargementsQb = $em->getRepository(Emargement::class)->createQueryBuilder('e')
      ->andWhere('e.session = :s')
      ->andWhere('e.dateJour IN (:ds)')
      ->setParameter('s', $session)
      ->setParameter('ds', $allDates);

    // on limite aux stagiaires de l’entreprise (+ le formateur s’il existe)
    if (!empty($stagiairesIds) || $trainerUserId) {
      $ids = $stagiairesIds;
      if ($trainerUserId) $ids[] = $trainerUserId;
      $ids = array_values(array_unique($ids));

      $emargementsQb
        ->innerJoin('e.utilisateur', 'uu')
        ->andWhere('uu.id IN (:uids)')
        ->setParameter('uids', $ids);
    }

    $emargements = $emargementsQb->getQuery()->getResult();

    // --- Regrouper par jour + utilisateur
    $linesByDate = [];
    foreach ($allDates as $d) {
      $linesByDate[$d] = [];
    }

    foreach ($emargements as $e) {
      $u    = $e->getUtilisateur();
      $uid  = $u->getId();
      $dYmd = $e->getDateJour()->format('Y-m-d');

      if (!isset($linesByDate[$dYmd][$uid])) {
        $linesByDate[$dYmd][$uid] = [
          'id'        => $uid,
          'isTrainer' => $trainerUserId !== null && $uid === $trainerUserId,
          'name'      => trim(($u->getPrenom() ?? '') . ' ' . ($u->getNom() ?? '')),
          'raisonSociale' => $u?->getEntreprise()->getRaisonSociale() ?? '—',
          'naissance' => $u->getDateNaissance()?->format('d/m/Y') ?: '—',
          'am'        => ['signed' => false, 'img' => null, 'at' => null],
          'pm'        => ['signed' => false, 'img' => null, 'at' => null],
        ];
      }

      $col = ($e->getPeriode() === DemiJournee::PM) ? 'pm' : 'am';

      $img = null;
      if ($e->getSignatureDataUrl()) {
        $img = $e->getSignatureDataUrl();
      } elseif ($e->getSignaturePath()) {
        $img = $this->signaturePathToRenderable($request, (string) $e->getSignaturePath());
      }

      if ($img) {
        $linesByDate[$dYmd][$uid][$col] = [
          'signed' => true,
          'img'    => $img,
          'at'     => $e->getSignedAt()?->format('d/m/Y H:i'),
        ];
      }
    }

    // --- Tri (stagiaires alphabétiques, formateur en dernier)
    foreach ($linesByDate as $d => $map) {
      $arr = array_values($map);

      usort($arr, static function (array $a, array $b): int {
        if ($a['isTrainer'] === $b['isTrainer']) {
          return strcasecmp($a['name'], $b['name']);
        }
        return $a['isTrainer'] ? 1 : -1;
      });

      $linesByDate[$d] = $arr;
    }

    // Adresse
    $site = $session->getSite();
    $adresse = trim(
      ($site?->getNom() ?: '') . ' - ' .
        ($site?->getAdresse() ?: '') . ' ' .
        ($site?->getComplement() ?: '') . ' - ' .
        ($site?->getCodePostal() ?: '') . ' ' .
        ($site?->getVille() ?: '') . ' - ' .
        ($site?->getPays() ?: '')
    );

    // --- meta attendu par le template
    $meta = [
      'entite'        => $entite,
      'titre'         => $session->getFormation()?->getTitre() ?? 'Formation',
      'code'          => (string) ($session->getCode() ?? ''),
      'site'          => $session->getSite()?->getNom() ?? null,
      'adresse'       => $adresse,
      'dateDebut'     => $minDebut?->format('d/m/Y'),
      'dateFin'       => $maxFin?->format('d/m/Y'),
      'jours'         => count($joursList),
      'joursList'     => $joursList,
      'formateurName' => $trainerName,
      'formateurId'   => $trainerUserId,
      'entreprise'    => $entreprise, // pratique si ton twig en a besoin
    ];

    $html = $this->renderView('pdf/emargement_sheet.html.twig', [
      'meta'        => $meta,
      'linesByDate' => $linesByDate,
      'preferences' => $entite->getPreferences(),
    ]);

    $fileName = sprintf('EMARGEMENT_%s', $meta['code'] ?: $session->getId());

    if (method_exists($this->pdf, 'createLandscape')) {
      return $this->pdf->createLandscape($html, $fileName);
    }
    return $this->pdf->createPortrait($html, $fileName);
  }



  #[Route('/qcm/{id}/questionnaire/pdf', name: 'qcm_questionnaire_pdf', methods: ['GET'], requirements: ['id' => '\d+'])]
  public function qcmQuestionnairePdf(Entite $entite, QcmAssignment $qa, EM $em): Response
  {
    $entreprise = $this->getEntrepriseUserOrFail();

    if (!$this->pdf) {
      throw $this->createNotFoundException('Service PDF non disponible.');
    }

    // Sécurité entité
    if ($qa->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createAccessDeniedException();
    }

    // Sécurité entreprise (via inscription)
    $ins = $qa->getInscription();
    if (!$ins || $ins->getEntreprise()?->getId() !== $entreprise->getId()) {
      throw $this->createAccessDeniedException();
    }

    $session = $qa->getSession();
    $qcm     = $qa->getQcm();

    if (!$session || !$qcm) {
      throw $this->createNotFoundException();
    }

    // (Optionnel mais “propre”) : vérifie que l’assignation correspond bien à session/qcm/phase
    // Ici, qa EST déjà l’assignation, donc c’est plutôt une sécurité anti-données incohérentes.
    $phaseStr = '—';
    if (method_exists($qa, 'getPhase') && $qa->getPhase()) {
      // Enum -> string lisible
      $phaseStr = $qa->getPhase()->name; // ou ->value selon ton Enum
    }

    // Questions (ordonnées via mapping)
    $questions = $qcm->getQuestions();

    $html = $this->renderView('pdf/qcm_questionnaire.html.twig', [
      'entite'      => $entite,
      'session'     => $session,
      'qcm'         => $qcm,
      'phase'       => $phaseStr,
      'questions'   => $questions,
      'generatedAt' => new \DateTimeImmutable(),

      // (optionnel) si tu veux les garder pour plus tard :
      'assignment'  => $qa,
      'inscription' => $ins,
      'stagiaire'   => $ins->getStagiaire(),
      'entreprise'  => $entreprise,
    ]);

    $fileName = sprintf(
      'QCM_%s_%s_%s',
      $session->getCode() ?: $session->getId(),
      $phaseStr ?: 'PHASE',
      $qcm->getId()
    );

    return $this->pdf->createPortrait($html, $fileName);
  }



  #[Route('/qcm/{id}/result/pdf', name: 'qcm_result_pdf', methods: ['GET'], requirements: ['id' => '\d+'])]
  public function qcmResultPdf(Entite $entite, QcmAssignment $qa): Response
  {
    $entreprise = $this->getEntrepriseUserOrFail();

    if (!$this->pdf) throw $this->createNotFoundException('Service PDF non disponible.');
    if ($qa->getEntite()?->getId() !== $entite->getId()) throw $this->createAccessDeniedException();

    $ins = $qa->getInscription();
    if (!$ins || $ins->getEntreprise()?->getId() !== $entreprise->getId()) {
      throw $this->createAccessDeniedException();
    }

    $session = $qa->getSession();
    if (!$session) throw $this->createNotFoundException();

    $attempt = $qa->getAttempt();
    if (!$attempt || !$attempt->getSubmittedAt()) {
      throw $this->createNotFoundException('Aucun résultat (tentative non soumise).');
    }

    $qcm = $qa->getQcm();
    if (!$qcm) throw $this->createNotFoundException();

    $stagiaire = $ins->getStagiaire();

    // construire items (question + choices + selected + correct)
    $items = [];
    foreach ($qcm->getQuestions() as $q) {
      $choices = $q->getOptions();

      // retrouver réponse liée à cette question
      $ans = null;
      foreach ($attempt->getAnswers() as $a) {
        if ($a->getQuestion()?->getId() === $q->getId()) {
          $ans = $a;
          break;
        }
      }

      $selectedIds = $ans ? $ans->getSelectedOptionIds() : [];
      $correctIds  = $q->getCorrectOptionIds();

      // “isCorrect” simple : ids identiques (⚠️ si ordre différent chez toi, je te mets la version robuste plus bas)
      $sel = $selectedIds;
      $cor = $correctIds;
      sort($sel);
      sort($cor);
      $isCorrect = ($sel === $cor);


      $items[] = [
        'question'   => $q,
        'choices'    => $choices,
        'selected'   => $selectedIds,
        'correctIds' => $correctIds,
        'isCorrect'  => $isCorrect,
      ];
    }

    $html = $this->renderView('pdf/qcm_result.html.twig', [
      'entite'      => $entite,
      'session'     => $session,
      'qcm'         => $qcm,

      // ✅ ton twig utilise "a" (phase) ET "assignment" (footnote)
      'a'           => $qa,
      'assignment'  => $qa,

      'attempt'     => $attempt,
      'stagiaire'   => $stagiaire,
      'items'       => $items,

      'scorePoints' => $attempt->getScorePoints(),
      'maxPoints'   => $attempt->getMaxPoints(),
      'pct'         => (int) round($attempt->getScorePercent()),
      'generatedAt' => new \DateTimeImmutable(),
    ]);

    $fileName = sprintf('QCM-Resultat-%s', $qa->getId());
    return $this->pdf->createPortrait($html, $fileName);
  }



  private function computeGlobalBounds($jours): array
  {
    $min = null;
    $max = null;
    foreach ($jours as $j) {
      $d1 = $j->getDateDebut();
      $d2 = $j->getDateFin();
      if ($d1 && (!$min || $d1 < $min)) $min = $d1;
      if ($d2 && (!$max || $d2 > $max)) $max = $d2;
    }
    return [$min, $max];
  }

  private function signaturePathToRenderable(Request $request, string $path): ?string
  {
    $trim = trim($path);
    if ($trim === '') return null;

    if (preg_match('~^https?://~i', $trim)) {
      return $trim;
    }

    $publicRoot = $this->getParameter('kernel.project_dir') . '/public';
    $full = $publicRoot . '/' . ltrim($trim, '/');

    if (is_file($full) && is_readable($full)) {
      $mime = $this->guessImageMime($full);
      $data = @file_get_contents($full);
      if ($data !== false) {
        return 'data:' . $mime . ';base64,' . base64_encode($data);
      }
    }

    return $request->getSchemeAndHttpHost() . '/' . ltrim($trim, '/');
  }

  private function guessImageMime(string $fullPath): string
  {
    return match (strtolower(pathinfo($fullPath, PATHINFO_EXTENSION))) {
      'jpg', 'jpeg' => 'image/jpeg',
      'gif'         => 'image/gif',
      'webp'        => 'image/webp',
      default       => 'image/png'
    };
  }
}
