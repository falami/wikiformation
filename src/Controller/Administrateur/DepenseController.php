<?php
// src/Controller/Administrateur/DepenseController.php

namespace App\Controller\Administrateur;

use App\Service\Filter\{AccountingPeriodFilter, ChoiceFilter};

use App\Entity\{Depense, Entite, Utilisateur};
use App\Form\Administrateur\DepenseType;
use App\Service\Depense\DepenseUploader;
use App\Service\Depense\ReceiptScanService;
use App\Service\Depense\ExpenseImportInput;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
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
    private DepenseUploader $depenseUploader,
    private LoggerInterface $logger,
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

    $periodYear ??= (string) (new \DateTimeImmutable())->format('Y');
    $periodQuarter ??= 'all';
    $periodMonth ??= 'all';
    $period = [$periodType, $periodYear, $periodMonth, $periodQuarter];






    $qb = $em->getRepository(Depense::class)->createQueryBuilder('d')
      ->leftJoin('d.payeur', 'p')->addSelect('p')
      ->leftJoin('d.categorie', 'c')->addSelect('c')
      ->leftJoin('d.fournisseur', 'f')->addSelect('f')
      ->andWhere('d.entite = :e')->setParameter('e', $entite);

    AccountingPeriodFilter::apply($qb, 'd.dateDepense', ...$period);


    if ($searchV !== '') {
      $qb->andWhere('
        d.libelle LIKE :s
        OR c.libelle LIKE :s
        OR f.nom LIKE :s
      ')->setParameter('s', '%' . $searchV . '%');
    }



    ChoiceFilter::any($qb, $tvaOnly, static function (QueryBuilder $branch, string $value): void {
      if ($value === 'deductible') $branch->andWhere('d.tvaDeductible = 1');
      elseif ($value === 'nodeductible') $branch->andWhere('d.tvaDeductible = 0');
    });




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

    AccountingPeriodFilter::apply($qbTotal, 'd2.dateDepense', ...$period);

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

    AccountingPeriodFilter::apply($qbAll, 'd.dateDepense', ...$period);



    $qbFilteredKpi = (clone $qb)
      ->setFirstResult(null)
      ->setMaxResults(null)
      ->resetDQLPart('orderBy');


    $rows = $qb->setFirstResult($start)
      ->setMaxResults($length)
      ->getQuery()
      ->getResult();




    // 2) QB "FILTRÉ" = tu reprends ton QB DataTables (celui qui sert à data/recordsFiltered)
    $qbFiltered = $qbFilteredKpi; // Agrégats sur tous les résultats filtrés, avant pagination.

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
      ->setPayeur($user)
      ->setTvaDeductible(false)->setTvaDeductiblePct(0);

    $sourceContract = null;
    if ($req->query->getInt('contrat') > 0) {
      $sourceContract = $em->getRepository(\App\Entity\ContratFormateur::class)->findOneBy([
        'id' => $req->query->getInt('contrat'), 'entite' => $entite,
      ]);
      if (!$sourceContract) throw $this->createNotFoundException();
      if ($sourceContract->getStatus() === \App\Enum\ContratFormateurStatus::RESILIE) {
        throw $this->createAccessDeniedException('Ce contrat est résilié.');
      }
      $dep->setLibelle('Facture formateur · ' . $sourceContract->getNumero() . ' · ' . $sourceContract->getSession()?->getCode())
        ->setMontantHtCents($sourceContract->getTotalHtCents())
        ->setMontantTvaCents($sourceContract->getMontantTvaCents())
        ->setMontantTtcCents($sourceContract->getTotalTtcCents())
        ->setTauxTva($sourceContract->isAssujettiTva() ? ($sourceContract->getTauxTva() ?? 0) : 0)
        ->setTvaDeductible(false)->setTvaDeductiblePct(100)
        ->setMeta(['contratFormateurId' => $sourceContract->getId()]);
    }

    // optionnel (si tu veux forcer au new)
    if (!$dep->getDevise()) $dep->setDevise('EUR');
    if ($dep->getTauxTva() === null) $dep->setTauxTva(20.0);

    $submissions = $req->getSession()->get('expense_new_submissions', []);
    $submissions = array_filter($submissions, static fn(array $item): bool => $item['expires'] > time());
    $submittedData = $req->request->all('depense');
    $submission = $req->isMethod('POST') ? (string)($submittedData['submissionToken'] ?? '') : bin2hex(random_bytes(24));
    if (!$req->isMethod('POST')) {
      $submissions[$submission] = ['entite'=>$entite->getId(), 'expires'=>time()+7200];
      $req->getSession()->set('expense_new_submissions', array_slice($submissions, -10, null, true));
    }
    $form = $this->createForm(DepenseType::class, $dep, ['entite' => $entite]);
    $form->add('submissionToken', \Symfony\Component\Form\Extension\Core\Type\HiddenType::class, ['mapped'=>false, 'data'=>$submission]);
    $form->handleRequest($req);
    if ($form->isSubmitted()) {
      if (!isset($submissions[$submission]) || $submissions[$submission]['entite'] !== $entite->getId()) {
        $form->addError(new \Symfony\Component\Form\FormError('Ce formulaire a expiré. Ouvrez une nouvelle dépense.'));
      } else {
        $dep->setImportKey(hash('sha256', 'manual:' . $entite->getId() . ':' . $user->getId() . ':' . $submission));
        if ($em->getRepository(Depense::class)->findOneBy(['importKey'=>$dep->getImportKey()])) {
          $this->addFlash('info', 'Cette dépense a déjà été enregistrée.');
          return $this->redirectToRoute('app_administrateur_depense_index', ['entite'=>$entite->getId()]);
        }
      }
    }

    if ($form->isSubmitted() && $sourceContract && $dep->isTvaDeductible() && !$form->get('justificatifFile')->getData()) {
      $form->get('justificatifFile')->addError(new \Symfony\Component\Form\FormError('Joignez la facture du formateur pour justifier la TVA déductible.'));
    }

    if ($form->isSubmitted()) $this->validateExpenseAmounts($form, $dep);

    if ($form->isSubmitted() && $form->isValid()) {
      $this->recalcDepense($dep);

      $file = $form->get('justificatifFile')->getData();
      $uploaded = null;
      try {
        if ($file) $dep->setJustificatifPath($uploaded = $this->depenseUploader->uploadProof($file));
        $em->persist($dep);
        $em->flush();
      } catch (\Throwable $error) {
        $this->depenseUploader->deleteIfExists($uploaded);
        $this->logger->error('Expense creation failed.', ['exception' => $error]);
        $form->addError(new \Symfony\Component\Form\FormError('Enregistrement impossible. Réessayez et sélectionnez à nouveau le justificatif.'));
        return $this->render('administrateur/depense/form.html.twig', ['form'=>$form, 'title'=>'Nouvelle dépense', 'sourceContract'=>$sourceContract, 'entite'=>$entite], new Response(status: 422));
      }

      $this->addFlash('success', 'Dépense créée.');
      return $this->redirectToRoute('app_administrateur_depense_index', ['entite' => $entite->getId()]);
    }

    return $this->render('administrateur/depense/form.html.twig', [
      'form' => $form,
      'title' => 'Nouvelle dépense',
      'sourceContract' => $sourceContract,
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

    if ($form->isSubmitted()) $this->validateExpenseAmounts($form, $dep);

    if ($form->isSubmitted() && $form->isValid()) {
      $this->recalcDepense($dep);

      $file = $form->get('justificatifFile')->getData();
      $oldProof = $dep->getJustificatifPath();
      $uploaded = null;
      try {
        if ($file) $dep->setJustificatifPath($uploaded = $this->depenseUploader->uploadProof($file));
        $dep->touch();
        $em->flush();
      } catch (\Throwable $error) {
        $this->depenseUploader->deleteIfExists($uploaded);
        $dep->setJustificatifPath($oldProof);
        $this->logger->error('Expense update failed.', ['exception' => $error]);
        $form->addError(new \Symfony\Component\Form\FormError('Enregistrement impossible. L’ancien justificatif a été conservé ; sélectionnez à nouveau le nouveau fichier.'));
        return $this->render('administrateur/depense/form.html.twig', ['form'=>$form, 'title'=>'Éditer dépense', 'entite'=>$entite], new Response(status: 422));
      }
      if ($uploaded) $this->depenseUploader->deleteIfExists($oldProof);

      $this->addFlash('success', 'Dépense mise à jour.');
      return $this->redirectToRoute('app_administrateur_depense_index', ['entite' => $entite->getId()]);
    }

    return $this->render('administrateur/depense/form.html.twig', [
      'form' => $form,
      'title' => 'Éditer dépense',
      'entite' => $entite,
    ]);
  }

  private function validateExpenseAmounts(\Symfony\Component\Form\FormInterface $form, Depense $expense): void
  {
    if (!$form->isSynchronized()) return;
    if (!$expense->isAmountsManual() && $expense->getMontantTtcCents() <= 0 && round($expense->getMontantHtCents() * (1 + $expense->getTauxTva() / 100)) > 2000000000) {
      $form->get('montantTtcCents')->addError(new \Symfony\Component\Form\FormError('Le montant TTC calculé dépasse la limite autorisée.'));
    }
    if ($expense->getMontantTtcCents() <= 0 && $expense->getMontantHtCents() <= 0) {
      $form->get('montantTtcCents')->addError(new \Symfony\Component\Form\FormError('Saisissez un montant supérieur à zéro.'));
    }
    if ($expense->isAmountsManual() && ($expense->getMontantTtcCents() <= 0 || $expense->getMontantHtCents() + $expense->getMontantTvaCents() !== $expense->getMontantTtcCents())) {
      $form->get('montantTvaCents')->addError(new \Symfony\Component\Form\FormError('Les montants du justificatif doivent vérifier HT + TVA = TTC.'));
    }
  }

  private function recalcDepense(Depense $d): void
  {
    // ✅ clamp du % 0..100 (toujours)
    $d->setTvaDeductiblePct((float)$d->getTvaDeductiblePct());

    // ✅ si TVA non déductible => 0%
    if (!$d->isTvaDeductible()) {
      $d->setTvaDeductiblePct(0);

    }

    if ($d->isAmountsManual()) return;

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
    if (!$this->isCsrfTokenValid('expense_choices_' . $entite->getId(), (string)$request->request->get('_token'))) throw $this->createAccessDeniedException('Session expirée. Rechargez la page.');

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

    $proof = $dep->getJustificatifPath();
    $em->remove($dep);
    $em->flush();
    $this->depenseUploader->deleteIfExists($proof);

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
    if (!$this->isCsrfTokenValid('expense_choices_' . $entite->getId(), (string)$request->request->get('_token'))) throw $this->createAccessDeniedException('Session expirée. Rechargez la page.');

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
    if (!$this->isCsrfTokenValid('expense_choices_' . $entite->getId(), (string)$request->request->get('_token'))) throw $this->createAccessDeniedException('Session expirée. Rechargez la page.');

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
    if (!$this->isCsrfTokenValid('expense_choices_' . $entite->getId(), (string)$request->request->get('_token'))) throw $this->createAccessDeniedException('Session expirée. Rechargez la page.');

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
    \App\Service\Depense\ExpenseImportRules $importRules,
    DepenseCategorieRepository $catRepo,
    DepenseFournisseurRepository $fourRepo,
    UtilisateurRepository $userRepo,
  ): Response {
    if (!$this->isCsrfTokenValid('expense_import_preview_' . $entite->getId(), (string)$request->request->get('_token'))) throw $this->createAccessDeniedException('Session expirée. Rechargez la page.');
    /** @var Utilisateur $user */
    $user = $this->getUser();



    /** @var UploadedFile|null $file */
    $file = $request->files->get('importFile');
    if (!$file instanceof UploadedFile || !$file->isValid() || !$file->getSize() || $file->getSize() > 10 * 1024 * 1024) {
      $this->addFlash('danger', 'Fichier absent, incomplet ou trop volumineux (maximum 10 Mo).');
      return $this->redirectToRoute('app_administrateur_depense_import', ['entite' => $entite->getId()]);
    }

    $ext = strtolower($file->getClientOriginalExtension());
    if (!in_array($ext, ['csv', 'xlsx', 'xls'], true)) {
      $this->addFlash('danger', 'Format invalide. Autorisé : CSV, XLSX.');
      return $this->redirectToRoute('app_administrateur_depense_import', ['entite' => $entite->getId()]);
    }

    $tmp = $file->getPathname();
    try {
      $rows = $parser->parse($tmp, $file->getClientOriginalName());
      if (!$rows) throw new \RuntimeException('Aucune dépense débitée trouvée dans le fichier.');
      if (count($rows) > 1000) throw new \RuntimeException('Import limité à 1 000 dépenses à la fois. Séparez votre relevé en plusieurs fichiers.');
    } catch (\RuntimeException $error) {
      $this->addFlash('danger', $error->getMessage());
      return $this->redirectToRoute('app_administrateur_depense_import', ['entite'=>$entite->getId()]);
    } catch (\Throwable $error) {
      $this->logger->warning('Expense import file could not be read.', ['exception'=>$error]);
      $this->addFlash('danger', 'Fichier illisible ou endommagé. Vérifiez son format puis réessayez.');
      return $this->redirectToRoute('app_administrateur_depense_import', ['entite'=>$entite->getId()]);
    }

    // Pré-remplissage TVA par défaut (ex: 20, déductible oui, pct 100)
    $proposals = [];
    foreach ($rows as $i => $r) {
      $date = $r['date'];
      $ttcCents = (int)$r['debitCents'];

      // défaut: on suppose TTC saisi => calc HT/TVA selon taux
      $taux = 0.0;
      [$htCents, $tvaCents] = $this->calcFromTtc($ttcCents, $taux);

      $proposals[] = [
        'idx' => $i,
        'date' => $date?->format('Y-m-d'),
        'dateFr' => $date?->format('d/m/Y'),
        'libelle' => $r['libelle'],
        'devise' => 'EUR',
        'tauxTva' => $taux,
        'tvaDeductible' => false,
        'tvaDeductiblePct' => 0.0,

        'montantHtCents' => $htCents,
        'montantTvaCents' => $tvaCents,
        'montantTtcCents' => $ttcCents,

        'categorieId' => null,
        'fournisseurId' => null,
        'payeurId' => $user->getId(),
        'raw' => $r['raw'],
      ];
    }

    $proposals = $importRules->apply($entite, $proposals);

    // Détection doublons (même date + même TTC)
    $dupMap = $this->buildDuplicateMap($em, $entite, $proposals);

    // listes pour selects
    $categories = $catRepo->qbForEntite($entite)->getQuery()->getResult();
    $fournisseurs = $fourRepo->qbForEntite($entite)->getQuery()->getResult();
    $payeurs = $this->expensePayers($em, $entite);
    $batch = bin2hex(random_bytes(24));
    $batches = $request->getSession()->get('expense_import_batches', []);
    $batches = array_filter($batches, static fn(array $b): bool => $b['expires'] > time());
    $batches[$batch] = ['entite'=>$entite->getId(), 'user'=>$user->getId(), 'count'=>count($proposals), 'expires'=>time()+7200];
    $request->getSession()->set('expense_import_batches', array_slice($batches, -10, null, true));

    return $this->render('administrateur/depense/import_preview.html.twig', [
      'entite' => $entite,
      'proposals' => $proposals,
      'importBatch' => $batch,
      'importErrors' => [],
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

    $date = ExpenseImportInput::date($dateStr);
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
  public function importCommit(Entite $entite, Request $request, EM $em, ExpenseImportInput $input, LockFactory $locks): Response
  {
    if (!$this->isCsrfTokenValid('expense_import_commit_' . $entite->getId(), (string)$request->request->get('_token'))) throw $this->createAccessDeniedException('Session expirée. Rechargez la page.');
    $user = $this->getUser();
    $batchId = (string)$request->request->get('importBatch', '');
    $batches = $request->getSession()->get('expense_import_batches', []);
    $batch = $batches[$batchId] ?? null;
    if (!$batch || $batch['entite'] !== $entite->getId() || $batch['user'] !== $user->getId() || $batch['expires'] < time()) {
      $this->addFlash('danger', 'Cet aperçu a expiré. Importez à nouveau le fichier.');
      return $this->redirectToRoute('app_administrateur_depense_import', ['entite'=>$entite->getId()]);
    }
    if (!empty($batch['completed'])) {
      $this->addFlash('info', 'Cet import a déjà été enregistré. Aucune dépense n’a été ajoutée une seconde fois.');
      return $this->redirectToRoute('app_administrateur_depense_index', ['entite'=>$entite->getId()]);
    }
    try {
      $json = $request->request->get('rowsJson');
      if (is_string($json) && strlen($json) > 8 * 1024 * 1024) throw new \InvalidArgumentException();
      $rows = is_string($json) && $json !== '' ? json_decode($json, true, 32, JSON_THROW_ON_ERROR) : $request->request->all('rows');
      if ($request->request->get('payloadComplete') !== '1' || !is_array($rows) || count($rows) !== $batch['count'] || array_keys($rows) !== range(0, $batch['count'] - 1)) throw new \InvalidArgumentException();
      foreach ($rows as $row) {
        if (!is_array($row)) throw new \InvalidArgumentException();
        foreach ($row as $value) if (!is_scalar($value) && $value !== null) throw new \InvalidArgumentException();
      }
    } catch (\Throwable) {
      $this->addFlash('danger', 'Import incomplet ou invalide. Aucune dépense enregistrée : rechargez le fichier et réessayez avec moins de lignes.');
      return $this->redirectToRoute('app_administrateur_depense_import', ['entite'=>$entite->getId()]);
    }

    $lock = $locks->createLock('expense-import-' . $entite->getId(), 300);
    if (!$lock->acquire()) return $this->renderImportRetry($request, $entite, $em, $rows, $batchId, ['Un autre import est en cours. Réessayez dans quelques instants.']);
    $uploaded = [];
    try {
      $errors = [];
      $expenses = [];
      $seen = [];
      $allProofs = $request->files->get('proofs', []);
      foreach ($rows as $index => $raw) {
        $accept = $raw['accept'] ?? '0';
        if (!in_array($accept, ['0', '1', 0, 1, false, true], true)) { $errors[] = 'Ligne ' . ($index + 1) . ' : choix d’import invalide.'; continue; }
        if (in_array($accept, ['0', 0, false], true)) continue;
        try {
          $r = $input->validate($raw);
          $key = hash('sha256', $batchId . ':' . $index);
          if ($em->getRepository(Depense::class)->findOneBy(['importKey'=>$key])) continue;
          $d = (new Depense())->setEntite($entite)->setCreateur($user)->setImportKey($key)
            ->setLibelle($r['libelle'])->setDateDepense($r['dateObject'])->setDevise($r['devise'])
            ->setTauxTva($r['tauxTva'])->setTvaDeductible($r['tvaDeductible'])
            ->setTvaDeductiblePct($r['tvaDeductible'] ? $r['tvaDeductiblePct'] : 0);
          [$ht, $vat] = $this->calcFromTtc($r['montantTtcCents'], $r['tauxTva']);
          $d->setAmountsManual($ht !== $r['montantHtCents'] || $vat !== $r['montantTvaCents'])
            ->setMontantHtCents($r['montantHtCents'])->setMontantTvaCents($r['montantTvaCents'])->setMontantTtcCents($r['montantTtcCents']);
          foreach (['categorieId'=>[DepenseCategorie::class,'setCategorie'], 'fournisseurId'=>[DepenseFournisseur::class,'setFournisseur']] as $field=>$relation) {
            if (!$r[$field]) continue;
            $record = $em->getRepository($relation[0])->findOneBy(['id'=>$r[$field], 'entite'=>$entite]);
            if (!$record) throw new \InvalidArgumentException('Catégorie ou fournisseur indisponible pour cet organisme.');
            $d->{$relation[1]}($record);
          }
          if ($r['payeurId']) {
            $payer = $em->getRepository(Utilisateur::class)->find($r['payeurId']);
            $member = $payer ? $em->getRepository(\App\Entity\UtilisateurEntite::class)->findOneBy(['entite'=>$entite, 'utilisateur'=>$payer]) : null;
            if (!$member || !$member->isActive()) throw new \InvalidArgumentException('Le payeur doit appartenir à cet organisme.');
            $d->setPayeur($payer);
          }
          $duplicateKey = $r['date'].'|'.$r['devise'].'|'.$r['montantTtcCents'];
          $duplicate = isset($seen[$duplicateKey]) || $em->createQueryBuilder()->select('COUNT(d.id)')->from(Depense::class, 'd')
            ->where('d.entite = :entity AND d.devise = :currency AND d.montantTtcCents = :amount AND d.dateDepense BETWEEN :start AND :end')
            ->setParameter('entity', $entite)->setParameter('currency', $r['devise'])->setParameter('amount', $r['montantTtcCents'])->setParameter('start', $r['dateObject'])->setParameter('end', $r['dateObject']->setTime(23,59,59))
            ->getQuery()->getSingleScalarResult() > 0;
          if ($duplicate && !$r['allowDuplicate']) throw new \InvalidArgumentException('Une dépense a déjà cette date et ce montant. Confirmez le doublon potentiel ou décochez la ligne.');
          $seen[$duplicateKey] = true;
          $proof = is_array($allProofs) ? ($allProofs[$index] ?? null) : null;
          if ($proof !== null) {
            if (!$proof instanceof UploadedFile) throw new \InvalidArgumentException('Justificatif invalide.');
            DepenseUploader::validateProof($proof);
          }
          if (!empty($raw['hasProof']) && !$proof) throw new \InvalidArgumentException('Le justificatif sélectionné n’a pas été reçu. Sélectionnez-le à nouveau.');
          $expenses[] = [$d, $proof];
        } catch (\InvalidArgumentException $error) {
          $errors[] = 'Ligne ' . ($index + 1) . ' : ' . $error->getMessage();
        }
      }
      if (!$expenses && !$errors) $errors[] = 'Aucune nouvelle ligne sélectionnée. Sélectionnez au moins une dépense à importer.';
      if ($errors) return $this->renderImportRetry($request, $entite, $em, $rows, $batchId, $errors);
      // Validate everything first; then save the batch atomically.
      $em->getConnection()->beginTransaction();
      try {
        foreach ($expenses as [$expense, $proof]) {
          if ($proof) {
            $filename = $this->depenseUploader->uploadProof($proof);
            $uploaded[] = $filename;
            $expense->setJustificatifPath($filename);
          }
          $em->persist($expense);
        }
        $em->flush();
        $em->getConnection()->commit();
      } catch (\Throwable $error) {
        if ($em->getConnection()->isTransactionActive()) $em->getConnection()->rollBack();
        foreach ($uploaded as $filename) $this->depenseUploader->deleteIfExists($filename);
        throw $error;
      }
      $batches[$batchId]['completed'] = true;
      $request->getSession()->set('expense_import_batches', $batches);
      $this->addFlash('success', count($expenses) . ' dépense(s) importée(s).');
      return $this->redirectToRoute('app_administrateur_depense_index', ['entite'=>$entite->getId()]);
    } catch (\Throwable $error) {
      $this->logger->error('Expense import failed.', ['exception'=>$error, 'entite'=>$entite->getId()]);
      $this->addFlash('danger', 'L’import n’a pas pu être enregistré. Aucune ligne de cette tentative n’a été ajoutée. Réessayez depuis un nouvel aperçu.');
      return $this->redirectToRoute('app_administrateur_depense_import', ['entite'=>$entite->getId()]);
    } finally {
      $lock->release();
    }
  }

  private function expensePayers(EM $em, Entite $entite): array
  {
    return $em->getRepository(Utilisateur::class)->createQueryBuilder('u')->distinct()
      ->innerJoin('u.utilisateurEntites', 'ue')->where('ue.entite = :e AND ue.status = :status')
      ->setParameter('e', $entite)->setParameter('status', 'active')->orderBy('u.nom', 'ASC')->getQuery()->getResult();
  }

  private function renderImportRetry(Request $request, Entite $entite, EM $em, array $rows, string $batch, array $errors): Response
  {
    $proposals = [];
    foreach ($rows as $index=>$row) {
      $date = ExpenseImportInput::date((string)($row['date'] ?? ''));
      foreach (['montantHtCents','montantTvaCents','montantTtcCents','tauxTva','tvaDeductiblePct'] as $field) {
        $value = str_replace(',', '.', (string)($row[$field] ?? '0'));
        $row[$field] = is_numeric($value) && is_finite((float)$value) ? max(0, min(2000000000, (float)$value)) : 0;
      }
      foreach (['accept','tvaDeductible','allowDuplicate'] as $field) $row[$field] = in_array($row[$field] ?? false, [true, 1, '1'], true);
      $proposals[] = array_replace(['libelle'=>'', 'devise'=>'EUR', 'tauxTva'=>0, 'tvaDeductible'=>false, 'tvaDeductiblePct'=>0,
        'montantHtCents'=>0, 'montantTvaCents'=>0, 'montantTtcCents'=>0, 'categorieId'=>null, 'fournisseurId'=>null, 'payeurId'=>null, 'accept'=>false, 'allowDuplicate'=>false], $row, ['idx'=>$index, 'dateFr'=>$date?->format('d/m/Y'), 'date'=>$date?->format('Y-m-d')]);
    }
    return $this->render('administrateur/depense/import_preview.html.twig', [
      'entite'=>$entite, 'proposals'=>$proposals, 'importBatch'=>$batch, 'importErrors'=>$errors,
      'dupMap'=>$this->buildDuplicateMap($em, $entite, $proposals),
      'categories'=>$em->getRepository(DepenseCategorie::class)->qbForEntite($entite)->getQuery()->getResult(),
      'fournisseurs'=>$em->getRepository(DepenseFournisseur::class)->qbForEntite($entite)->getQuery()->getResult(),
      'payeurs'=>$this->expensePayers($em, $entite),
    ], new Response(status: 422));
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
    $fileDuplicates = [];
    $seenFile = [];
    $min = null;
    $max = null;

    foreach ($proposals as $p) {
      $ttc = (int)($p['montantTtcCents'] ?? 0);
      if ($ttc > 0) $amounts[] = $ttc;

      $ds = $p['date'] ?? null; // 'Y-m-d'
      if ($ds) {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', (string)$ds);
        if ($d) {
          $key = $d->format('Y-m-d') . '|' . $ttc;
          if (isset($seenFile[$key])) $fileDuplicates[$key] = ['exists'=>true, 'depenseId'=>null];
          $seenFile[$key] = true;
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

    $map = $fileDuplicates;
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
      'amountsManual' => $dep->isAmountsManual(),

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
    $dep->setJustificatifPath(null);
    $dep->touch();
    $em->flush();
    $this->depenseUploader->deleteIfExists($path);

    return $this->json(['ok' => true]);
  }



  #[Route('/scan-receipt', name: 'scan_receipt', methods: ['POST'])]
  public function scanReceipt(
    Entite $entite,
    Request $req,
    ReceiptScanService $scanService
  ): JsonResponse {
    if (!$this->isCsrfTokenValid('expense_scan_' . $entite->getId(), (string)$req->request->get('_token'))) {
      return $this->json(['ok'=>false, 'message'=>'Session expirée. Rechargez la page.'], 403);
    }
    $file = $req->files->get('file');
    if (!$file instanceof UploadedFile) return $this->json(['ok'=>false, 'message'=>'Aucun fichier reçu.'], 400);
    try {
      DepenseUploader::validateProof($file);
      $proposal = $scanService->scan($file);
      return $this->json(['ok'=>true, 'proposal'=>$proposal]);
    } catch (\InvalidArgumentException $error) {
      return $this->json(['ok'=>false, 'message'=>$error->getMessage()], 422);
    } catch (\Throwable $error) {
      $this->logger->error('Receipt analysis failed.', ['exception'=>$error]);
      return $this->json(['ok'=>false, 'message'=>'L’analyse est indisponible. Réessayez ou renseignez la dépense manuellement ; le justificatif peut toujours être joint.'], 502);
    }
  }
}
