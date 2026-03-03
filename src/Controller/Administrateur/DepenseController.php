<?php
// src/Controller/Administrateur/DepenseController.php

namespace App\Controller\Administrateur;

use App\Entity\{Depense, Entite, Utilisateur};
use App\Form\Administrateur\DepenseType;
use App\Service\Depense\DepenseUploader;
use App\Service\Depense\ReceiptScanService;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Entity\DepenseCategorie;
use App\Entity\DepenseFournisseur;
use App\Repository\DepenseCategorieRepository;
use App\Repository\DepenseFournisseurRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Service\Depense\DepenseBankImportParser;
use App\Repository\UtilisateurRepository;
use Doctrine\DBAL\{ArrayParameterType, Connection};
use Doctrine\ORM\QueryBuilder;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use App\Security\Permission\TenantPermission;

#[Route('/administrateur/{entite}/depense', name: 'app_administrateur_depense_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::DEPENSE_MANAGE, subject: 'entite')]
final class DepenseController extends AbstractController
{


  private const CAT_TYPES = ['operating', 'tax', 'payroll', 'finance', 'internal', 'other'];


  public function __construct(
    private UtilisateurEntiteManager $utilisateurEntiteManager,
    private DepenseUploader $depenseUploader,
  ) {}

  #[Route('', name: 'index', methods: ['GET'])]
  public function index(
    Entite $entite,
    DepenseCategorieRepository $catRepo,
    DepenseFournisseurRepository $fourRepo
  ): Response {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    $categories = $catRepo->createQueryBuilder('c')
      ->andWhere('c.entite = :e')->setParameter('e', $entite)
      ->andWhere('c.actif = 1')
      ->orderBy('c.libelle', 'ASC')
      ->getQuery()->getResult();

    $fournisseurs = $fourRepo->createQueryBuilder('f')
      ->andWhere('f.entite = :e')->setParameter('e', $entite)
      ->andWhere('f.actif = 1')
      ->orderBy('f.nom', 'ASC')
      ->getQuery()->getResult();

    return $this->render('administrateur/depense/index.html.twig', [
      'entite' => $entite,
      'categories' => $categories,
      'fournisseurs' => $fournisseurs,

    ]);
  }



  #[Route('/ajax', name: 'ajax', methods: ['POST'])]
  public function ajax(Entite $entite, Request $request, EM $em): JsonResponse
  {
    $draw   = $request->request->getInt('draw', 1);
    $start  = max(0, $request->request->getInt('start', 0));
    $length = $request->request->getInt('length', 25);
    if ($length <= 0) $length = 25;

    $searchV = (string)($request->request->all('search')['value'] ?? '');
    $order   = $request->request->all('order') ?? [];
    $tvaOnly = (string)$request->request->get('tvaOnly', 'all'); // all | deductible | nodeductible

    $catIds  = $request->request->all('catIds') ?? [];
    $fourIds = $request->request->all('fourIds') ?? [];

    $catIds  = array_values(array_filter(array_map('intval', (array)$catIds), fn($v) => $v > 0));
    $fourIds = array_values(array_filter(array_map('intval', (array)$fourIds), fn($v) => $v > 0));
    $catMode  = (string)$request->request->get('catMode', 'all');   // all | some | none
    $fourMode = (string)$request->request->get('fourMode', 'all'); // all | some | none


    $periodType    = (string) $request->request->get('periodType', 'year'); // all|year|quarter|month
    $periodYear    = $request->request->get('periodYear');
    $periodQuarter = $request->request->get('periodQuarter');
    $periodMonth   = $request->request->get('periodMonth');

    $periodYear    = ($periodYear !== null && $periodYear !== '') ? (int)$periodYear : null;
    $periodQuarter = ($periodQuarter !== null && $periodQuarter !== '') ? (int)$periodQuarter : null;
    $periodMonth   = ($periodMonth !== null && $periodMonth !== '') ? (int)$periodMonth : null;

    $range = $this->buildPeriodRange($periodType, $periodYear, $periodQuarter, $periodMonth);






    $qb = $em->getRepository(Depense::class)->createQueryBuilder('d')
      ->leftJoin('d.payeur', 'p')->addSelect('p')
      ->leftJoin('d.categorie', 'c')->addSelect('c')
      ->leftJoin('d.fournisseur', 'f')->addSelect('f')
      ->andWhere('d.entite = :e')->setParameter('e', $entite);

    $this->applyPeriodFilter($qb, 'd', $range);


    if ($searchV !== '') {
      $qb->andWhere('
        d.libelle LIKE :s
        OR c.libelle LIKE :s
        OR f.nom LIKE :s
      ')->setParameter('s', '%' . $searchV . '%');
    }



    if ($tvaOnly === 'deductible') {
      $qb->andWhere('d.tvaDeductible = 1');
    } elseif ($tvaOnly === 'nodeductible') {
      $qb->andWhere('d.tvaDeductible = 0');
    }




    // ✅ si "none" => 0 résultat (filtre explicite)
    if ($catMode === 'none') {
      $qb->andWhere('1=0');
    }
    if ($fourMode === 'none') {
      $qb->andWhere('1=0');
    }

    // ✅ si "some" => filtre IN normal
    if ($catMode === 'some' && !empty($catIds)) {
      $qb->andWhere('c.id IN (:catIds)')->setParameter('catIds', $catIds);
    }

    if ($fourMode === 'some' && !empty($fourIds)) {
      $qb->andWhere('f.id IN (:fourIds)')->setParameter('fourIds', $fourIds);
    }

    // ✅ si "all" => pas de filtre (on ne touche à rien)



    // total sans filtre (mais entité)
    $qbTotal = $em->getRepository(Depense::class)->createQueryBuilder('d2')
      ->select('COUNT(d2.id)')
      ->andWhere('d2.entite = :e')->setParameter('e', $entite);

    $this->applyPeriodFilter($qbTotal, 'd2', $range);

    $recordsTotal = (int) $qbTotal->getQuery()->getSingleScalarResult();


    // total filtré (search + tvaOnly)
    $qbCount = (clone $qb)
      ->resetDQLPart('orderBy')
      ->setFirstResult(null)
      ->setMaxResults(null)
      ->select('COUNT(d.id)');

    $recordsFiltered = (int)$qbCount->getQuery()->getSingleScalarResult();

    // tri DataTables (selon ton template premium)
    $colIdx = isset($order[0]['column']) ? (int)$order[0]['column'] : 1;
    $dir    = (isset($order[0]['dir']) && strtolower($order[0]['dir']) === 'asc') ? 'ASC' : 'DESC';

    $orderBy = match ($colIdx) {
      0 => 'd.id',
      1 => 'd.dateDepense',
      2 => 'd.libelle',
      3 => 'c.libelle',

      // ✅ NEW col 4
      4 => 'f.nom',

      // TTC/TVA...
      5 => 'd.montantTtcCents',
      6 => 'd.montantTvaCents',

      // Col 7 = TVA déductible recalculée
      7 => 'd.montantTvaCents',

      // Col 8 = %
      8 => 'd.tvaDeductiblePct',

      // Col 9 = Déductible (bool)
      9 => 'd.tvaDeductible',

      default => 'd.dateDepense',
    };




    $qb->orderBy($orderBy, $dir);

    $rows = $qb->setFirstResult($start)
      ->setMaxResults($length)
      ->getQuery()
      ->getResult();





    $data = [];
    foreach ($rows as $dep) {

      $f = $dep->getFournisseur();
      $color = $f?->getCouleurHex();
      $nomF = $f?->getNom();

      $fournisseurHtml = '—';
      if ($nomF) {
        $dot = $color ? '<span style="display:inline-block;width:10px;height:10px;border-radius:999px;background:' . $color . ';margin-right:.5rem;vertical-align:middle;"></span>' : '';
        $fournisseurHtml = '<span class="d-inline-flex align-items-center">' . $dot . '<span class="fw-semibold">' . htmlspecialchars($nomF, ENT_QUOTES) . '</span></span>';
      }

      /** @var Depense $dep */
      $data[] = [
        'id' => $dep->getId(),
        'date' => $dep->getDateDepense()->format('d/m/Y'),
        'libelle' => $dep->getLibelle(),
        'categorie' => $dep->getCategorie()?->getLibelle() ?: '—',
        'fournisseur' => $fournisseurHtml,
        'ttc' => $this->moneyCell($dep->getMontantTtcCents(), 'ttc'),
        'tva' => $this->moneyCell($dep->getMontantTvaCents(), 'tva'),
        'tvaPct' => $dep->isTvaDeductible()
          ? number_format($dep->getTvaDeductiblePct(), 1, ',', ' ') . '&nbsp;%'
          : '—',
        'tvaDed' => $this->moneyCell($dep->getTvaDeductibleCents(), 'tva'),
        'deductible' => $dep->isTvaDeductible()
          ? '<span class="badge rounded-pill bg-success-subtle text-success border border-success-subtle">Oui</span>'
          : '<span class="badge rounded-pill bg-secondary-subtle text-secondary border border-secondary-subtle">Non</span>',
        'actions' => $this->renderView('administrateur/depense/_actions.html.twig', [
          'd' => $dep,
          'entite' => $entite,
          // si tu veux utiliser le publicUrl du service dans le partial, passe-le en variable ou calcule là-bas
        ]),
      ];
    }

    // 1) QB "ALL" (juste entite)
    $qbAll = $em->getRepository(Depense::class)->createQueryBuilder('d')
      ->andWhere('d.entite = :e')
      ->setParameter('e', $entite);

    $this->applyPeriodFilter($qbAll, 'd', $range);



    $qbFilteredKpi = (clone $qb)
      ->setFirstResult(null)
      ->setMaxResults(null)
      ->resetDQLPart('orderBy');


    $rows = $qb->setFirstResult($start)
      ->setMaxResults($length)
      ->getQuery()
      ->getResult();




    // 2) QB "FILTRÉ" = tu reprends ton QB DataTables (celui qui sert à data/recordsFiltered)
    $qbFiltered = $qb; // <- ton QB actuel avant pagination

    $kpisAll = $this->computeKpis($qbAll);
    $kpis = $this->computeKpis($qbFiltered);

    return new JsonResponse([
      'draw' => $draw,
      'recordsTotal' => $recordsTotal,
      'recordsFiltered' => $recordsFiltered,
      'data' => $data,          // ✅ IMPORTANT
      'kpisAll' => $kpisAll,
      'kpis' => $kpis,
    ]);
  }

  // ...
  #[Route('/kpis', name: 'kpis', methods: ['GET'])]
  public function kpis(Entite $entite, EM $em): JsonResponse
  {
    $qb = $em->createQueryBuilder()
      ->from(Depense::class, 'd')
      ->andWhere('d.entite = :e')->setParameter('e', $entite);

    $count = (int)(clone $qb)->select('COUNT(d.id)')->getQuery()->getSingleScalarResult();
    $ttc   = (int)(clone $qb)->select('COALESCE(SUM(d.montantTtcCents),0)')->getQuery()->getSingleScalarResult();
    $ht    = (int)(clone $qb)->select('COALESCE(SUM(d.montantHtCents),0)')->getQuery()->getSingleScalarResult();
    $tva   = (int)(clone $qb)->select('COALESCE(SUM(d.montantTvaCents),0)')->getQuery()->getSingleScalarResult();

    $tvaDedRaw = (float)$em->createQueryBuilder()
      ->select('COALESCE(SUM(
        CASE WHEN d.tvaDeductible = 1
          THEN (d.montantTvaCents * (d.tvaDeductiblePct / 100))
          ELSE 0
        END
      ), 0)')
      ->from(Depense::class, 'd')
      ->andWhere('d.entite = :e')->setParameter('e', $entite)
      ->getQuery()->getSingleScalarResult();

    $tvaDed = (int) round($tvaDedRaw);

    // ✅ % global de TVA récupérable (sur la TVA totale)
    $tvaDedPctGlobal = $tva > 0 ? round(($tvaDed / $tva) * 100, 1) : 0.0;

    return $this->json([
      'count' => $count,
      'htCents' => $ht,
      'tvaCents' => $tva,
      'tvaDeductibleCents' => $tvaDed,
      'tvaDeductiblePctGlobal' => $tvaDedPctGlobal, // ✅ NEW
      'ttcCents' => $ttc,
    ]);
  }


  #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
  public function new(Entite $entite, Request $req, EM $em): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();


    $dep = (new Depense())
      ->setEntite($entite)
      ->setCreateur($user)
      ->setPayeur($user);

    // optionnel (si tu veux forcer au new)
    if (!$dep->getDevise()) $dep->setDevise('EUR');
    if ($dep->getTauxTva() === null) $dep->setTauxTva(20.0);

    $form = $this->createForm(DepenseType::class, $dep, [
      'entite' => $entite,
    ])->handleRequest($req);

    if ($form->isSubmitted() && $form->isValid()) {
      $this->recalcDepense($dep);

      $file = $form->get('justificatifFile')->getData();
      if ($file) {
        $dep->setJustificatifPath($this->depenseUploader->uploadProof($file));
      }

      $em->persist($dep);
      $em->flush();

      $this->addFlash('success', 'Dépense créée.');
      return $this->redirectToRoute('app_administrateur_depense_index', ['entite' => $entite->getId()]);
    }

    return $this->render('administrateur/depense/form.html.twig', [
      'form' => $form,
      'title' => 'Nouvelle dépense',
      'entite' => $entite,
    ]);
  }

  #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
  public function edit(Entite $entite, Depense $dep, Request $req, EM $em): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    if ($dep->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createAccessDeniedException('Dépense non autorisée pour cette entité.');
    }

    $form = $this->createForm(DepenseType::class, $dep, [
      'entite' => $entite,
    ])->handleRequest($req);

    if ($form->isSubmitted() && $form->isValid()) {
      $this->recalcDepense($dep);

      $file = $form->get('justificatifFile')->getData();
      if ($file) {
        // supprime l’ancien si existant
        $this->depenseUploader->deleteIfExists($dep->getJustificatifPath());
        $dep->setJustificatifPath($this->depenseUploader->uploadProof($file));
      }

      $dep->touch();
      $em->flush();

      $this->addFlash('success', 'Dépense mise à jour.');
      return $this->redirectToRoute('app_administrateur_depense_index', ['entite' => $entite->getId()]);
    }

    return $this->render('administrateur/depense/form.html.twig', [
      'form' => $form,
      'title' => 'Éditer dépense',
      'entite' => $entite,
    ]);
  }

  private function recalcDepense(Depense $d): void
  {
    // ✅ clamp du % 0..100 (toujours)
    $d->setTvaDeductiblePct((float)$d->getTvaDeductiblePct());

    // ✅ si TVA non déductible => 0%
    if (!$d->isTvaDeductible()) {
      $d->setTvaDeductiblePct(0);
    } elseif ($d->getTvaDeductiblePct() <= 0) {
      // optionnel : si déductible mais pct vide/0, on remet 100
      $d->setTvaDeductiblePct(100);
    }

    $taux = max(0.0, (float)$d->getTauxTva());
    $ttc  = (int)$d->getMontantTtcCents();
    $ht   = (int)$d->getMontantHtCents();

    if ($ttc > 0) {
      if ($taux <= 0.0001) {
        $d->setMontantHtCents($ttc);
        $d->setMontantTvaCents(0);
        return;
      }

      $htCalc  = (int) round($ttc / (1 + ($taux / 100)));
      $tvaCalc = max(0, $ttc - $htCalc);

      $d->setMontantHtCents($htCalc);
      $d->setMontantTvaCents($tvaCalc);
      return;
    }

    // fallback HT
    $tvaCalc = (int) round($ht * ($taux / 100));
    $d->setMontantTvaCents($tvaCalc);
    $d->setMontantTtcCents($ht + $tvaCalc);
  }


  private function moneyCell(int $cents, string $type): string
  {
    $val = number_format($cents / 100, 2, ',', ' ') . '&nbsp;€'; // ✅ insécable

    return match ($type) {
      'tva' => $cents > 0
        ? '<span class="badge rounded-pill bg-warning-subtle text-warning border border-warning-subtle px-2 py-1 nowrap">' . $val . '</span>'
        : '<span class="text-muted nowrap">0,00&nbsp;€</span>',
      default => '<span class="fw-semibold nowrap">' . $val . '</span>',
    };
  }






  #[Route('/ajax/fournisseur/create', name: 'ajax_fournisseur_create', methods: ['POST'])]
  public function ajaxCreateFournisseur(
    Entite $entite,
    Request $request,
    EM $em,
    DepenseFournisseurRepository $repo
  ): JsonResponse {
    $nom   = trim((string) $request->request->get('nom', ''));
    $siret = trim((string) $request->request->get('siret', ''));
    $siret = $siret !== '' ? $siret : null;

    // ✅ nouveau champ couleur
    $couleurHex = strtoupper(trim((string) $request->request->get('couleurHex', '')));
    if ($couleurHex === '') {
      $couleurHex = null;
    } elseif (!preg_match('/^#([A-F0-9]{6})$/', $couleurHex)) {
      return $this->json(['ok' => false, 'message' => 'Couleur invalide (format attendu: #RRGGBB).'], 400);
    }

    if ($nom === '') {
      return $this->json(['ok' => false, 'message' => 'Nom requis.'], 400);
    }

    $existing = $repo->findOneByEntiteAndNom($entite, $nom);
    if ($existing) {
      // (optionnel) si une couleur a été fournie, on peut la mettre à jour
      if ($couleurHex !== null && method_exists($existing, 'setCouleurHex')) {
        $existing->setCouleurHex($couleurHex);
        $em->flush();
      }

      return $this->json([
        'ok' => true,
        'id' => $existing->getId(),
        'text' => $existing->getNom(),
        'color' => method_exists($existing, 'getCouleurHex') ? $existing->getCouleurHex() : null,
        'created' => false,
      ]);
    }

    $f = (new DepenseFournisseur())
      ->setEntite($entite)
      ->setNom($nom)
      ->setSiret($siret);

    // ✅ persistance couleur si ton entité a setCouleurHex()
    if ($couleurHex !== null && method_exists($f, 'setCouleurHex')) {
      $f->setCouleurHex($couleurHex);
    }

    $em->persist($f);
    $em->flush();

    return $this->json([
      'ok' => true,
      'id' => $f->getId(),
      'text' => $f->getNom(),
      'color' => method_exists($f, 'getCouleurHex') ? $f->getCouleurHex() : null,
      'created' => true,
    ]);
  }



  #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
  public function delete(Entite $entite, Depense $dep, Request $req, EM $em): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    // 🔒 sécurité entité
    if ($dep->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createAccessDeniedException('Dépense non autorisée pour cette entité.');
    }

    // ✅ CSRF
    if (!$this->isCsrfTokenValid('depense_delete_' . $dep->getId(), (string)$req->request->get('_token'))) {
      $this->addFlash('danger', 'Token CSRF invalide.');
      return $this->redirectToRoute('app_administrateur_depense_index', ['entite' => $entite->getId()]);
    }

    // 🧹 supprime le justificatif si présent
    $this->depenseUploader->deleteIfExists($dep->getJustificatifPath());

    $em->remove($dep);
    $em->flush();

    // Si appel AJAX (DataTables), renvoie JSON
    if ($req->isXmlHttpRequest()) {
      return $this->json(['ok' => true]);
    }

    $this->addFlash('success', 'Dépense supprimée.');
    return $this->redirectToRoute('app_administrateur_depense_index', ['entite' => $entite->getId()]);
  }




  private function parseBool01(mixed $v, bool $default = false): bool
  {
    if ($v === null || $v === '') return $default;
    if (is_bool($v)) return $v;
    $s = strtolower(trim((string)$v));
    return in_array($s, ['1', 'true', 'on', 'yes'], true);
  }

  private function sanitizeCatType(?string $type): string
  {
    $t = strtolower(trim((string)$type));
    return in_array($t, self::CAT_TYPES, true) ? $t : 'operating';
  }

  #[Route('/ajax/categorie/create', name: 'ajax_categorie_create', methods: ['POST'])]
  public function ajaxCreateCategorie(
    Entite $entite,
    Request $request,
    EM $em,
    DepenseCategorieRepository $repo
  ): JsonResponse {
    $libelle = trim((string)$request->request->get('libelle', ''));
    if ($libelle === '') {
      return $this->json(['ok' => false, 'message' => 'Libellé requis.'], 400);
    }

    $type = $this->sanitizeCatType($request->request->get('type'));
    $actif = $this->parseBool01($request->request->get('actif', 1), true);
    $include = $this->parseBool01($request->request->get('includeInFinanceCharts', 1), true);

    $existing = $repo->findOneByEntiteAndLibelle($entite, $libelle);
    if ($existing) {
      // optionnel : sync champs même si doublon
      $existing->setType($type);
      $existing->setActif($actif);
      $existing->setIncludeInFinanceCharts($include);
      $em->flush();

      return $this->json([
        'ok' => true,
        'id' => $existing->getId(),
        'text' => $existing->getLibelle(),
        'type' => $existing->getType(),
        'actif' => $existing->isActif() ? 1 : 0,
        'includeInFinanceCharts' => $existing->isIncludeInFinanceCharts() ? 1 : 0,
        'created' => false,
      ]);
    }

    $c = (new DepenseCategorie())
      ->setEntite($entite)
      ->setLibelle($libelle)
      ->setType($type)
      ->setActif($actif)
      ->setIncludeInFinanceCharts($include);

    $em->persist($c);
    $em->flush();

    return $this->json([
      'ok' => true,
      'id' => $c->getId(),
      'text' => $c->getLibelle(),
      'type' => $c->getType(),
      'actif' => $c->isActif() ? 1 : 0,
      'includeInFinanceCharts' => $c->isIncludeInFinanceCharts() ? 1 : 0,
      'created' => true,
    ]);
  }


  #[Route('/ajax/categorie/{id}/update', name: 'ajax_categorie_update', methods: ['POST'], requirements: ['id' => '\d+'])]
  public function ajaxUpdateCategorie(
    Entite $entite,
    DepenseCategorie $categorie,
    Request $request,
    EM $em,
    DepenseCategorieRepository $repo
  ): JsonResponse {
    if ($categorie->getEntite()?->getId() !== $entite->getId()) {
      throw new NotFoundHttpException();
    }

    $libelle = trim((string)$request->request->get('libelle', ''));
    if ($libelle === '') {
      return $this->json(['ok' => false, 'message' => 'Libellé requis.'], 400);
    }

    $type = $this->sanitizeCatType($request->request->get('type'));
    $actif = $this->parseBool01($request->request->get('actif', $categorie->isActif() ? 1 : 0), $categorie->isActif());
    $include = $this->parseBool01(
      $request->request->get('includeInFinanceCharts', $categorie->isIncludeInFinanceCharts() ? 1 : 0),
      $categorie->isIncludeInFinanceCharts()
    );

    $existing = $repo->findOneByEntiteAndLibelle($entite, $libelle);
    if ($existing && $existing->getId() !== $categorie->getId()) {
      return $this->json(['ok' => false, 'message' => 'Une catégorie avec ce libellé existe déjà.'], 409);
    }

    $categorie
      ->setLibelle($libelle)
      ->setType($type)
      ->setActif($actif)
      ->setIncludeInFinanceCharts($include);

    $em->flush();

    return $this->json([
      'ok' => true,
      'id' => $categorie->getId(),
      'text' => $categorie->getLibelle(),
      'type' => $categorie->getType(),
      'actif' => $categorie->isActif() ? 1 : 0,
      'includeInFinanceCharts' => $categorie->isIncludeInFinanceCharts() ? 1 : 0,
    ]);
  }




  #[Route('/ajax/fournisseur/{id}/update', name: 'ajax_fournisseur_update', methods: ['POST'])]
  public function ajaxUpdateFournisseur(
    Entite $entite,
    DepenseFournisseur $fournisseur,
    Request $request,
    EM $em,
    DepenseFournisseurRepository $repo
  ): JsonResponse {
    // 🔒 sécurité entité
    if ($fournisseur->getEntite()?->getId() !== $entite->getId()) {
      throw new NotFoundHttpException();
    }

    $nom   = trim((string)$request->request->get('nom', ''));
    $siret = trim((string)$request->request->get('siret', ''));
    $siret = $siret !== '' ? $siret : null;

    $couleurHex = strtoupper(trim((string)$request->request->get('couleurHex', '')));
    if ($couleurHex === '') {
      $couleurHex = null;
    } elseif (!preg_match('/^#([A-F0-9]{6})$/', $couleurHex)) {
      return $this->json(['ok' => false, 'message' => 'Couleur invalide (format attendu: #RRGGBB).'], 400);
    }

    if ($nom === '') {
      return $this->json(['ok' => false, 'message' => 'Nom requis.'], 400);
    }

    // anti-duplication (même entité)
    $existing = $repo->findOneByEntiteAndNom($entite, $nom);
    if ($existing && $existing->getId() !== $fournisseur->getId()) {
      return $this->json(['ok' => false, 'message' => 'Un fournisseur avec ce nom existe déjà.'], 409);
    }

    $fournisseur
      ->setNom($nom)
      ->setSiret($siret)
      ->setCouleurHex($couleurHex);

    $em->flush();

    return $this->json([
      'ok' => true,
      'id' => $fournisseur->getId(),
      'text' => $fournisseur->getNom(),
      'color' => $fournisseur->getCouleurHex(),
    ]);
  }


  #[Route('/{id}/proof', name: 'proof', methods: ['GET'])]
  public function proof(Entite $entite, Depense $dep): Response
  {
    if ($dep->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createAccessDeniedException();
    }

    $abs = $this->depenseUploader->absolutePath($dep->getJustificatifPath());
    if (!$abs || !is_file($abs)) {
      throw new NotFoundHttpException('Justificatif introuvable.');
    }

    $res = new BinaryFileResponse($abs);

    // ✅ inline = affichage dans iframe (sinon téléchargement)
    $res->setContentDisposition(
      ResponseHeaderBag::DISPOSITION_INLINE,
      basename($abs)
    );

    // ✅ autoriser iframe same-origin
    $res->headers->set('X-Frame-Options', 'SAMEORIGIN');
    $res->headers->set('Content-Security-Policy', "frame-ancestors 'self'");

    // ✅ content-type correct
    $mime = @mime_content_type($abs) ?: 'application/pdf';
    $res->headers->set('Content-Type', $mime);

    return $res;
  }



  #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
  public function show(Entite $entite, Depense $dep): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    // 🔒 sécurité entité
    if ($dep->getEntite()?->getId() !== $entite->getId()) {
      throw new NotFoundHttpException();
    }

    return $this->render('administrateur/depense/show.html.twig', [
      'entite' => $entite,
      'd' => $dep,
    ]);
  }



  #[Route('/import', name: 'import', methods: ['GET'])]
  public function import(Entite $entite, EM $em): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    return $this->render('administrateur/depense/import.html.twig', [
      'entite' => $entite,
    ]);
  }

  #[Route('/import/template/{format}', name: 'import_template', methods: ['GET'], requirements: ['format' => 'csv|xlsx'])]
  public function importTemplate(Entite $entite, string $format): Response
  {
    // Modèle minimal : Date | Libellé | Débit euros | Crédit euros
    if ($format === 'csv') {
      $csv = "Date;Libellé;Débit euros;Crédit euros\n";
      $csv .= "26/01/2026;Ex: Achat matériel;49,90;\n";

      $res = new Response($csv);
      $res->headers->set('Content-Type', 'text/csv; charset=UTF-8');
      $res->headers->set('Content-Disposition', 'attachment; filename="modele-import-depenses.csv"');
      return $res;
    }

    // XLSX
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray(['Date', 'Libellé', 'Débit euros', 'Crédit euros'], null, 'A1');
    $sheet->fromArray(['26/01/2026', 'Ex: Achat matériel', '49,90', ''], null, 'A2');
    $sheet->getStyle('A1:D1')->getFont()->setBold(true);

    $writer = new Xlsx($spreadsheet);

    $response = new StreamedResponse(function () use ($writer) {
      $writer->save('php://output');
    });

    $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $response->headers->set('Content-Disposition', 'attachment; filename="modele-import-depenses.xlsx"');

    return $response;
  }

  #[Route('/import/preview', name: 'import_preview', methods: ['POST'])]
  public function importPreview(
    Entite $entite,
    Request $request,
    EM $em,
    DepenseBankImportParser $parser,
    DepenseCategorieRepository $catRepo,
    DepenseFournisseurRepository $fourRepo,
    UtilisateurRepository $userRepo,
  ): Response {
    /** @var Utilisateur $user */
    $user = $this->getUser();



    /** @var UploadedFile|null $file */
    $file = $request->files->get('importFile');
    if (!$file) {
      $this->addFlash('danger', 'Aucun fichier reçu.');
      return $this->redirectToRoute('app_administrateur_depense_import', ['entite' => $entite->getId()]);
    }

    $ext = strtolower($file->getClientOriginalExtension());
    if (!in_array($ext, ['csv', 'xlsx', 'xls'], true)) {
      $this->addFlash('danger', 'Format invalide. Autorisé : CSV, XLSX.');
      return $this->redirectToRoute('app_administrateur_depense_import', ['entite' => $entite->getId()]);
    }

    $tmp = $file->getPathname();
    $rows = $parser->parse($tmp, $file->getClientOriginalName());

    // Pré-remplissage TVA par défaut (ex: 20, déductible oui, pct 100)
    $proposals = [];
    foreach ($rows as $i => $r) {
      $date = $r['date'];
      $ttcCents = (int)$r['debitCents'];

      // défaut: on suppose TTC saisi => calc HT/TVA selon taux
      $taux = 20.0;
      [$htCents, $tvaCents] = $this->calcFromTtc($ttcCents, $taux);

      $proposals[] = [
        'idx' => $i,
        'date' => $date?->format('Y-m-d'),
        'dateFr' => $date?->format('d/m/Y'),
        'libelle' => $r['libelle'],
        'devise' => 'EUR',
        'tauxTva' => $taux,
        'tvaDeductible' => true,
        'tvaDeductiblePct' => 80.0,

        'montantHtCents' => $htCents,
        'montantTvaCents' => $tvaCents,
        'montantTtcCents' => $ttcCents,

        'categorieId' => null,
        'fournisseurId' => null,
        'payeurId' => $user->getId(),
        'raw' => $r['raw'],
      ];
    }

    // Détection doublons (même date + même TTC)
    $dupMap = $this->buildDuplicateMap($em, $entite, $proposals);

    // listes pour selects
    $categories = $catRepo->qbForEntite($entite)->getQuery()->getResult();
    $fournisseurs = $fourRepo->qbForEntite($entite)->getQuery()->getResult();
    $payeurs = $userRepo->createQueryBuilder('u')->orderBy('u.nom', 'ASC')->getQuery()->getResult();

    return $this->render('administrateur/depense/import_preview.html.twig', [
      'entite' => $entite,
      'proposals' => $proposals,
      'dupMap' => $dupMap,
      'categories' => $categories,
      'fournisseurs' => $fournisseurs,
      'payeurs' => $payeurs,


    ]);
  }



  #[Route('/import/dup-check', name: 'import_dup_check', methods: ['POST'])]
  public function importDupCheck(Entite $entite, Request $request, EM $em): JsonResponse
  {
    $dateStr  = (string) $request->request->get('date', '');
    $ttcCents = (int) $request->request->get('ttcCents', 0);

    $date = \DateTimeImmutable::createFromFormat('Y-m-d', $dateStr);
    if (!$date || $ttcCents <= 0) {
      return $this->json(['exists' => false]);
    }

    $min = $date->setTime(0, 0, 0);
    $max = $date->setTime(23, 59, 59);

    $row = $em->createQueryBuilder()
      ->select('d.id')
      ->from(Depense::class, 'd')
      ->andWhere('d.entite = :e')->setParameter('e', $entite)
      ->andWhere('d.montantTtcCents = :ttc')->setParameter('ttc', $ttcCents)
      ->andWhere('d.dateDepense BETWEEN :min AND :max')
      ->setParameter('min', $min)
      ->setParameter('max', $max)
      ->orderBy('d.id', 'DESC')
      ->setMaxResults(1)
      ->getQuery()
      ->getOneOrNullResult();

    return $this->json([
      'exists'    => (bool) $row,
      'depenseId' => $row ? (int) $row['id'] : null,
    ]);
  }





  #[Route('/import/commit', name: 'import_commit', methods: ['POST'])]
  public function importCommit(
    Entite $entite,
    Request $request,
    EM $em,
  ): Response {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    $rows = $request->request->all('rows');
    if (!$rows || !is_array($rows)) {
      $this->addFlash('danger', 'Aucune ligne à importer.');
      return $this->redirectToRoute('app_administrateur_depense_import', ['entite' => $entite->getId()]);
    }

    $created = 0;

    foreach ($rows as $idx => $r) {
      $accept = (bool)($r['accept'] ?? false);
      if (!$accept) continue;

      $date = \DateTimeImmutable::createFromFormat('Y-m-d', (string)($r['date'] ?? ''));
      if (!$date) continue;

      $ttcCents = (int)($r['montantTtcCents'] ?? 0);
      $htCents  = (int)($r['montantHtCents'] ?? 0);
      $tvaCents = (int)($r['montantTvaCents'] ?? 0);

      // recalc “béton” si TTC présent (prioritaire)
      $taux = (float)($r['tauxTva'] ?? 0);
      if ($ttcCents > 0) {
        [$htCents, $tvaCents] = $this->calcFromTtc($ttcCents, $taux);
      } else {
        // fallback HT
        $tvaCents = (int) round($htCents * ($taux / 100));
        $ttcCents = $htCents + $tvaCents;
      }

      $d = (new Depense())
        ->setEntite($entite)
        ->setCreateur($user)
        ->setLibelle(trim((string)($r['libelle'] ?? '')))
        ->setDateDepense($date)
        ->setDevise((string)($r['devise'] ?? 'EUR'))
        ->setTauxTva($taux)
        ->setTvaDeductible((bool)($r['tvaDeductible'] ?? false))
        ->setTvaDeductiblePct((float)($r['tvaDeductiblePct'] ?? 0))
        ->setMontantHtCents($htCents)
        ->setMontantTvaCents($tvaCents)
        ->setMontantTtcCents($ttcCents);

      // clamp logique identique à ta recalcDepense()
      if (!$d->isTvaDeductible()) {
        $d->setTvaDeductiblePct(0);
      } elseif ($d->getTvaDeductiblePct() <= 0) {
        $d->setTvaDeductiblePct(100);
      }

      // relations
      $catId = $r['categorieId'] ?? null;
      if ($catId) {
        $cat = $em->getRepository(DepenseCategorie::class)->find((int)$catId);
        if ($cat && $cat->getEntite()?->getId() === $entite->getId()) $d->setCategorie($cat);
      }

      $fourId = $r['fournisseurId'] ?? null;
      if ($fourId) {
        $four = $em->getRepository(DepenseFournisseur::class)->find((int)$fourId);
        if ($four && $four->getEntite()?->getId() === $entite->getId()) $d->setFournisseur($four);
      }

      $payeurId = $r['payeurId'] ?? null;
      if ($payeurId) {
        $payeur = $em->getRepository(Utilisateur::class)->find((int)$payeurId);
        if ($payeur) $d->setPayeur($payeur);
      }

    // justificatif par ligne : input name="proofs[IDX]"
      /** @var UploadedFile|null $proof */
      $allProofs = $request->files->get('proofs') ?? [];
      $proof = $allProofs[$idx] ?? null;

      if ($proof) {
        $d->setJustificatifPath($this->depenseUploader->uploadProof($proof));
      }

      $em->persist($d);
      $created++;
    }

    $em->flush();

    $this->addFlash('success', $created . ' dépense(s) importée(s).');
    return $this->redirectToRoute('app_administrateur_depense_index', ['entite' => $entite->getId()]);
  }

  /** @return array{0:int,1:int} */
  private function calcFromTtc(int $ttcCents, float $taux): array
  {
    $taux = max(0.0, $taux);
    if ($ttcCents <= 0) return [0, 0];
    if ($taux <= 0.0001) return [$ttcCents, 0];

    $ht = (int) round($ttcCents / (1 + ($taux / 100)));
    $tva = max(0, $ttcCents - $ht);
    return [$ht, $tva];
  }

  /**
   * @param array<int,array<string,mixed>> $proposals
   * @return array<string,array{exists:bool, depenseId:int}>
   */
  private function buildDuplicateMap(EM $em, Entite $entite, array $proposals): array
  {
    $amounts = [];
    $min = null;
    $max = null;

    foreach ($proposals as $p) {
      $ttc = (int)($p['montantTtcCents'] ?? 0);
      if ($ttc > 0) $amounts[] = $ttc;

      $ds = $p['date'] ?? null; // 'Y-m-d'
      if ($ds) {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', (string)$ds);
        if ($d) {
          $min = $min ? min($min, $d) : $d;
          $max = $max ? max($max, $d) : $d;
        }
      }
    }


    $amounts = array_values(array_unique($amounts));
    if (!$amounts || !$min || !$max) return [];

    // Important : couvrir toute la journée (si dateDepense est datetime)
    $minDt = $min->setTime(0, 0, 0);
    $maxDt = $max->setTime(23, 59, 59);

    $qb = $em->createQueryBuilder()
      ->select('d.id, d.montantTtcCents, d.dateDepense')
      ->from(Depense::class, 'd')
      ->andWhere('d.entite = :e')->setParameter('e', $entite)
      ->andWhere('d.montantTtcCents IN (:am)')
      ->andWhere('d.dateDepense BETWEEN :min AND :max')
      ->setParameter('min', $minDt)
      ->setParameter('max', $maxDt);

    // types array montants (DBAL2/3)
    if (class_exists(ArrayParameterType::class)) {
      // DBAL 3+
      $qb->setParameter('am', $amounts, ArrayParameterType::INTEGER);
    } else {
      // DBAL 2.x (sans référencer une constante dépréciée en dur)
      $paramConst = Connection::class . '::PARAM_INT_ARRAY';
      if (defined($paramConst)) {
        $qb->setParameter('am', $amounts, constant($paramConst));
      } else {
        // fallback ultime (au cas où)
        $qb->setParameter('am', $amounts);
      }
    }

    $res = $qb->getQuery()->getArrayResult();

    $map = [];
    foreach ($res as $r) {
      // selon hydrator, dateDepense peut être string OU DateTime
      $dt = $r['dateDepense'];
      if ($dt instanceof \DateTimeInterface) {
        $dateKey = $dt->format('Y-m-d');
      } else {
        // fallback string (ex: '2025-12-01 00:00:00' ou '2025-12-01')
        $dateKey = (new \DateTimeImmutable((string)$dt))->format('Y-m-d');
      }

      $key = $dateKey . '|' . (int)$r['montantTtcCents'];
      $map[$key] = ['exists' => true, 'depenseId' => (int)$r['id']];
    }

    return $map;
  }


  #[Route('/import/existing/{id}', name: 'import_existing', methods: ['GET'], requirements: ['id' => '\d+'])]
  public function importExisting(Entite $entite, Depense $dep): JsonResponse
  {
    if ($dep->getEntite()?->getId() !== $entite->getId()) {
      throw new NotFoundHttpException();
    }

    return $this->json([
      'id' => $dep->getId(),
      'date' => $dep->getDateDepense()?->format('Y-m-d'),
      'dateFr' => $dep->getDateDepense()?->format('d/m/Y'),
      'libelle' => $dep->getLibelle(),

      'categorie' => $dep->getCategorie()?->getLibelle(),
      'fournisseur' => $dep->getFournisseur()?->getNom(),
      'payeur' => $dep->getPayeur() ? trim(($dep->getPayeur()->getNom() . ' ' . $dep->getPayeur()->getPrenom())) : null,

      'devise' => $dep->getDevise(),
      'tauxTva' => (float)$dep->getTauxTva(),

      'tvaDeductible' => (bool)$dep->isTvaDeductible(),
      'tvaDeductiblePct' => (float)$dep->getTvaDeductiblePct(),

      'montantHtCents' => (int)$dep->getMontantHtCents(),
      'montantTvaCents' => (int)$dep->getMontantTvaCents(),
      'montantTtcCents' => (int)$dep->getMontantTtcCents(),

      'proofUrl' => $dep->getJustificatifPath()
        ? $this->generateUrl('app_administrateur_depense_proof', ['entite' => $entite->getId(), 'id' => $dep->getId()])
        : null,

      'showUrl' => $this->generateUrl('app_administrateur_depense_show', ['entite' => $entite->getId(), 'id' => $dep->getId()]),
    ]);
  }


  private function computeKpis(QueryBuilder $qb): array
  {
    // Clone + suppression pagination + pas d'order
    $q = (clone $qb)
      ->resetDQLPart('orderBy')
      ->setFirstResult(null)
      ->setMaxResults(null);

    // KPI simples (count / ttc / tva)
    $k = (clone $q)
      ->resetDQLPart('select')
      ->select('COUNT(d.id) as cnt')
      ->addSelect('COALESCE(SUM(d.montantTtcCents),0) as ttc')
      ->addSelect('COALESCE(SUM(d.montantTvaCents),0) as tva')
      ->getQuery()
      ->getSingleResult();

    // TVA déductible recalculée (comme ta route /kpis)
    $tvaDedRaw = (float) (clone $q)
      ->resetDQLPart('select')
      ->select('COALESCE(SUM(
      CASE WHEN d.tvaDeductible = 1
        THEN (d.montantTvaCents * (d.tvaDeductiblePct / 100))
        ELSE 0
      END
    ), 0)')
      ->getQuery()
      ->getSingleScalarResult();

    $tvaDed = (int) round($tvaDedRaw);

    return [
      'count' => (int) ($k['cnt'] ?? 0),
      'ttcCents' => (int) ($k['ttc'] ?? 0),
      'tvaCents' => (int) ($k['tva'] ?? 0),
      'tvaDeductibleCents' => $tvaDed,
    ];
  }


  private function buildPeriodRange(
    string $type,
    ?int $year,
    ?int $quarter,
    ?int $month
  ): ?array {
    $type = strtolower(trim($type));

    if ($type === 'all') return null;

    $y = $year ?: (int) (new \DateTimeImmutable('now'))->format('Y');

    if ($type === 'year') {
      $from = (new \DateTimeImmutable("$y-01-01"))->setTime(0, 0, 0);
      $to   = (new \DateTimeImmutable("$y-12-31"))->setTime(23, 59, 59);
      return [$from, $to];
    }

    if ($type === 'quarter') {
      $q = max(1, min(4, (int)($quarter ?: 1)));
      $startMonth = (($q - 1) * 3) + 1;
      $from = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $y, $startMonth)))->setTime(0, 0, 0);
      $to   = $from->modify('+3 months')->modify('-1 second'); // fin du trimestre
      return [$from, $to];
    }

    if ($type === 'month') {
      $m = max(1, min(12, (int)($month ?: 1)));
      $from = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m)))->setTime(0, 0, 0);
      $to   = $from->modify('+1 month')->modify('-1 second'); // fin du mois
      return [$from, $to];
    }

    return null;
  }

  private function applyPeriodFilter(QueryBuilder $qb, string $alias, ?array $range): void
  {
    if (!$range) return;
    [$from, $to] = $range;

    // dateDepense est un datetime => between inclusif
    $qb->andWhere(sprintf('%s.dateDepense BETWEEN :pFrom AND :pTo', $alias))
      ->setParameter('pFrom', $from)
      ->setParameter('pTo', $to);
  }


  #[Route('/{id}/proof/delete', name: 'proof_delete', methods: ['POST'])]
  public function proofDelete(Entite $entite, Depense $dep, Request $req, EM $em): JsonResponse
  {
    if ($dep->getEntite()?->getId() !== $entite->getId()) {
      return $this->json(['ok' => false, 'message' => 'Accès refusé.'], 403);
    }

    if (!$this->isCsrfTokenValid('depense_proof_delete_' . $dep->getId(), (string)$req->request->get('_token'))) {
      return $this->json(['ok' => false, 'message' => 'Token CSRF invalide.'], 400);
    }

    $path = $dep->getJustificatifPath();
    if ($path) {
      $this->depenseUploader->deleteIfExists($path);
    }

    $dep->setJustificatifPath(null);
    $dep->touch();
    $em->flush();

    return $this->json(['ok' => true]);
  }



  #[Route('/scan-receipt', name: 'scan_receipt', methods: ['POST'])]
  public function scanReceipt(
    Request $req,
    ReceiptScanService $scanService
  ): JsonResponse {
    // 🔒 sécurité (entité) : tu l’as déjà via IsGranted/UtilisateurEntiteManager ailleurs


    $cred = $_SERVER['GOOGLE_APPLICATION_CREDENTIALS'] ?? getenv('GOOGLE_APPLICATION_CREDENTIALS') ?? '';
    if (!$cred || !is_file($cred) || !is_readable($cred)) {
      return $this->json([
        'ok' => false,
        'message' => 'Credentials introuvables ou illisibles',
        'path' => $cred,
        'is_file' => is_file($cred),
        'readable' => is_readable($cred),
        'host' => gethostname(),
      ], 500);
    }


    /** @var UploadedFile|null $file */
    $file = $req->files->get('file');
    if (!$file) {
      return $this->json(['ok' => false, 'message' => 'Aucun fichier reçu.'], 400);
    }

    // ✅ autorise image + pdf (photo ticket ou justificatif PDF)
    $allowed = [
      'application/pdf',
      'image/jpeg',
      'image/png',
      'image/webp',
    ];
    $mime = $file->getMimeType() ?: '';
    if (!in_array($mime, $allowed, true)) {
      return $this->json(['ok' => false, 'message' => 'Format invalide (PDF/JPG/PNG/WebP).'], 400);
    }

    if ($file->getSize() > 8 * 1024 * 1024) {
      return $this->json(['ok' => false, 'message' => 'Fichier trop lourd (max 8 Mo).'], 400);
    }

    try {
      $proposal = $scanService->scan($file);

      return $this->json([
        'ok' => true,
        'proposal' => $proposal, // champs + confidence + debug
      ]);
    } catch (\Throwable $e) {
      return $this->json([
        'ok' => false,
        'message' => 'Analyse impossible : ' . $e->getMessage(),
      ], 500);
    }
  }
}
