<?php
// src/Controller/Administrateur/TvaDataTableController.php
declare(strict_types=1);

namespace App\Controller\Administrateur;

use App\Entity\{Entite, Facture, Depense, Paiement, Avoir};
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Security\Permission\TenantPermission;


#[Route('/administrateur/{entite}/tva/dt', name: 'app_administrateur_tva_dt_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::TVA_DATATABLE_MANAGE, subject: 'entite')]
final class TvaDataTableController extends AbstractController
{
  private function dtParams(Request $req): array
  {
    $draw = (int)$req->query->get('draw', 1);
    $start = (int)$req->query->get('start', 0);
    $len = (int)$req->query->get('length', 25);
    $search = (string)($req->query->all('search')['value'] ?? '');
    return [$draw, $start, $len, $search];
  }

  private function parseRange(Request $req): array
  {
    $tz = new \DateTimeZone('Europe/Paris');
    $d1s = $req->query->getString('dateStart', '');
    $d2s = $req->query->getString('dateEnd', '');

    $d1 = $d1s ? \DateTimeImmutable::createFromFormat('Y-m-d', $d1s, $tz) : null;
    $d2 = $d2s ? \DateTimeImmutable::createFromFormat('Y-m-d', $d2s, $tz) : null;

    // fallback sécurité
    if ($d1s && !$d1) $d1 = new \DateTimeImmutable($d1s, $tz);
    if ($d2s && !$d2) $d2 = new \DateTimeImmutable($d2s, $tz);

    return [$d1, $d2];
  }

  private function dtResponse(int $draw, int $total, int $filtered, array $data): JsonResponse
  {
    return $this->json([
      'draw' => $draw,
      'recordsTotal' => $total,
      'recordsFiltered' => $filtered,
      'data' => $data,
    ]);
  }

  // =========================
  // FACTURES
  // =========================
  #[Route('/factures', name: 'factures', methods: ['GET', 'POST'])]
  public function factures(Entite $entite, EM $em, Request $req): JsonResponse
  {
    [$draw, $offset, $len, $search] = $this->dtParams($req);
    [$d1, $d2] = $this->parseRange($req);

    $qb = $em->createQueryBuilder()
      ->from(Facture::class, 'f')
      ->leftJoin('f.destinataire', 'u')
      ->leftJoin('f.entrepriseDestinataire', 'ed')
      ->select('f, u, ed')
      ->where('f.entite = :e')
      ->setParameter('e', $entite);

    if ($d1 && $d2) {
      $qb->andWhere('f.dateEmission BETWEEN :d1 AND :d2')
        ->setParameter('d1', $d1)
        ->setParameter('d2', $d2);
    }

    if ($search !== '') {
      $qb->andWhere('f.numero LIKE :q OR ed.raisonSociale LIKE :q OR u.email LIKE :q OR u.nom LIKE :q OR u.prenom LIKE :q')
        ->setParameter('q', '%' . $search . '%');
    }

    $countTotal = (int)$em->createQueryBuilder()
      ->select('COUNT(f0.id)')
      ->from(Facture::class, 'f0')
      ->where('f0.entite = :e')->setParameter('e', $entite)
      ->getQuery()->getSingleScalarResult();

    $countFiltered = (int)(clone $qb)
      ->select('COUNT(f.id)')
      ->getQuery()->getSingleScalarResult();

    /** @var Facture[] $rows */
    $rows = $qb->orderBy('f.dateEmission', 'DESC')
      ->setFirstResult($offset)->setMaxResults($len)
      ->getQuery()->getResult();

    $data = array_map(function (Facture $f) {
      $date = $f->getDateEmission()->format('d/m/Y');
      $numero = $f->getNumero();
      $dest = $f->getDestinataireLabel();

      // ✅ hors débours avec remise globale proportionnelle (tes méthodes)
      $ht  = $f->getMontantHtHorsDeboursCents();
      $tva = $f->getMontantTvaHorsDeboursCents();
      $ttc = $f->getMontantTtcHorsDeboursCents();

      $dev = $f->getDevise() ?: 'EUR';


      // Actions (facultatif) : lien facture
      $actions = sprintf(
        '<a class="btn btn-sm btn-light" href="%s"><i class="bi bi-eye"></i></a>',
        $this->generateUrl('app_administrateur_facture_show', ['entite' => $f->getEntite()?->getId(), 'id' => $f->getId()])
      );

      return [$date, $numero, $dest, $ht, $tva, $ttc, $dev, $actions];
    }, $rows);

    return $this->dtResponse($draw, $countTotal, $countFiltered, $data);
  }

  // =========================
  // DEPENSES
  // =========================
  #[Route('/depenses', name: 'depenses', methods: ['GET', 'POST'])]
  public function depenses(Entite $entite, EM $em, Request $req): JsonResponse
  {
    [$draw, $offset, $len, $search] = $this->dtParams($req);
    [$d1, $d2] = $this->parseRange($req);

    $cat = $req->query->get('categorie');
    $four = $req->query->get('fournisseur');
    $depTvaOnly = $req->query->getString('depTvaOnly', 'all'); // all|deductible|nodeductible
    $rate = $req->query->get('rate'); // ""|"20"|"10"|"55"|"0"

    $qb = $em->createQueryBuilder()
      ->from(Depense::class, 'd')
      ->leftJoin('d.categorie', 'c')
      ->leftJoin('d.fournisseur', 'f')
      ->select('d, c, f')
      ->where('d.entite = :e')->setParameter('e', $entite);

    if ($d1 && $d2) {
      $qb->andWhere('d.dateDepense BETWEEN :d1 AND :d2')
        ->setParameter('d1', $d1)
        ->setParameter('d2', $d2);
    }

    if ($cat)  $qb->andWhere('d.categorie = :cat')->setParameter('cat', (int)$cat);
    if ($four) $qb->andWhere('d.fournisseur = :four')->setParameter('four', (int)$four);

    if ($depTvaOnly === 'deductible')   $qb->andWhere('d.tvaDeductible = 1');
    if ($depTvaOnly === 'nodeductible') $qb->andWhere('d.tvaDeductible = 0');

    if ($rate !== null && $rate !== '') {
      $t = ((int)$rate === 55) ? 5.5 : (float)$rate;
      $qb->andWhere('d.tauxTva = :t')->setParameter('t', $t);
    }

    if ($search !== '') {
      $qb->andWhere('d.libelle LIKE :q OR c.libelle LIKE :q OR f.nom LIKE :q')
        ->setParameter('q', '%' . $search . '%');
    }

    $countTotal = (int)$em->createQueryBuilder()
      ->select('COUNT(d0.id)')
      ->from(Depense::class, 'd0')
      ->where('d0.entite = :e')->setParameter('e', $entite)
      ->getQuery()->getSingleScalarResult();

    $countFiltered = (int)(clone $qb)
      ->select('COUNT(d.id)')
      ->getQuery()->getSingleScalarResult();

    /** @var Depense[] $rows */
    $rows = $qb->orderBy('d.dateDepense', 'DESC')
      ->setFirstResult($offset)->setMaxResults($len)
      ->getQuery()->getResult();

    $data = array_map(function (Depense $d) {
      $date = $d->getDateDepense()?->format('d/m/Y') ?? '—';
      $lib  = method_exists($d, 'getLibelle') ? ($d->getLibelle() ?? '—') : '—';
      $cat  = $d->getCategorie()?->getLibelle() ?? '—';
      $four = $d->getFournisseur()?->getNom() ?? '—';

      $ht  = (int)($d->getMontantHtCents() ?? 0);
      $tva = (int)($d->getMontantTvaCents() ?? 0);
      $ttc = (int)($d->getMontantTtcCents() ?? ($ht + $tva));

      $ded = 0;
      if (method_exists($d, 'isTvaDeductible') && $d->isTvaDeductible()) {
        $pct = (float)($d->getTvaDeductiblePct() ?? 100);
        $ded = (int) round($tva * ($pct / 100));
      }

      $rate = method_exists($d, 'getTauxTva') ? $d->getTauxTva() : null;
      $rateLabel = ($rate === null) ? '—' : (str_replace('.', ',', rtrim(rtrim((string)$rate, '0'), '.')) . ' %');

      $actions = sprintf(
        '<a class="btn btn-sm btn-light" href="%s"><i class="bi bi-eye"></i></a>',
        $this->generateUrl('app_administrateur_depense_show', ['entite' => $d->getEntite()?->getId(), 'id' => $d->getId()])
      );

      return [$date, $lib, $cat, $four, $ht, $tva, $ded, $rateLabel, $ttc, $actions];
    }, $rows);

    return $this->dtResponse($draw, $countTotal, $countFiltered, $data);
  }

  // =========================
  // PAIEMENTS
  // =========================
  #[Route('/paiements', name: 'paiements', methods: ['GET', 'POST'])]
  public function paiements(Entite $entite, EM $em, Request $req): JsonResponse
  {
    [$draw, $offset, $len, $search] = $this->dtParams($req);
    [$d1, $d2] = $this->parseRange($req);

    $qb = $em->createQueryBuilder()
      ->from(Paiement::class, 'p')
      ->join('p.facture', 'f')
      ->select('p, f')
      ->where('f.entite = :e')->setParameter('e', $entite);

    if ($d1 && $d2) {
      $qb->andWhere('p.datePaiement BETWEEN :d1 AND :d2')
        ->setParameter('d1', $d1)
        ->setParameter('d2', $d2);
    }

    if ($search !== '') {
      $qb->andWhere('f.numero LIKE :q')->setParameter('q', '%' . $search . '%');
    }

    $countTotal = (int)$em->createQueryBuilder()
      ->select('COUNT(p0.id)')
      ->from(Paiement::class, 'p0')
      ->join('p0.facture', 'f0')
      ->where('f0.entite = :e')->setParameter('e', $entite)
      ->getQuery()->getSingleScalarResult();

    $countFiltered = (int)(clone $qb)->select('COUNT(p.id)')->getQuery()->getSingleScalarResult();

    /** @var Paiement[] $rows */
    $rows = $qb->orderBy('p.datePaiement', 'DESC')
      ->setFirstResult($offset)->setMaxResults($len)
      ->getQuery()->getResult();

    $data = array_map(function (Paiement $p) {
      $date = $p->getDatePaiement()->format('d/m/Y');

      // ✅ Mode affiché = label() ; value en tooltip
      $m = $p->getMode();
      $modeHtml = sprintf(
        '<span class="badge-soft" title="%s">%s</span>',
        htmlspecialchars($m->value, ENT_QUOTES),
        htmlspecialchars($m->label(), ENT_QUOTES)
      );

      $f = $p->getFacture();
      $factNum = $f?->getNumero() ?? '—';

      $amount = $p->getMontantCents();

      // ✅ TVA : ventilation prioritaire si présente, sinon estimation pro-rata TTC hors débours
      $tvaEst = 0;
      if (method_exists($p, 'getVentilationTvaHorsDeboursCents') && $p->getVentilationTvaHorsDeboursCents() !== null) {
        $tvaEst = (int) $p->getVentilationTvaHorsDeboursCents();
      } elseif ($f) {
        $facTtc = max(1, $f->getMontantTtcHorsDeboursCents());
        $facTva = max(0, $f->getMontantTvaHorsDeboursCents());

        $ratio = $amount / $facTtc;
        $ratio = max(0.0, min(1.0, $ratio)); // borne pour éviter sur-estimation si paiement > TTC

        $tvaEst = (int) round($facTva * $ratio);
      }

      // ✅ devise paiement d’abord (car tu l’as en colonne), sinon facture, sinon EUR
      $dev = $p->getDevise() ?: ($f?->getDevise() ?: 'EUR');

      $actions = $f ? sprintf(
        '<a class="btn btn-sm btn-light" href="%s" title="Voir la facture"><i class="bi bi-receipt"></i></a>',
        $this->generateUrl('app_administrateur_facture_show', [
          'entite' => $f->getEntite()?->getId(),
          'id' => $f->getId()
        ])
      ) : '';

      return [$date, $modeHtml, $factNum, $amount, $tvaEst, $dev, $actions];
    }, $rows);

    return $this->dtResponse($draw, $countTotal, $countFiltered, $data);
  }


  // =========================
  // AVOIRS
  // =========================
  #[Route('/avoirs', name: 'avoirs', methods: ['GET', 'POST'])]
  public function avoirs(Entite $entite, EM $em, Request $req): JsonResponse
  {
    [$draw, $offset, $len, $search] = $this->dtParams($req);
    [$d1, $d2] = $this->parseRange($req);

    $qb = $em->createQueryBuilder()
      ->from(Avoir::class, 'a')
      ->leftJoin('a.factureOrigine', 'f')
      ->select('a, f')
      ->where('a.entite = :e')->setParameter('e', $entite);

    if ($d1 && $d2) {
      $qb->andWhere('a.dateEmission BETWEEN :d1 AND :d2')
        ->setParameter('d1', $d1)
        ->setParameter('d2', $d2);
    }

    if ($search !== '') {
      // a.numero est unique, f.numero aussi
      $qb->andWhere('a.numero LIKE :q OR f.numero LIKE :q')
        ->setParameter('q', '%' . $search . '%');
    }

    $countTotal = (int)$em->createQueryBuilder()
      ->select('COUNT(a0.id)')
      ->from(Avoir::class, 'a0')
      ->where('a0.entite = :e')->setParameter('e', $entite)
      ->getQuery()->getSingleScalarResult();

    $countFiltered = (int)(clone $qb)->select('COUNT(a.id)')->getQuery()->getSingleScalarResult();

    /** @var Avoir[] $rows */
    $rows = $qb->orderBy('a.dateEmission', 'DESC')
      ->setFirstResult($offset)->setMaxResults($len)
      ->getQuery()->getResult();

    $data = array_map(function (Avoir $a) {
      $date = $a->getDateEmission()->format('d/m/Y');
      $num  = $a->hasNumero() ? ($a->getNumeroOrNull() ?? '—') : ('AV#' . $a->getId());

      $f = $a->getFactureOrigine();
      $orig = $f?->getNumero() ?? '—';

      $ttcAvoir = (int)$a->getMontantTtcCents();

      // ✅ TVA estimée au pro-rata TTC par rapport à la facture origine (hors débours)
      $tvaAvoir = 0;
      if ($f) {
        $facTtc = max(1, $f->getMontantTtcHorsDeboursCents());
        $facTva = $f->getMontantTvaHorsDeboursCents();
        $ratio  = $ttcAvoir / $facTtc; // 0..1 (souvent)
        $tvaAvoir = (int) round($facTva * $ratio);
      }

      // Actions : adapte si ton show route diffère
      $actions = sprintf(
        '<a class="btn btn-sm btn-light" href="%s"><i class="bi bi-eye"></i></a>',
        $this->generateUrl('app_administrateur_avoir_show', [
          'entite' => $a->getEntite()?->getId(),
          'id' => $a->getId()
        ])
      );

      return [$date, $num, $orig, $tvaAvoir, $ttcAvoir, $actions];
    }, $rows);

    return $this->dtResponse($draw, $countTotal, $countFiltered, $data);
  }
}
