<?php

namespace App\Controller\Administrateur;

use App\Entity\{Entite, Depense, Paiement, Facture, Devis, Avoir};
use Doctrine\ORM\EntityManagerInterface as EM;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Enum\FactureStatus;
use App\Enum\DevisStatus;
use App\Enum\ModePaiement;
use App\Security\Permission\TenantPermission;



#[Route('/administrateur/{entite}/finance/api', name: 'app_administrateur_finance_dt_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::FINANCE_DATATABLE_MANAGE, subject: 'entite')]
final class FinanceDataTableController extends AbstractController
{
  #[Route('/depenses', name: 'depenses', methods: ['GET', 'POST'])]
  public function depenses(Entite $entite, EM $em, Request $req): JsonResponse
  {
    $qb = $em->createQueryBuilder()
      ->from(Depense::class, 'd')
      ->select('d, c, f, payeur, createur')
      ->leftJoin('d.categorie', 'c')
      ->leftJoin('d.fournisseur', 'f')
      ->leftJoin('d.payeur', 'payeur')
      ->leftJoin('d.createur', 'createur')
      ->andWhere('d.entite = :e')->setParameter('e', $entite);

    $search = trim((string) ($req->get('search')['value'] ?? ''));
    if ($search !== '') {
      $qb->andWhere('d.libelle LIKE :s OR c.libelle LIKE :s OR f.nom LIKE :s')
        ->setParameter('s', '%' . $search . '%');
    }


    // filtres spécifiques
    $this->applyCommonFilters($qb, $req, 'd', 'dateDepense', 'devise');
    $catMode = (string) $req->query->get('catMode', 'all');
    $fourMode = (string) $req->query->get('fourMode', 'all');

    $catIds  = $this->parseIds($req, 'catIds');
    $fourIds = $this->parseIds($req, 'fourIds');

    // Catégories
    if ($catMode === 'none') {
      $qb->andWhere('1=0');
    } elseif ($catMode === 'some') {
      if ($catIds) $qb->andWhere('c.id IN (:cats)')->setParameter('cats', $catIds);
      else $qb->andWhere('1=0');
    } else {
      // all => rien (ou ton includeInFinanceCharts si tu veux l'appliquer ici aussi)
    }

    // Fournisseurs
    if ($fourMode === 'none') {
      $qb->andWhere('1=0');
    } elseif ($fourMode === 'some') {
      if ($fourIds) $qb->andWhere('f.id IN (:fours)')->setParameter('fours', $fourIds);
      else $qb->andWhere('1=0');
    }


    $tvaOnly = $req->query->get('tvaOnly');
    if ($tvaOnly === 'deductible')   $qb->andWhere('d.tvaDeductible = 1');
    if ($tvaOnly === 'nodeductible') $qb->andWhere('d.tvaDeductible = 0');

    $columns = [
      // index => [db_expr_for_order, formatter(row)]
      0 => ['expr' => 'd.dateDepense', 'get' => fn($r) => $r['date']],
      1 => ['expr' => 'd.libelle',    'get' => fn($r) => $r['libelle']],
      2 => ['expr' => 'c.libelle',    'get' => fn($r) => $r['categorie']],
      3 => ['expr' => 'f.nom',        'get' => fn($r) => $r['fournisseur']],
      4 => ['expr' => 'd.montantHtCents',  'get' => fn($r) => $r['ht']],
      5 => ['expr' => 'd.montantTvaCents', 'get' => fn($r) => $r['tva']],
      6 => ['expr' => 'd.montantTtcCents', 'get' => fn($r) => $r['ttc']],
      7 => ['expr' => 'd.tvaDeductible',   'get' => fn($r) => $r['tvaDeductible']],
      8 => ['expr' => 'd.devise',          'get' => fn($r) => $r['devise']],
      9 => ['expr' => 'd.id',              'get' => fn($r) => $r['actions']],
    ];

    return $this->handleDataTable($qb, $req, $columns, function (Depense $d, Entite $entite) {
      $cat = $d->getCategorie()?->getLibelle() ?? '—';
      $four = $d->getFournisseur()?->getNom() ?? '—';
      $payeur = $d->getPayeur() ? trim(($d->getPayeur()->getPrenom() ?? '') . ' ' . ($d->getPayeur()->getNom() ?? '')) : '—';

      return [
        'id' => $d->getId(),
        'date' => $d->getDateDepense()->format('Y-m-d'),
        'libelle' => $d->getLibelle(),
        'categorie' => $cat,
        'fournisseur' => $four,
        'ht' => $d->getMontantHtCents(),
        'tva' => $d->getMontantTvaCents(),
        'ttc' => $d->getMontantTtcCents(),
        'tvaDeductible' => $d->isTvaDeductible(),
        'tvaDedCents' => $d->getTvaDeductibleCents(),
        'payeur' => $payeur,
        'devise' => $d->getDevise(),
        'actions' => (function() use ($d, $entite) {
          // ⚠️ adapte les noms de routes si besoin
          $urlShow = $this->generateUrl('app_administrateur_depense_show', ['entite' => $entite->getId(), 'id' => $d->getId()]);
          $urlEdit = $this->generateUrl('app_administrateur_depense_edit', ['entite' => $entite->getId(), 'id' => $d->getId()]);
          $urlDel  = $this->generateUrl('app_administrateur_depense_delete', ['entite' => $entite->getId(), 'id' => $d->getId()]);

          // ⚠️ token : adapte l’intention à ton delete (ex: "delete_depense")
          $token = $this->container->get('security.csrf.token_manager')->getToken('delete_depense'.$d->getId())->getValue();

          $label = trim(($d->getLibelle() ?? ''));

          return sprintf(
            '<div class="btn-group btn-group-sm" role="group" aria-label="Actions">
              %s %s
              <button type="button" class="btn btn-danger-soft js-dt-delete"
                data-url="%s" data-token="%s" data-label="%s" title="Supprimer">
                <i class="bi bi-trash3"></i>
              </button>
            </div>',
            $this->btn($urlShow, 'bi bi-eye', 'Voir'),
            $this->btn($urlEdit, 'bi bi-pencil-square', 'Modifier'),
            $this->esc($urlDel),
            $this->esc($token),
            $this->esc($label !== '' ? $label : ('Dépense #'.$d->getId()))
          );
        })(),
      ];
    }, $entite);
  }

  #[Route('/paiements', name: 'paiements', methods: ['GET', 'POST'])]
  public function paiements(Entite $entite, EM $em, Request $req): JsonResponse
  {
    $qb = $em->createQueryBuilder()
      ->from(Paiement::class, 'p')
      ->select('p, f, pu, pe')
      ->leftJoin('p.facture', 'f')
      ->leftJoin('p.payeurUtilisateur', 'pu')
      ->leftJoin('p.payeurEntreprise', 'pe')
      ->andWhere('p.entite = :e')->setParameter('e', $entite);

    // ✅ search global (corrigé)
    $search = trim((string) ($req->get('search')['value'] ?? ''));
    if ($search !== '') {
      $qb->andWhere("
            f.numero LIKE :s
            OR p.stripePaymentIntentId LIKE :s
            OR p.justificatif LIKE :s
        ")
        ->setParameter('s', '%' . $search . '%');
    }

    $this->applyCommonFilters($qb, $req, 'p', 'datePaiement', 'devise');

    $mode = $req->query->get('payMode');
    if ($mode !== null && $mode !== '') {
      $qb->andWhere('p.mode = :m')
        ->setParameter('m', ModePaiement::from($mode));
    }

    $columns = [
      0 => ['expr' => 'p.datePaiement',          'get' => fn($r) => $r['date']],
      1 => ['expr' => 'p.mode',                 'get' => fn($r) => $r['mode']],
      2 => ['expr' => 'f.numero',               'get' => fn($r) => $r['facture']],
      3 => ['expr' => 'p.montantCents',         'get' => fn($r) => $r['montant']],
      4 => ['expr' => 'p.devise',               'get' => fn($r) => $r['devise']],
      5 => ['expr' => 'p.stripePaymentIntentId', 'get' => fn($r) => $r['stripePaymentIntentId']],
      6 => ['expr' => 'p.justificatif',         'get' => fn($r) => $r['justificatif']],
      7 => ['expr' => 'p.id',                   'get' => fn($r) => $r['actions']],
    ];

    return $this->handleDataTable($qb, $req, $columns, function (Paiement $p, Entite $entite) {
      return [
        'id' => $p->getId(),
        'date' => $p->getDatePaiement()->format('Y-m-d'),
        'mode' => $p->getMode()?->label() ?? '—',
        'facture' => $p->getFacture()?->getNumero() ?? '—',
        'montant' => $p->getMontantCents(),
        'devise' => $p->getDevise(),
        'stripePaymentIntentId' => $p->getStripePaymentIntentId(),
        'justificatif' => $p->getJustificatif(),
        'actions' => (function() use ($p, $entite) {
          $urlShow = $this->generateUrl('app_administrateur_paiement_show', ['entite' => $entite->getId(), 'id' => $p->getId()]);
          $btnFacture = '';

          if ($p->getFacture()) {
            $urlFact = $this->generateUrl('app_administrateur_facture_show', ['entite' => $entite->getId(), 'id' => $p->getFacture()->getId()]);
            $btnFacture = $this->btn($urlFact, 'bi bi-receipt', 'Voir la facture');
          }

          $urlDel  = $this->generateUrl('app_administrateur_paiement_delete', ['entite' => $entite->getId(), 'id' => $p->getId()]);
          $token = $this->container->get('security.csrf.token_manager')->getToken('delete_paiement'.$p->getId())->getValue();

          $label = $p->getStripePaymentIntentId() ?: ('Paiement #'.$p->getId());

          return sprintf(
            '<div class="btn-group btn-group-sm" role="group">
              %s %s
              <button type="button" class="btn btn-danger-soft js-dt-delete"
                data-url="%s" data-token="%s" data-label="%s" title="Supprimer">
                <i class="bi bi-trash3"></i>
              </button>
            </div>',
            $this->btn($urlShow, 'bi bi-eye', 'Voir'),
            $btnFacture,
            $this->esc($urlDel),
            $this->esc($token),
            $this->esc($label)
          );
        })(),
      ];
    }, $entite);
  }


  #[Route('/factures', name: 'factures', methods: ['GET', 'POST'])]
  public function factures(Entite $entite, EM $em, Request $req): JsonResponse
  {
    $qb = $em->createQueryBuilder()
      ->from(Facture::class, 'fa')
      ->select('fa, dest, entDest')
      ->leftJoin('fa.destinataire', 'dest')
      ->leftJoin('fa.entrepriseDestinataire', 'entDest')
      ->andWhere('fa.entite = :e')->setParameter('e', $entite);

    $search = trim((string) ($req->get('search')['value'] ?? ''));
    if ($search !== '') {
      $qb->andWhere("
          fa.numero LIKE :s
          OR entDest.raisonSociale LIKE :s
          OR dest.email LIKE :s
          OR dest.nom LIKE :s
          OR dest.prenom LIKE :s
      ")->setParameter('s', '%' . $search . '%');
    }



    $this->applyCommonFilters($qb, $req, 'fa', 'dateEmission', 'devise');
    $st = $req->query->get('factureStatus');
    if ($st !== null && $st !== '') {
      $qb->andWhere('fa.status = :st')
        ->setParameter('st', FactureStatus::from($st));
    }


    $columns = [
      0 => ['expr' => 'fa.dateEmission',     'get' => fn($r) => $r['date']],
      1 => ['expr' => 'fa.numero',          'get' => fn($r) => $r['numero']],
      2 => ['expr' => 'entDest.raisonSociale', 'get' => fn($r) => $r['destinataire']], // ✅
      3 => ['expr' => 'fa.status',          'get' => fn($r) => $r['status']],
      4 => ['expr' => 'fa.montantHtCents',  'get' => fn($r) => $r['ht']],
      5 => ['expr' => 'fa.montantTvaCents', 'get' => fn($r) => $r['tva']],
      6 => ['expr' => 'fa.montantTtcCents', 'get' => fn($r) => $r['ttc']],
      7 => ['expr' => 'fa.devise',          'get' => fn($r) => $r['devise']],
      8 => ['expr' => 'fa.id',              'get' => fn($r) => $r['actions']],
    ];

    return $this->handleDataTable($qb, $req, $columns, function (Facture $fa, Entite $entite) {
      return [
        'id' => $fa->getId(),
        'date' => $fa->getDateEmission()->format('Y-m-d'),
        'numero' => $fa->getNumero(),
        'destinataire' => $fa->getDestinataireLabel(), // ✅
        'status' => $fa->getStatus()?->label() ?? '—',
        'statusValue' => $fa->getStatus()?->value ?? null, // optionnel
        'ht' => $fa->getMontantHtCents(),
        'tva' => $fa->getMontantTvaCents(),
        'ttc' => $fa->getMontantTtcCents(),
        'devise' => $fa->getDevise(),
        'actions' => (function() use ($fa, $entite) {
          $urlShow = $this->generateUrl('app_administrateur_facture_show', ['entite' => $entite->getId(), 'id' => $fa->getId()]);
          // ⚠️ si tu as une route PDF dédiée, utilise-la, sinon show
          $urlPdf  = $this->generateUrl('app_administrateur_facture_pdf', ['entite' => $entite->getId(), 'id' => $fa->getId()]);

          $urlDel  = $this->generateUrl('app_administrateur_facture_delete', ['entite' => $entite->getId(), 'id' => $fa->getId()]);
          $token = $this->container->get('security.csrf.token_manager')->getToken('delete_facture'.$fa->getId())->getValue();

          $label = $fa->getNumero() ?: ('Facture #'.$fa->getId());

          return sprintf(
            '<div class="btn-group btn-group-sm" role="group">
              %s
              %s
              <button type="button" class="btn btn-danger-soft js-dt-delete"
                data-url="%s" data-token="%s" data-label="%s" title="Supprimer">
                <i class="bi bi-trash3"></i>
              </button>
            </div>',
            $this->btn($urlShow, 'bi bi-eye', 'Voir'),
            $this->btn($urlPdf,  'bi bi-file-earmark-pdf', 'PDF', 'btn btn-light', ['target' => '_blank']),
            $this->esc($urlDel),
            $this->esc($token),
            $this->esc($label)
          );
        })(),
      ];
    }, $entite);
  }

  #[Route('/devis', name: 'devis', methods: ['GET', 'POST'])]
  public function devis(Entite $entite, EM $em, Request $req): JsonResponse
  {
    $qb = $em->createQueryBuilder()
      ->from(Devis::class, 'dv')
      ->select('dv, dest, entDest, p')
      ->leftJoin('dv.destinataire', 'dest')
      ->leftJoin('dv.entrepriseDestinataire', 'entDest')
      ->leftJoin('dv.prospect', 'p')
      ->andWhere('dv.entite = :e')->setParameter('e', $entite);

    $search = trim((string) ($req->get('search')['value'] ?? ''));
    if ($search !== '') {
      $qb->andWhere("
          dv.numero LIKE :s
          OR entDest.raisonSociale LIKE :s
          OR dest.email LIKE :s
          OR dest.nom LIKE :s
          OR dest.prenom LIKE :s
          OR p.nom LIKE :s
          OR p.prenom LIKE :s
      ")->setParameter('s', '%' . $search . '%');
    }



    $this->applyCommonFilters($qb, $req, 'dv', 'dateEmission', 'devise');
    $st = $req->query->get('devisStatus');
    if ($st !== null && $st !== '') {
      $qb->andWhere('dv.status = :st')
        ->setParameter('st', DevisStatus::from($st));
    }


    $columns = [
      0 => ['expr' => 'dv.dateEmission',     'get' => fn($r) => $r['date']],
      1 => ['expr' => 'dv.numero',          'get' => fn($r) => $r['numero']],
      2 => ['expr' => 'entDest.raisonSociale', 'get' => fn($r) => $r['destinataire']],
      3 => ['expr' => 'dv.status',          'get' => fn($r) => $r['status']],
      4 => ['expr' => 'dv.montantHtCents',  'get' => fn($r) => $r['ht']],
      5 => ['expr' => 'dv.montantTvaCents', 'get' => fn($r) => $r['tva']],
      6 => ['expr' => 'dv.montantTtcCents', 'get' => fn($r) => $r['ttc']],
      7 => ['expr' => 'dv.devise',          'get' => fn($r) => $r['devise']],
      8 => ['expr' => 'dv.pdfPath',         'get' => fn($r) => $r['pdf']],     // ✅
      9 => ['expr' => 'dv.id',              'get' => fn($r) => $r['actions']],
    ];

    return $this->handleDataTable($qb, $req, $columns, function (Devis $dv,Entite $entite) {
      $dest = '—';
      if ($dv->getEntrepriseDestinataire()) $dest = $dv->getEntrepriseDestinataire()->getRaisonSociale() ?: 'Entreprise';
      elseif ($dv->getDestinataire()) {
        $u = $dv->getDestinataire();
        $dest = trim(($u->getPrenom() ?? '') . ' ' . ($u->getNom() ?? '')) ?: ($u->getEmail() ?? '—');
      } elseif ($dv->getProspect()) {
        $p = $dv->getProspect();
        $dest = trim(($p->getNom() ?? '') . ' ' . ($p->getPrenom() ?? '')) ?: 'Prospect';
      }

      return [
        'id' => $dv->getId(),
        'date' => $dv->getDateEmission()->format('Y-m-d'),
        'numero' => $dv->getNumero() ?? '—',
        'destinataire' => $dest,
        'status' => $dv->getStatus()?->label() ?? '—',
        'statusValue' => $dv->getStatus()?->value ?? null, // optionnel

        'ht' => $dv->getMontantHtCents(),
        'tva' => $dv->getMontantTvaCents(),
        'ttc' => $dv->getMontantTtcCents(),
        'devise' => $dv->getDevise(),
        'pdf' => $dv->getPdfPath(),
        'actions' => (function() use ($dv, $entite) {
          $urlShow = $this->generateUrl('app_administrateur_devis_show', ['entite' => $entite->getId(), 'id' => $dv->getId()]);

          $btnPdf = '';
          if ($dv->getPdfPath()) {
            // ⚠️ adapte selon ton stockage (route, controller download, ou public/uploads)
            $urlPdf = '/uploads/devis/' . rawurlencode($dv->getPdfPath());
            $btnPdf = $this->btn($urlPdf, 'bi bi-file-earmark-pdf', 'PDF', 'btn btn-light', ['target' => '_blank']);
          }

          // ⚠️ route conversion à adapter
          $urlConv = $this->generateUrl('app_administrateur_devis_to_facture', ['entite' => $entite->getId(), 'id' => $dv->getId()]);

          $urlDel  = $this->generateUrl('app_administrateur_devis_delete', ['entite' => $entite->getId(), 'id' => $dv->getId()]);
          $token = $this->container->get('security.csrf.token_manager')->getToken('delete_devis'.$dv->getId())->getValue();

          $label = $dv->getNumero() ?: ('Devis #'.$dv->getId());

          return sprintf(
            '<div class="btn-group btn-group-sm" role="group">
              %s
              %s
              %s
              <button type="button" class="btn btn-danger-soft js-dt-delete"
                data-url="%s" data-token="%s" data-label="%s" title="Supprimer">
                <i class="bi bi-trash3"></i>
              </button>
            </div>',
            $this->btn($urlShow, 'bi bi-eye', 'Voir'),
            $btnPdf,
            $this->btn($urlConv, 'bi bi-arrow-right-circle', 'Convertir en facture'),
            $this->esc($urlDel),
            $this->esc($token),
            $this->esc($label)
          );
        })(),
      ];
    }, $entite);
  }

  #[Route('/avoirs', name: 'avoirs', methods: ['GET', 'POST'])]
  public function avoirs(Entite $entite, EM $em, Request $req): JsonResponse
  {
    $qb = $em->createQueryBuilder()
      ->from(Avoir::class, 'av')
      ->select('av, f')
      ->leftJoin('av.factureOrigine', 'f')
      ->andWhere('av.entite = :e')->setParameter('e', $entite);

    $search = trim((string) ($req->get('search')['value'] ?? ''));
    if ($search !== '') {
      $qb->andWhere("
          dv.numero LIKE :s
          OR entDest.raisonSociale LIKE :s
          OR dest.email LIKE :s
          OR dest.nom LIKE :s
          OR dest.prenom LIKE :s
          OR p.nom LIKE :s
          OR p.prenom LIKE :s
      ")->setParameter('s', '%' . $search . '%');
    }



    $this->applyCommonFilters($qb, $req, 'av', 'dateEmission', null);

    $columns = [
      0 => ['expr' => 'av.dateEmission',      'get' => fn($r) => $r['date']],
      1 => ['expr' => 'av.numero',           'get' => fn($r) => $r['numero']],
      2 => ['expr' => 'f.numero',            'get' => fn($r) => $r['factureOrigine']], // ✅
      3 => ['expr' => 'av.montantTtcCents',  'get' => fn($r) => $r['ttc']],
      4 => ['expr' => 'av.id',               'get' => fn($r) => $r['actions']],
    ];

    return $this->handleDataTable($qb, $req, $columns, function (Avoir $av,Entite $entite) {
      return [
        'id' => $av->getId(),
        'date' => $av->getDateEmission()->format('Y-m-d'),
        'numero' => $av->getNumeroOrNull() ?? '—',
        'factureOrigine' => $av->getFactureOrigine()?->getNumero() ?? '—',
        'ttc' => $av->getMontantTtcCents(),
        'actions' => (function() use ($av, $entite) {
          $urlShow = $this->generateUrl('app_administrateur_avoir_show', ['entite' => $entite->getId(), 'id' => $av->getId()]);
          $urlDel  = $this->generateUrl('app_administrateur_avoir_delete', ['entite' => $entite->getId(), 'id' => $av->getId()]);
          $token = $this->container->get('security.csrf.token_manager')->getToken('delete_avoir'.$av->getId())->getValue();

          $label = $av->getNumeroOrNull() ?: ('Avoir #'.$av->getId());

          return sprintf(
            '<div class="btn-group btn-group-sm" role="group">
              %s
              <button type="button" class="btn btn-danger-soft js-dt-delete"
                data-url="%s" data-token="%s" data-label="%s" title="Supprimer">
                <i class="bi bi-trash3"></i>
              </button>
            </div>',
            $this->btn($urlShow, 'bi bi-eye', 'Voir'),
            $this->esc($urlDel),
            $this->esc($token),
            $this->esc($label)
          );
        })(),
      ];
    }, $entite);
  }

  // -------------------------
  // DataTables core (server-side)
  // -------------------------

  private function handleDataTable(
    QueryBuilder $qb,
    Request $req,
    array $columns,
    callable $rowMapper,
    mixed $ctx = null
): JsonResponse
{
    $draw = (int) $req->get('draw', 1);
    $start = max(0, (int) $req->get('start', 0));
    $length = (int) $req->get('length', 10);
    if ($length <= 0) $length = 10;

    $order = $req->get('order')[0] ?? null;
    if ($order) {
        $colIdx = (int) ($order['column'] ?? 0);
        $dir = strtoupper($order['dir'] ?? 'ASC');
        $dir = in_array($dir, ['ASC', 'DESC'], true) ? $dir : 'ASC';
        if (isset($columns[$colIdx]['expr'])) {
            $qb->addOrderBy($columns[$colIdx]['expr'], $dir);
        }
    } else {
        $qb->addOrderBy(array_values($columns)[0]['expr'] ?? '1', 'DESC');
    }

    $countQb = clone $qb;
    $countQb->resetDQLPart('select')->resetDQLPart('orderBy');
    $countQb->select('COUNT(DISTINCT ' . $this->guessRootAlias($qb) . '.id)');
    $recordsFiltered = (int) $countQb->getQuery()->getSingleScalarResult();
    $recordsTotal = $recordsFiltered;

    $qb->setFirstResult($start)->setMaxResults($length);

    $rows = $qb->getQuery()->getResult();
    $data = [];
    foreach ($rows as $entity) {
        $data[] = $rowMapper($entity, $ctx); // ✅ ici on passe le contexte
    }

    return $this->json([
        'draw' => $draw,
        'recordsTotal' => $recordsTotal,
        'recordsFiltered' => $recordsFiltered,
        'data' => array_map(function ($row) use ($columns) {
            $out = [];
            foreach ($columns as $col) {
                $out[] = $col['get']($row);
            }
            return $out;
        }, $data),
    ]);
}

  private function applyCommonFilters(QueryBuilder $qb, Request $req, string $alias, string $dateField, ?string $deviseField): void
  {
    $start = $req->get('dateStart') ? new \DateTimeImmutable($req->get('dateStart')) : null;
    $end   = $req->get('dateEnd')   ? new \DateTimeImmutable($req->get('dateEnd'))   : null;

    if ($start && $end) {
      $qb->andWhere(sprintf('%s.%s BETWEEN :start AND :end', $alias, $dateField))
        ->setParameter('start', $start->setTime(0, 0, 0))
        ->setParameter('end', $end->setTime(23, 59, 59));
    }

    $dev = trim((string)$req->get('devise', ''));
    if ($deviseField && $dev !== '') {
      $qb->andWhere(sprintf('%s.%s = :dev', $alias, $deviseField))
        ->setParameter('dev', $dev);
    }
  }


  private function guessRootAlias(QueryBuilder $qb): string
  {
    $aliases = $qb->getRootAliases();
    return $aliases[0] ?? 'x';
  }

  private function parseIds(Request $req, string $key): array
  {
    $ids = (array) $req->query->all($key);     // catIds[] / fourIds[]
    $ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
    return $ids;
  }




  private function esc(?string $v): string
  {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

  private function btn(string $url, string $icon, string $title, string $class = 'btn btn-light', array $attrs = []): string
  {
    $attrHtml = '';
    foreach ($attrs as $k => $v) {
      $attrHtml .= ' ' . $this->esc($k) . '="' . $this->esc((string)$v) . '"';
    }

    return sprintf(
      '<a href="%s" class="%s" title="%s" data-bs-toggle="tooltip"%s><i class="%s"></i></a>',
      $this->esc($url),
      $this->esc($class),
      $this->esc($title),
      $attrHtml,
      $this->esc($icon)
    );
  }
}
