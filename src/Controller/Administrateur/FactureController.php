<?php

namespace App\Controller\Administrateur;

use App\Entity\{Facture, Entreprise, Utilisateur, EmailLog, Entite};
use App\Form\Administrateur\{FactureType};
use App\Enum\FactureStatus;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\Service\Sequence\FactureNumberGenerator;
use App\Service\Pdf\PdfManager;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use App\Security\Permission\TenantPermission;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Service\Billing\InscriptionBillingSync;






#[Route('/administrateur/{entite}/facture', name: 'app_administrateur_facture_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::FACTURE_MANAGE, subject: 'entite')]
class FactureController extends AbstractController
{
  public function __construct(
    private UtilisateurEntiteManager $utilisateurEntiteManager,
    #[Autowire('%upload_proofs_dir%')] private string $proofDir,
    private readonly InscriptionBillingSync $inscSync, // ✅
    private ?PdfManager $pdf = null,
  ) {}

  #[Route('', name: 'index', methods: ['GET'])]
  public function factures(Entite $entite, EM $em): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    // ✅ Destinataires (Utilisateurs) présents sur des factures de l'entité
    $payeurUsers = $em->createQueryBuilder()
      ->select('DISTINCT u')
      ->from(Utilisateur::class, 'u')
      ->innerJoin(Facture::class, 'f', 'WITH', 'f.destinataire = u')
      ->andWhere('f.entite = :e')
      ->setParameter('e', $entite)
      ->orderBy('u.nom', 'ASC')
      ->addOrderBy('u.prenom', 'ASC')
      ->getQuery()
      ->getResult();


    // ✅ Destinataires (Entreprises) présents sur des factures de l'entité
    $payeurEntreprises = $em->createQueryBuilder()
      ->select('DISTINCT e')
      ->from(Entreprise::class, 'e')
      ->innerJoin(Facture::class, 'f', 'WITH', 'f.entrepriseDestinataire = e')
      ->andWhere('f.entite = :e')
      ->setParameter('e', $entite)
      ->orderBy('e.raisonSociale', 'ASC')
      ->getQuery()
      ->getResult();


    return $this->render('administrateur/facture/index.html.twig', [
      'entite' => $entite,
      'payeurUsers' => $payeurUsers,
      'payeurEntreprises' => $payeurEntreprises,

    ]);
  }

  #[Route('/ajax', name: 'ajax', methods: ['POST'])]
  public function facturesAjax(Entite $entite, Request $request, EM $em): JsonResponse
  {
    $conn = $em->getConnection();

    // =========================
    // DataTables inputs
    // =========================
    $draw   = $request->request->getInt('draw', 1);
    $start  = max(0, $request->request->getInt('start', 0));
    $length = $request->request->getInt('length', 10);
    if ($length <= 0) $length = 10;

    $search   = $request->request->all('search');
    $searchV  = trim((string)($search['value'] ?? ''));

    $order = $request->request->all('order') ?? [];
    $colIdx = isset($order[0]['column']) ? (int)$order[0]['column'] : 3;
    $dir    = (isset($order[0]['dir']) && strtolower((string)$order[0]['dir']) === 'asc') ? 'ASC' : 'DESC';

    $statusFilter = (string)$request->request->get('statusFilter', 'all');

    $periodType  = (string)$request->request->get('periodType', 'all');
    $yearFilter  = (string)$request->request->get('yearFilter', 'all');
    $monthFilter = (string)$request->request->get('monthFilter', 'all');
    $quarterFilter = (string)$request->request->get('quarterFilter', 'all');

    $payeurUserIds = $request->request->all('payeurUserIds') ?? [];
    $payeurEntrepriseIds = $request->request->all('payeurEntrepriseIds') ?? [];


    // =========================
    // Expressions (Règle voulue)
    // HT = somme hors débours (facture.montant_ht_cents)
    // TVA = somme hors débours (facture.montant_tva_cents)
    // Débours = somme des lignes débours en TTC (HT net + TVA ligne)
    // TTC total = HT + TVA + Débours
    // remaining/paid/overpaid basés sur TTC total
    // =========================
    $paidExpr    = 'COALESCE(p.paid_cents,0)';
    $htExpr  = 'COALESCE(hd.ht_hd_cents,0)';   // ✅ HT hors débours
    $tvaExpr = 'COALESCE(hd.tva_hd_cents,0)';  // ✅ TVA hors débours
    $deboursExpr = 'COALESCE(deb.debours_ttc_cents,0)';   // affichage seulement

    // ✅ IMPORTANT : ne pas rajouter les débours au TTC
    $ttcExpr = '(' . $htExpr . ' + ' . $tvaExpr . ' + ' . $deboursExpr . ')';
    $remainingExpr = 'GREATEST(0, ' . $ttcExpr . ' - ' . $paidExpr . ')';
    $overpaidExpr  = '(' . $paidExpr . ' - ' . $ttcExpr . ')';


    // =========================
    // ORDER BY mapping
    // =========================
    $orderBy = match ($colIdx) {
      0  => 'f.numero',
      1  => 'dest_sort',
      2  => 'f.date_emission',
      3  => 'ht_cents',
      4  => 'tva_cents',
      5  => 'debours_ttc_cents',
      6  => 'ttc_total_cents',
      7  => 'paid_cents',
      8  => 'remaining_cents',
      9 => 'f.status',
      default => 'f.date_emission',
    };

    // =========================
    // WHERE + params
    // =========================
    $where  = ['f.entite_id = :entiteId'];
    $params = ['entiteId' => $entite->getId()];
    $types  = ['entiteId' => ParameterType::INTEGER];

    // ===== Filtres destinataires =====
    $payeurUserIds = array_values(array_filter(array_map('intval', (array)$payeurUserIds)));
    $payeurEntrepriseIds = array_values(array_filter(array_map('intval', (array)$payeurEntrepriseIds)));


    if (!empty($payeurUserIds) || !empty($payeurEntrepriseIds)) {
      $or = [];
      if (!empty($payeurUserIds)) {
        $or[] = 'f.destinataire_id IN (:payeurUserIds)';
        $params['payeurUserIds'] = $payeurUserIds;
        $types['payeurUserIds'] = class_exists(ArrayParameterType::class)
          ? ArrayParameterType::INTEGER
          : (defined(Connection::class . '::PARAM_INT_ARRAY') ? constant(Connection::class . '::PARAM_INT_ARRAY') : null);
      }
      if (!empty($payeurEntrepriseIds)) {
        $or[] = 'f.entreprise_destinataire_id IN (:payeurEntrepriseIds)';
        $params['payeurEntrepriseIds'] = $payeurEntrepriseIds;

        $types['payeurEntrepriseIds'] = class_exists(ArrayParameterType::class)
          ? ArrayParameterType::INTEGER
          : (defined(Connection::class . '::PARAM_INT_ARRAY') ? constant(Connection::class . '::PARAM_INT_ARRAY') : null);
      }
      $where[] = '(' . implode(' OR ', $or) . ')';
    }

    // ===== Filtre période sur f.date_emission =====
    if ($periodType !== 'all') {
      if ($periodType === 'year' && $yearFilter !== 'all') {
        $where[] = 'YEAR(f.date_emission) = :yf';
        $params['yf'] = (int)$yearFilter;
        $types['yf'] = ParameterType::INTEGER;
      }

      if ($periodType === 'month') {
        if ($yearFilter !== 'all') {
          $where[] = 'YEAR(f.date_emission) = :yf';
          $params['yf'] = (int)$yearFilter;
          $types['yf'] = ParameterType::INTEGER;
        }
        if ($monthFilter !== 'all') {
          $where[] = 'MONTH(f.date_emission) = :mf';
          $params['mf'] = (int)$monthFilter;
          $types['mf'] = ParameterType::INTEGER;
        }
      }

      if ($periodType === 'quarter') {
        if ($yearFilter !== 'all') {
          $where[] = 'YEAR(f.date_emission) = :yf';
          $params['yf'] = (int)$yearFilter;
          $types['yf'] = ParameterType::INTEGER;
        }
        if ($quarterFilter !== 'all') {
          $where[] = 'QUARTER(f.date_emission) = :qf';
          $params['qf'] = (int)$quarterFilter;
          $types['qf'] = ParameterType::INTEGER;
        }
      }
    }


    if ($searchV !== '') {
      $where[] = '(
        f.numero LIKE :s
        OR f.note LIKE :s
        OR u.email LIKE :s OR u.nom LIKE :s OR u.prenom LIKE :s
        OR e.email LIKE :s OR e.email_facturation LIKE :s OR e.raison_sociale LIKE :s
      )';
      $params['s'] = '%' . $searchV . '%';
      $types['s']  = ParameterType::STRING;
    }

    $stCanceled = FactureStatus::CANCELED->value;

    if ($statusFilter === 'canceled') {
      $where[] = 'f.status = :stCanceled';
      $params['stCanceled'] = $stCanceled;
      $types['stCanceled']  = ParameterType::STRING;
    } elseif (in_array($statusFilter, ['paid', 'partial', 'due'], true)) {
      $where[] = 'f.status != :stCanceled';
      $params['stCanceled'] = $stCanceled;
      $types['stCanceled']  = ParameterType::STRING;

      if ($statusFilter === 'paid') {
        $where[] = $remainingExpr . ' = 0';
      } elseif ($statusFilter === 'partial') {
        $where[] = $paidExpr . ' > 0 AND ' . $remainingExpr . ' > 0';
      } elseif ($statusFilter === 'due') {
        $where[] = $paidExpr . ' = 0 AND ' . $remainingExpr . ' > 0';
      }
    } elseif ($statusFilter === 'overpaid') {
      $where[] = 'f.status != :stCanceled';
      $params['stCanceled'] = $stCanceled;
      $types['stCanceled']  = ParameterType::STRING;

      $where[] = $overpaidExpr . ' > 0';
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    // =========================
    // recordsTotal (sans filtres)
    // =========================
    $recordsTotal = (int)$conn->fetchOne(
      'SELECT COUNT(*) FROM facture f WHERE f.entite_id = :entiteId',
      ['entiteId' => $entite->getId()],
      ['entiteId' => ParameterType::INTEGER]
    );

    // =========================
    // Sous-requêtes (paiements + débours TTC)
    // =========================
    $paidSub = "
        SELECT facture_id, COALESCE(SUM(montant_cents),0) AS paid_cents
        FROM paiement
        GROUP BY facture_id
    ";

    // Débours TTC = SUM(HT net + TVA ligne) pour is_debours=1
    $deboursSub = "
        SELECT
            facture_id,
            COALESCE(SUM(
                (
                    GREATEST(
                        0,
                        (qte * pu_ht_cents)
                        - CASE
                            WHEN COALESCE(remise_montant_cents, 0) > 0
                              THEN LEAST(remise_montant_cents, (qte * pu_ht_cents))
                            WHEN COALESCE(remise_pourcent, 0) > 0
                              THEN ROUND((qte * pu_ht_cents) * (remise_pourcent / 100))
                            ELSE 0
                          END
                    )
                )
                +
                ROUND(
                    GREATEST(
                        0,
                        (qte * pu_ht_cents)
                        - CASE
                            WHEN COALESCE(remise_montant_cents, 0) > 0
                              THEN LEAST(remise_montant_cents, (qte * pu_ht_cents))
                            WHEN COALESCE(remise_pourcent, 0) > 0
                              THEN ROUND((qte * pu_ht_cents) * (remise_pourcent / 100))
                            ELSE 0
                          END
                    )
                    * (COALESCE(tva_bp, 0) / 10000)
                )
            ),0) AS debours_ttc_cents
        FROM ligne_facture
        WHERE is_debours = 1
        GROUP BY facture_id
    ";


    // Hors débours : HT net + TVA ligne uniquement sur is_debours = 0
    $horsDeboursSub = "
      SELECT
          facture_id,
          COALESCE(SUM(
              GREATEST(
                  0,
                  (qte * pu_ht_cents)
                  - CASE
                      WHEN COALESCE(remise_montant_cents, 0) > 0
                        THEN LEAST(remise_montant_cents, (qte * pu_ht_cents))
                      WHEN COALESCE(remise_pourcent, 0) > 0
                        THEN ROUND((qte * pu_ht_cents) * (remise_pourcent / 100))
                      ELSE 0
                    END
              )
          ),0) AS ht_hd_cents,

          COALESCE(SUM(
              ROUND(
                  GREATEST(
                      0,
                      (qte * pu_ht_cents)
                      - CASE
                          WHEN COALESCE(remise_montant_cents, 0) > 0
                            THEN LEAST(remise_montant_cents, (qte * pu_ht_cents))
                          WHEN COALESCE(remise_pourcent, 0) > 0
                            THEN ROUND((qte * pu_ht_cents) * (remise_pourcent / 100))
                          ELSE 0
                        END
                  )
                  * (COALESCE(tva_bp, 0) / 10000)
              )
          ),0) AS tva_hd_cents
      FROM ligne_facture
      WHERE COALESCE(is_debours,0) = 0
      GROUP BY facture_id
  ";


    // =========================
    // recordsFiltered (✅ COUNT DISTINCT, pas de GROUP BY)
    // =========================
    $countSql = "
        SELECT COUNT(DISTINCT f.id)
        FROM facture f
        LEFT JOIN utilisateur u ON u.id = f.destinataire_id
        LEFT JOIN entreprise e  ON e.id = f.entreprise_destinataire_id
        LEFT JOIN ($paidSub) p ON p.facture_id = f.id
        LEFT JOIN ($deboursSub) deb ON deb.facture_id = f.id
        LEFT JOIN ($horsDeboursSub) hd ON hd.facture_id = f.id

        $whereSql
    ";
    $recordsFiltered = (int)$conn->fetchOne($countSql, $params, $types);

    // =========================
    // Requête principale paginée (✅ pas de GROUP BY)
    // =========================
    $sql = "
        SELECT
            f.id,
            f.numero,
            f.note,
            f.date_emission,
            f.status,

            $htExpr  AS ht_cents,
            $tvaExpr AS tva_cents,
            $deboursExpr AS debours_ttc_cents,

            $ttcExpr AS ttc_total_cents,
            $paidExpr AS paid_cents,
            $remainingExpr AS remaining_cents,

            COALESCE(
                NULLIF(e.raison_sociale,''),
                NULLIF(CONCAT(u.prenom,' ',u.nom),''),
                COALESCE(e.email_facturation, e.email, u.email, '—')
            ) AS dest_sort,

            u.email AS u_email, u.prenom AS u_prenom, u.nom AS u_nom,
            e.raison_sociale AS e_rs, e.email AS e_email, e.email_facturation AS e_emailf

        FROM facture f
        LEFT JOIN utilisateur u ON u.id = f.destinataire_id
        LEFT JOIN entreprise e  ON e.id = f.entreprise_destinataire_id
        LEFT JOIN ($paidSub) p ON p.facture_id = f.id
        LEFT JOIN ($deboursSub) deb ON deb.facture_id = f.id
        LEFT JOIN ($horsDeboursSub) hd ON hd.facture_id = f.id


        $whereSql
        ORDER BY $orderBy $dir
        LIMIT :len OFFSET :start
    ";

    $params['len']   = $length;
    $params['start'] = $start;
    $types['len']    = ParameterType::INTEGER;
    $types['start']  = ParameterType::INTEGER;

    $rows = $conn->fetchAllAssociative($sql, $params, $types);

    // =========================
    // Build DataTables JSON
    // =========================
    $data = [];
    foreach ($rows as $r) {
      $id = (int)$r['id'];

      $destLabel = '—';
      if (!empty($r['e_rs'])) {
        $emailE = trim((string)($r['e_emailf'] ?: $r['e_email']));
        $nameE  = trim((string)$r['e_rs']);
        $destLabel = $nameE ?: ($emailE ?: 'Entreprise');
        if ($emailE && $nameE) $destLabel = $nameE . '<br><small class="text-muted">' . $emailE . '</small>';
      } else {
        $emailU = trim((string)($r['u_email'] ?? ''));
        $nameU  = trim((string)(($r['u_prenom'] ?? '') . ' ' . ($r['u_nom'] ?? '')));
        $destLabel = $nameU ?: ($emailU ?: '—');
        if ($emailU && $nameU) $destLabel = $nameU . '<br><small class="text-muted">' . $emailU . '</small>';
      }

      $dateIso = $r['date_emission'] ? (new \DateTimeImmutable((string)$r['date_emission']))->format('Y-m-d') : '';
      $dateFr  = $r['date_emission'] ? (new \DateTimeImmutable((string)$r['date_emission']))->format('d/m/Y') : '—';

      /** @var Facture|null $facture */
      $facture = $em->getRepository(Facture::class)->find($id);


      $ht        = (int)($r['ht_cents'] ?? 0);
      $tva       = (int)($r['tva_cents'] ?? 0);
      $debours   = (int)($r['debours_ttc_cents'] ?? 0);
      $ttc       = (int)($r['ttc_total_cents'] ?? 0);
      $paid      = (int)($r['paid_cents'] ?? 0);
      $remaining = (int)($r['remaining_cents'] ?? 0);


      $numero = $r['numero'] ?: '—';
      $note   = trim((string)($r['note'] ?? ''));

      $numeroHtml = '<div class="fact-num-wrap">'
        . '<div class="fact-num">' . htmlspecialchars($numero, ENT_QUOTES) . '</div>';

      if ($note !== '') {
        // tooltip = note complète (sans HTML)
        $tt = htmlspecialchars($note, ENT_QUOTES);

        // preview = note (on garde les retours, mais tronquée en CSS)
        $preview = nl2br($tt);

        $numeroHtml .=
          '<div class="fact-note" data-bs-toggle="tooltip" data-bs-placement="top" title="' . $tt . '">'
          . '<span class="fact-note-text">' . $preview . '</span>'
          . '</div>';
      }

      $numeroHtml .= '</div>';



      $data[] = [
        'numero' => $numeroHtml,
        'dest' => $destLabel,
        'dateEmission' => $dateIso ? '<span data-order="' . $dateIso . '">' . $dateFr . '</span>' : '—',
        'ht' => $this->moneyCell($ht, 'ht'),
        'tva' => $this->moneyCell($tva, 'tva'),
        'debours' => $this->moneyCell($debours, 'debours'),
        'ttc' => $this->moneyCell($ttc, 'ttc'),
        'paid' => $this->moneyCell($paid, 'paid'),
        'remaining' => $this->moneyCell($remaining, 'remaining'),
        'status' => $facture
          ? $this->renderView('administrateur/facture/_status_badge.html.twig', [
            'f' => $facture,
            'paidCents' => $paid,
            'remainingCents' => $remaining,
          ])
          : '—',
        'actions' => $facture
          ? $this->renderView('administrateur/facture/_actions.html.twig', [
            'f' => $facture,
            'entite' => $entite,
            'paidCents' => $paid,
            'remainingCents' => $remaining,
          ])
          : '',
      ];
    }

    // =========================
    // KPIs (mêmes filtres) (✅ pas de GROUP BY)
    // =========================
    $kpiSql = "
        SELECT
            COUNT(DISTINCT f.id) AS count,
            COALESCE(SUM($htExpr),0)  AS ht_cents,
            COALESCE(SUM($tvaExpr),0) AS tva_cents,
            COALESCE(SUM($deboursExpr),0) AS debours_cents,
            COALESCE(SUM($ttcExpr),0) AS ttc_cents,
            COALESCE(SUM($paidExpr),0) AS paid_cents,
            COALESCE(SUM($remainingExpr),0) AS remaining_cents
        FROM facture f
        LEFT JOIN utilisateur u ON u.id = f.destinataire_id
        LEFT JOIN entreprise e  ON e.id = f.entreprise_destinataire_id
        LEFT JOIN ($paidSub) p ON p.facture_id = f.id
        LEFT JOIN ($deboursSub) deb ON deb.facture_id = f.id
        LEFT JOIN ($horsDeboursSub) hd ON hd.facture_id = f.id
        $whereSql
    ";
    $kpisRow = $conn->fetchAssociative($kpiSql, $params, $types) ?: [];

    $kpis = [
      'count'          => (int)($kpisRow['count'] ?? 0),
      'ttcCents'       => (int)($kpisRow['ttc_cents'] ?? 0),
      'paidCents'      => (int)($kpisRow['paid_cents'] ?? 0),
      'remainingCents' => (int)($kpisRow['remaining_cents'] ?? 0),

      // optionnels (si un jour tu affiches)
      'htCents'        => (int)($kpisRow['ht_cents'] ?? 0),
      'tvaCents'       => (int)($kpisRow['tva_cents'] ?? 0),
      'deboursCents'   => (int)($kpisRow['debours_cents'] ?? 0),
    ];

    return new JsonResponse([
      'draw' => $draw,
      'recordsTotal' => $recordsTotal,
      'recordsFiltered' => $recordsFiltered,
      'kpis' => $kpis,
      'data' => $data,
    ]);
  }





  #[Route('/kpis', name: 'kpis', methods: ['GET'])]
  public function facturesKpis(Entite $entite, EM $em): JsonResponse
  {
    $conn = $em->getConnection();

    $paidSub = "
    SELECT facture_id, COALESCE(SUM(montant_cents),0) AS paid_cents
    FROM paiement
    GROUP BY facture_id
  ";

    $deboursSub = "
    SELECT
      facture_id,
      COALESCE(SUM(
        (
          GREATEST(
            0,
            (qte * pu_ht_cents)
            - CASE
              WHEN COALESCE(remise_montant_cents, 0) > 0 THEN LEAST(remise_montant_cents, (qte * pu_ht_cents))
              WHEN COALESCE(remise_pourcent, 0) > 0 THEN ROUND((qte * pu_ht_cents) * (remise_pourcent / 100))
              ELSE 0
            END
          )
        )
        +
        ROUND(
          GREATEST(
            0,
            (qte * pu_ht_cents)
            - CASE
              WHEN COALESCE(remise_montant_cents, 0) > 0 THEN LEAST(remise_montant_cents, (qte * pu_ht_cents))
              WHEN COALESCE(remise_pourcent, 0) > 0 THEN ROUND((qte * pu_ht_cents) * (remise_pourcent / 100))
              ELSE 0
            END
          )
          * (COALESCE(tva_bp, 0) / 10000)
        )
      ),0) AS debours_ttc_cents
    FROM ligne_facture
    WHERE is_debours = 1
    GROUP BY facture_id
  ";

    $horsDeboursSub = "
    SELECT
      facture_id,
      COALESCE(SUM(
        GREATEST(
          0,
          (qte * pu_ht_cents)
          - CASE
            WHEN COALESCE(remise_montant_cents, 0) > 0 THEN LEAST(remise_montant_cents, (qte * pu_ht_cents))
            WHEN COALESCE(remise_pourcent, 0) > 0 THEN ROUND((qte * pu_ht_cents) * (remise_pourcent / 100))
            ELSE 0
          END
        )
      ),0) AS ht_hd_cents,
      COALESCE(SUM(
        ROUND(
          GREATEST(
            0,
            (qte * pu_ht_cents)
            - CASE
              WHEN COALESCE(remise_montant_cents, 0) > 0 THEN LEAST(remise_montant_cents, (qte * pu_ht_cents))
              WHEN COALESCE(remise_pourcent, 0) > 0 THEN ROUND((qte * pu_ht_cents) * (remise_pourcent / 100))
              ELSE 0
            END
          ) * (COALESCE(tva_bp, 0) / 10000)
        )
      ),0) AS tva_hd_cents
    FROM ligne_facture
    WHERE COALESCE(is_debours,0) = 0
    GROUP BY facture_id
  ";

    $sql = "
    SELECT
      COUNT(DISTINCT f.id) AS count,
      COALESCE(SUM(COALESCE(hd.ht_hd_cents,0) + COALESCE(hd.tva_hd_cents,0) + COALESCE(deb.debours_ttc_cents,0)),0) AS ttc_cents,
      COALESCE(SUM(COALESCE(p.paid_cents,0)),0) AS paid_cents
    FROM facture f
    LEFT JOIN ($paidSub) p ON p.facture_id = f.id
    LEFT JOIN ($deboursSub) deb ON deb.facture_id = f.id
    LEFT JOIN ($horsDeboursSub) hd ON hd.facture_id = f.id
    WHERE f.entite_id = :entiteId
  ";

    $row = $conn->fetchAssociative($sql, ['entiteId' => $entite->getId()], ['entiteId' => ParameterType::INTEGER]) ?: [];

    $count = (int)($row['count'] ?? 0);
    $ttc   = (int)($row['ttc_cents'] ?? 0);
    $paid  = (int)($row['paid_cents'] ?? 0);
    $remaining = max(0, $ttc - $paid);

    return new JsonResponse([
      'count' => $count,
      'ttcCents' => $ttc,
      'paidCents' => $paid,
      'remainingCents' => $remaining,
    ]);
  }


  /**
   * ✅ Petit helper pour la mise en forme des montants (HTML)
   * (utilisé par DataTables, donc on renvoie une string HTML)
   */
  private function moneyCell(int $cents, string $type): string
  {
    $val = number_format($cents / 100, 2, ',', ' ') . ' €';

    return match ($type) {
      'remaining' => $cents > 0
        ? '<span class="badge rounded-pill bg-danger-subtle text-danger border border-danger-subtle px-2 py-1">' . $val . '</span>'
        : '<span class="badge rounded-pill bg-success-subtle text-success border border-success-subtle px-2 py-1">0,00 €</span>',

      'paid' => $cents > 0
        ? '<span class="badge rounded-pill bg-success-subtle text-success border border-success-subtle px-2 py-1">' . $val . '</span>'
        : '<span class="text-muted">0,00 €</span>',

      'debours' => $cents > 0
        ? '<span class="badge rounded-pill bg-secondary-subtle text-secondary border border-secondary-subtle px-2 py-1" data-bs-toggle="tooltip" title="Total des lignes marquées en débours (TTC)">' . $val . '</span>'
        : '<span class="text-muted">0,00 €</span>',

      'tva' => $cents > 0
        ? '<span class="badge rounded-pill bg-info-subtle text-info border border-info-subtle px-2 py-1">' . $val . '</span>'
        : '<span class="text-muted">0,00 €</span>',

      default => '<span class="fw-semibold">' . $val . '</span>',
    };
  }


  #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
  public function factureNew(
    Entite $entite,
    Request $req,
    EM $em,
    FactureNumberGenerator $factureGen,
  ): Response {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    $facture = new Facture();
    $facture->setCreateur($user);
    $facture->setEntite($entite);
    $facture->setDateEmission(new \DateTimeImmutable());

    // si chez toi ces champs sont obligatoires
    if (method_exists($facture, 'setStatus') && !$facture->getStatus()) {
      $facture->setStatus(FactureStatus::DUE);
    }
    if (method_exists($facture, 'setDevise') && !$facture->getDevise()) {
      $facture->setDevise('EUR');
    }

    $form = $this->createForm(FactureType::class, $facture, [
      'entite' => $entite,
    ])->handleRequest($req);

    if ($form->isSubmitted() && $form->isValid()) {

      // Optionnel mais conseillé : recalcul côté serveur depuis les lignes
      $this->recalcFacture($facture);

      foreach ($facture->getLignes() as $ligne) {
        if (!$ligne->getCreateur()) {
          $ligne->setCreateur($user);
        }
        if (!$ligne->getEntite()) {
          $ligne->setEntite($entite);
        }
        // (optionnel) sécurité : rattacher la facture si jamais
        if ($ligne->getFacture() !== $facture) {
          $ligne->setFacture($facture);
        }
      }

      // Optionnel : exclusivité destinataire / entrepriseDestinataire

      if (!$facture->getNumero()) {
        $year = (int) $facture->getDateEmission()->format('Y');
        $facture->setNumero($factureGen->nextForEntite($entite->getId(), $year));
      }






      $em->persist($facture);
      $em->flush();

      // ✅ sync montants sur inscriptions liées à cette facture
      $this->inscSync->syncMany($facture->getInscriptions()->toArray());

      $this->addFlash('success', 'Facture créée.');

      return $this->redirectToRoute('app_administrateur_facture_index', [
        'entite' => $entite->getId(),
      ]);
    }

    // ✅ ton template
    return $this->render('administrateur/facture/form.html.twig', [
      'form' => $form,
      'title' => 'Nouvelle facture',
      'entite' => $entite,
    ]);
  }

  #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
  public function factureEdit(
    Entite $entite,
    Facture $facture,
    Request $req,
    EM $em,
  ): Response {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    // ✅ sécurité entité
    if ($facture->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createAccessDeniedException('Facture non autorisée pour cette entité.');
    }
    $before = $facture->getInscriptions()->toArray();
    $form = $this->createForm(FactureType::class, $facture, [
      'entite' => $entite,
    ])->handleRequest($req);

    if ($form->isSubmitted() && $form->isValid()) {

      // recalcul serveur
      $this->recalcFacture($facture);


      foreach ($facture->getLignes() as $ligne) {
        if (!$ligne->getCreateur()) {
          $ligne->setCreateur($user);
        }
        if (!$ligne->getEntite()) {
          $ligne->setEntite($entite);
        }
        // (optionnel) sécurité : rattacher la facture si jamais
        if ($ligne->getFacture() !== $facture) {
          $ligne->setFacture($facture);
        }
      }

      $em->flush();

      $after = $facture->getInscriptions()->toArray();
      $affected = array_merge($before, $after);

      // ✅ sync
      $this->inscSync->syncMany($affected);

      $this->addFlash('success', 'Facture mise à jour.');

      return $this->redirectToRoute('app_administrateur_facture_index', [
        'entite' => $entite->getId(),
      ]);
    }

    // ✅ ton template
    return $this->render('administrateur/facture/form.html.twig', [
      'form' => $form,
      'title' => 'Éditer facture',
      'entite' => $entite,

    ]);
  }

  #[Route('/{id}/payer', name: 'pay', methods: ['POST'])]
  public function facturePay(Entite $entite, Facture $id, Request $request): RedirectResponse
  {
    if ($id->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createAccessDeniedException('Facture non autorisée pour cette entité.');
    }

    if (!$this->isCsrfTokenValid('facture_pay_' . $id->getId(), (string)$request->request->get('_token'))) {
      throw $this->createAccessDeniedException('CSRF invalide.');
    }

    return $this->redirectToRoute('app_administrateur_paiement_new', [
      'entite'  => $entite->getId(),
      'facture' => $id->getId(),
    ]);
  }


  #[Route('/{id}/pdf', name: 'pdf', methods: ['GET'])]
  public function facturePdf(Entite $entite, Facture $facture): Response
  {
    if (!$this->pdf) {
      throw $this->createNotFoundException('Service PDF non disponible.');
    }

    // ✅ sécurité entité
    if ($facture->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createAccessDeniedException('Facture non autorisée pour cette entité.');
    }

    $html = $this->renderView('pdf/facture.html.twig', [
      'entite'  => $entite,
      'facture' => $facture,
    ]);

    $fileName = sprintf('FACTURE_%s', $facture->getNumero() ?: $facture->getId());

    return $this->pdf->createPortrait($html, $fileName);
  }

  /**
   * Recalcule HT/TVA/TTC depuis les lignes (sécurité serveur).
   * ⚠️ Adapte si ton puHtCents est en CENTIMES (recommandé) ou en EUROS.
   * Ici on suppose : puHtCents = centimes, montants = centimes.
   */
  /**
   * Recalcule HT/TVA/TTC depuis les lignes.
   * ✅ TTC inclut les débours (TTC = TTC hors débours + débours TTC).
   */
  /**
   * Recalcule HT/TVA/TTC depuis les lignes (sécurité serveur).
   * Règle voulue :
   * - HT/TVA stockés = hors débours
   * - TTC stocké = TTC hors débours + débours TTC
   */
  private function recalcFacture(Facture $f): void
  {
    if (!method_exists($f, 'getLignes')) return;

    $htHorsDebours  = 0;
    $tvaHorsDebours = 0;
    $deboursTtc     = 0;

    foreach ($f->getLignes() as $l) {
      $lineHt  = (int) $l->getTotalHtNetCents();
      $lineTva = (int) $l->getTotalTvaCents();

      if ($l->isDebours()) {
        $deboursTtc += max(0, $lineHt + $lineTva);
        continue;
      }

      $htHorsDebours  += max(0, $lineHt);
      $tvaHorsDebours += max(0, $lineTva);
    }


    // ✅ stocke hors débours
    $f->setMontantHtCents($htHorsDebours);
    $f->setMontantTvaCents($tvaHorsDebours);

    // ✅ ton modèle : montantTtcCents = TTC hors débours (et les débours sont à part)
    $f->setMontantTtcCents($htHorsDebours + $tvaHorsDebours);

    // Si tu as un champ dédié débours TTC sur Facture, set-le ici aussi.
    // ex: $f->setMontantDeboursTtcCents($deboursTtc);
  }






  #[Route('/{id}/send', name: 'send', methods: ['POST'])]
  public function factureSend(
    Entite $entite,
    Facture $facture,
    Request $request,
    MailerInterface $mailer,
    EM $em
  ): RedirectResponse {
    // ✅ sécurité entité
    if ($facture->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createAccessDeniedException('Facture non autorisée pour cette entité.');
    }

    // ✅ CSRF
    if (!$this->isCsrfTokenValid('facture_send_' . $facture->getId(), (string)$request->request->get('_token'))) {
      throw $this->createAccessDeniedException('CSRF invalide.');
    }

    // ✅ service PDF
    if (!$this->pdf) {
      $this->addFlash('danger', 'Service PDF non disponible.');
      return $this->redirectToRoute('app_administrateur_facture_index', ['entite' => $entite->getId()]);
    }

    // ✅ (optionnel) empêcher envoi si annulée
    if (($facture->getStatus()?->value ?? null) === FactureStatus::CANCELED->value) {
      $this->addFlash('warning', "Facture annulée : envoi désactivé.");
      return $this->redirectToRoute('app_administrateur_facture_index', ['entite' => $entite->getId()]);
    }

    /** @var Utilisateur|null $actor */
    $actor = $this->getUser(); // qui clique sur "envoyer"

    // ✅ 1) Destinataire (Utilisateur ou Entreprise)
    $entreprise = method_exists($facture, 'getEntrepriseDestinataire') ? $facture->getEntrepriseDestinataire() : null;
    $toUser = $facture->getDestinataire(); // utilisateur direct, si facture à une personne

    $toEmail = null;
    $toName  = null;

    if ($entreprise) {
      $toEmail = $entreprise->getEmailFacturation() ?: $entreprise->getEmail();
      $toName  = (string)($entreprise->getRaisonSociale() ?: $toEmail);

      // ✅ si entreprise -> tenter de retrouver l'utilisateur "personne morale"
      // (ex: premier utilisateur de l'entreprise, sinon matching email)
      if (!$toUser) {
        if (method_exists($entreprise, 'getUtilisateurs')) {
          $first = $entreprise->getUtilisateurs()->first();
          if ($first) {
            $toUser = $first;
          }
        }
        if (!$toUser && $toEmail) {
          // fallback : utilisateur portant cet email
          $toUser = $em->getRepository(Utilisateur::class)->findOneBy(['email' => $toEmail]);
        }
      }
    } elseif ($toUser) {
      $toEmail = $toUser->getEmail();
      $toName  = trim(($toUser->getPrenom() ?? '') . ' ' . ($toUser->getNom() ?? '')) ?: $toEmail;
    }

    if (!$toEmail) {
      $this->addFlash('warning', "Impossible d'envoyer : aucun email destinataire (Utilisateur ou Entreprise).");
      return $this->redirectToRoute('app_administrateur_facture_index', ['entite' => $entite->getId()]);
    }

    // ✅ 2) Email (subject + body)
    $fromEmail = 'contact@wikiformation.fr';
    $fromName  = (string)($entite->getNom() ?? 'Facturation');

    $subject = sprintf('Votre facture %s', $facture->getNumero() ?: ('#' . $facture->getId()));

    $htmlBody = $this->renderView('emails/facture_send.html.twig', [
      'subject' => $subject,
      'entite'  => $entite,
      'facture' => $facture,
    ]);

    // ✅ 3) Générer PDF (bytes) avec TON modèle
    $htmlPdf = $this->renderView('pdf/facture.html.twig', [
      'entite'  => $entite,
      'facture' => $facture,
    ]);
    $pdfBytes = $this->pdf->createPortraitBytes($htmlPdf);
    $fileName = sprintf('FACTURE_%s.pdf', $facture->getNumero() ?: $facture->getId());

    $email = (new Email())
      ->from(new Address($fromEmail, $fromName))
      ->to(new Address($toEmail, $toName ?: $toEmail))
      ->subject($subject)
      ->html($htmlBody)
      ->attach($pdfBytes, $fileName, 'application/pdf');

    // ✅ 4) Créer le EmailLog AVANT l'envoi (trace aussi les échecs)
    // Idempotence : évite double log si double click / refresh
    $idemKey = sprintf(
      'facture:%d:send:%s',
      $facture->getId(),
      sha1($toEmail . '|' . $subject . '|' . $fileName)
    );

    /** @var EmailLog|null $existing */
    $existing = $em->getRepository(EmailLog::class)->findOneBy(['idemKey' => $idemKey]);
    if ($existing) {
      // déjà loggué (double click) → on évite les doublons
      $this->addFlash('info', "Envoi déjà tracé (anti double-clic).");
      return $this->redirectToRoute('app_administrateur_facture_index', ['entite' => $entite->getId()]);
    }

    $log = (new EmailLog())
      ->setEntite($entite)
      ->setCreateur($actor)
      ->setFacture($facture)
      ->setToEmail($toEmail)
      ->setSubject($subject)
      ->setBodyHtmlSnapshot($htmlBody)
      ->setStatus('PENDING')
      ->setSentAt(new \DateTimeImmutable())
      ->setIdemKey($idemKey);

    // ✅ traçabilité : destinataire utilisateur + acteur (nécessite tes nouveaux champs)
    if (method_exists($log, 'setToUser')) {
      $log->setToUser($toUser);
    }
    if (method_exists($log, 'setActor')) {
      $log->setActor($actor);
    }

    $em->persist($log);
    $em->flush();

    try {
      $mailer->send($email);

      $log->setStatus('SENT')->setErrorMessage(null);
      $em->flush();

      $this->addFlash('success', "Facture envoyée à {$toEmail}.");
    } catch (\Throwable $e) {
      $log->setStatus('FAILED')->setErrorMessage($e->getMessage());
      $em->flush();

      $this->addFlash('danger', "Erreur lors de l'envoi : " . $e->getMessage());
    }

    return $this->redirectToRoute('app_administrateur_facture_index', ['entite' => $entite->getId()]);
  }

  #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
  public function factureShow(Entite $entite, Facture $facture, EM $em): Response
  {
    // ✅ sécurité entité
    if ($facture->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createAccessDeniedException('Facture non autorisée pour cette entité.');
    }

    /** @var Utilisateur $user */
    $user = $this->getUser();

    // (optionnel) paiements triés
    $paiements = $facture->getPaiements()->toArray();
    usort($paiements, fn($a, $b) => ($b->getDatePaiement()?->getTimestamp() ?? 0) <=> ($a->getDatePaiement()?->getTimestamp() ?? 0));

    // petits totaux utiles
    $paidCents = 0;
    foreach ($facture->getPaiements() as $p) {
      $paidCents += (int)$p->getMontantCents();
    }
    $ttcTotal = (int)$facture->getMontantTtcCents(); // ✅ TTC déjà complet


    $remainingCents = max(0, $ttcTotal - $paidCents);


    return $this->render('administrateur/facture/show.html.twig', [
      'entite' => $entite,
      'f' => $facture,
      'paiements' => $paiements,
      'paidCents' => $paidCents,
      'remainingCents' => $remainingCents,
    ]);
  }
}
