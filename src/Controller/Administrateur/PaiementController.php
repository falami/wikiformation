<?php

namespace App\Controller\Administrateur;

use App\Entity\{Facture, Paiement, Utilisateur, Entite, Entreprise};
use App\Form\Administrateur\PaiementType;
use App\Enum\FactureStatus;
use App\Enum\ModePaiement;
use App\Repository\PaiementRepository;
use Doctrine\ORM\EntityManagerInterface as EM;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse, RedirectResponse};
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Doctrine\DBAL\ParameterType;
use App\Security\Permission\TenantPermission;
use App\Service\Billing\InscriptionBillingSync;





#[Route('/administrateur/{entite}/paiement', name: 'app_administrateur_paiement_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::PAIEMENT_MANAGE, subject: 'entite')]
final class PaiementController extends AbstractController
{
    public function __construct(
        #[Autowire('%upload_proofs_dir%')] private string $proofDir,
        private readonly InscriptionBillingSync $inscSync, // ✅
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Entite $entite, EM $em): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        // Payeurs utilisateurs distincts (root = Utilisateur)
        $payeurUsers = $em->createQueryBuilder()
            ->select('DISTINCT u')
            ->from(Utilisateur::class, 'u')
            ->innerJoin(Paiement::class, 'p', 'WITH', 'p.payeurUtilisateur = u')
            ->andWhere('p.entite = :e')
            ->setParameter('e', $entite)
            ->orderBy('u.nom', 'ASC')
            ->addOrderBy('u.prenom', 'ASC')
            ->getQuery()
            ->getResult();

        // Payeurs entreprises distincts (root = Entreprise)
        $payeurEntreprises = $em->createQueryBuilder()
            ->select('DISTINCT e')
            ->from(Entreprise::class, 'e')   // ⚠️ adapte si ton entité s’appelle autrement
            ->innerJoin(Paiement::class, 'p', 'WITH', 'p.payeurEntreprise = e')
            ->andWhere('p.entite = :e')
            ->setParameter('e', $entite)
            ->orderBy('e.raisonSociale', 'ASC')
            ->getQuery()
            ->getResult();


        return $this->render('administrateur/paiement/index.html.twig', [
            'entite' => $entite,
            'title'  => 'Paiements',
            'payeurUsers' => $payeurUsers,
            'payeurEntreprises' => $payeurEntreprises,

        ]);
    }


    // -------------------------
    // DATATABLE AJAX
    // -------------------------
    #[Route('/ajax', name: 'ajax', methods: ['POST'])]
    public function ajax(Entite $entite, Request $request, EM $em): JsonResponse
    {
        $draw    = $request->request->getInt('draw', 1);
        $start   = max(0, $request->request->getInt('start', 0));
        $length  = max(1, $request->request->getInt('length', 10));
        $order   = $request->request->all('order') ?? [];
        $searchV = trim((string) (($request->request->all('search')['value'] ?? '') ?? ''));
        $modeFilter = (string) $request->request->get('modeFilter', 'all');
        $periodType   = (string) $request->request->get('periodType', 'all');
        $yearFilter   = (string) $request->request->get('yearFilter', 'all');
        $monthFilter  = (string) $request->request->get('monthFilter', 'all');
        $quarterFilter = (string) $request->request->get('quarterFilter', 'all');


        $payeurUserIds = $request->request->all('payeurUserIds') ?? [];
        $payeurUserIds = array_values(array_filter(array_map('intval', (array) $payeurUserIds)));

        $payeurEntrepriseIds = $request->request->all('payeurEntrepriseIds') ?? [];
        $payeurEntrepriseIds = array_values(array_filter(array_map('intval', (array) $payeurEntrepriseIds)));





        // mapping colonnes DataTables (8 colonnes)
        $map = [
            0 => 'p.id',
            1 => 'u.nom',
            2 => 'f.numero',
            3 => 'p.ventilationHtHorsDeboursCents',   // HT
            4 => 'p.ventilationTvaHorsDeboursCents',  // TVA
            5 => 'p.ventilationDeboursCents',         // Débours
            6 => 'p.montantCents',                    // TTC payé
            7 => 'p.mode',
            8 => 'p.datePaiement',
            9 => 'p.id', // actions
        ];


        $qbBase = $em->getRepository(Paiement::class)->createQueryBuilder('p')
            ->leftJoin('p.facture', 'f')
            ->leftJoin('p.payeurUtilisateur', 'u')
            ->leftJoin('p.payeurEntreprise', 'e')
            ->andWhere('p.entite = :entite')
            ->setParameter('entite', $entite);

        $recordsTotal = (int) (clone $qbBase)
            ->select('COUNT(p.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()->getSingleScalarResult();

        $qb = (clone $qbBase)->addSelect('f,u,e');

        // filtre mode
        $this->applyModeFilter($qb, 'p', $modeFilter);
        $this->applyPeriodFilter($qb, 'p', $periodType, $yearFilter, $monthFilter, $quarterFilter);
        $this->applyPayeurIdsFilter($qb, 'p', $payeurUserIds, $payeurEntrepriseIds);




        // search (gère numeric proprement)
        if ($searchV !== '') {
            if (ctype_digit($searchV)) {
                $id = (int) $searchV;
                $qb->andWhere('p.id = :sid OR f.id = :sid')
                    ->setParameter('sid', $id);
            } else {
                $qb->andWhere('
                    f.numero LIKE :s
                    OR u.nom LIKE :s OR u.prenom LIKE :s OR u.email LIKE :s
                    OR e.raisonSociale LIKE :s OR e.email LIKE :s
                ')->setParameter('s', '%' . $searchV . '%');
            }
        }

        $recordsFiltered = (int) (clone $qb)
            ->select('COUNT(DISTINCT p.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()->getSingleScalarResult();

        // order
        $orderColIdx = isset($order[0]['column']) ? (int) $order[0]['column'] : 0;
        $orderDir    = (isset($order[0]['dir']) && strtolower($order[0]['dir']) === 'asc') ? 'ASC' : 'DESC';
        $orderBy     = $map[$orderColIdx] ?? 'p.id';

        $rows = $qb->orderBy($orderBy, $orderDir)
            ->addOrderBy('p.id', 'DESC')
            ->setFirstResult($start)
            ->setMaxResults($length)
            ->getQuery()->getResult();






        $data = [];
        foreach ($rows as $p) {
            /** @var Paiement $p */
            $payeur = $p->getPayeurLabel();
            $fact = $p->getFacture();

            $vent = $this->paiementVentilationDisplayCents($p); // ['ht'=>..,'tva'=>..,'debours'=>..,'ttc'=>..]


            $factureHtml = '—';
            if ($fact) {
                $numeroF = $fact->getNumero() ?: '—';
                $noteF   = trim((string) ($fact->getNote() ?? ''));

                $factureHtml = '<div class="fact-num-wrap">'
                    . '<div class="fact-num">' . htmlspecialchars($numeroF, ENT_QUOTES) . '</div>';

                if ($noteF !== '') {
                    $tt = htmlspecialchars($noteF, ENT_QUOTES);
                    $preview = nl2br($tt);

                    $factureHtml .=
                        '<div class="fact-note" data-bs-toggle="tooltip" data-bs-placement="top" title="' . $tt . '">'
                        . '<span class="fact-note-text">' . $preview . '</span>'
                        . '</div>';
                }

                $factureHtml .= '</div>';
            }


            $data[] = [
                'id'      => $p->getId(),
                'deQui'   => $payeur !== '—' ? $payeur : ($fact?->getDestinataire()?->getEmail() ?? '—'),
                'facture' => $factureHtml,
                'montantHt'      => $this->moneyCell((int) $vent['ht'], 'ht'),
                'montantTva'     => $this->moneyCell((int) $vent['tva'], 'tva'),
                'montantDebours' => $this->moneyCell((int) $vent['debours'], 'debours'),
                'montantTtc'     => $this->moneyCell((int) $vent['ttc'], 'ttc'),

                'mode'    => $p->getMode()->label(),
                'date'    => $p->getDatePaiement()->format('d/m/Y'),
                'actions' => $this->renderView('administrateur/paiement/_actions.html.twig', [
                    'p' => $p,
                    'entite' => $entite,
                ]),
            ];
        }

        return new JsonResponse([
            'draw' => $draw,
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
        ]);
    }


    private function applyPayeurIdsFilter(QueryBuilder $qb, string $alias, array $userIds, array $entrepriseIds): void
    {
        if (empty($userIds) && empty($entrepriseIds)) {
            return;
        }

        $orX = $qb->expr()->orX();

        if (!empty($userIds)) {
            $orX->add($qb->expr()->in("$alias.payeurUtilisateur", ':pUsers'));
            $qb->setParameter('pUsers', $userIds);
        }

        if (!empty($entrepriseIds)) {
            $orX->add($qb->expr()->in("$alias.payeurEntreprise", ':pEnts'));
            $qb->setParameter('pEnts', $entrepriseIds);
        }

        $qb->andWhere($orX);
    }



    private function applyPayeurSplitFilter(QueryBuilder $qb, string $alias, array $userSel, array $entrepriseSel): void
    {
        // userSel / entrepriseSel contiennent 'yes' et/ou 'no'
        // vide = pas de filtre sur ce champ

        $clauses = [];

        if (!empty($userSel)) {
            $userOr = [];
            if (in_array('yes', $userSel, true)) $userOr[] = "$alias.payeurUtilisateur IS NOT NULL";
            if (in_array('no',  $userSel, true)) $userOr[] = "$alias.payeurUtilisateur IS NULL";
            if ($userOr) $clauses[] = '(' . implode(' OR ', $userOr) . ')';
        }

        if (!empty($entrepriseSel)) {
            $entOr = [];
            if (in_array('yes', $entrepriseSel, true)) $entOr[] = "$alias.payeurEntreprise IS NOT NULL";
            if (in_array('no',  $entrepriseSel, true)) $entOr[] = "$alias.payeurEntreprise IS NULL";
            if ($entOr) $clauses[] = '(' . implode(' OR ', $entOr) . ')';
        }

        if ($clauses) {
            // IMPORTANT : entre les deux filtres, on veut une intersection (AND)
            $qb->andWhere(implode(' AND ', $clauses));
        }
    }


    private function applyPeriodFilter(
        QueryBuilder $qb,
        string $alias,
        string $periodType,
        string $yearFilter,
        string $monthFilter,
        string $quarterFilter
    ): void {
        $periodType = $periodType ?: 'all';

        if ($periodType === 'all') {
            return;
        }

        // si month/quarter mais year=all => fallback année courante
        $now = new \DateTimeImmutable('now');
        $year = ($yearFilter !== '' && $yearFilter !== 'all') ? (int) $yearFilter : (int) $now->format('Y');

        $start = null;
        $end = null;

        if ($periodType === 'year') {
            $start = new \DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $year));
            $end   = $start->modify('+1 year');
        }

        if ($periodType === 'month') {
            $m = ($monthFilter !== '' && $monthFilter !== 'all') ? (int) $monthFilter : (int) $now->format('n');
            $m = max(1, min(12, $m));

            $start = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $m));
            $end   = $start->modify('+1 month');
        }

        if ($periodType === 'quarter') {
            $q = ($quarterFilter !== '' && $quarterFilter !== 'all') ? (int) $quarterFilter : 1;
            $q = max(1, min(4, $q));

            $firstMonth = 1 + (($q - 1) * 3);
            $start = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $firstMonth));
            $end   = $start->modify('+3 months');
        }

        if (!$start || !$end) return;

        $startParam = $alias . '_pStart';
        $endParam   = $alias . '_pEnd';

        $qb->andWhere("$alias.datePaiement >= :$startParam AND $alias.datePaiement < :$endParam")
            ->setParameter($startParam, $start)
            ->setParameter($endParam, $end);
    }


    // -------------------------
    // NEW
    // -------------------------
    #[Route('/nouveau', name: 'new', methods: ['GET', 'POST'])]
    public function new(Entite $entite, Request $req, EM $em, PaiementRepository $paiementRepo): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $p = new Paiement();
        $p->setCreateur($user);
        $p->setEntite($entite);
        $p->setDatePaiement(new \DateTimeImmutable());
        $p->setMode(ModePaiement::VIREMENT);

        // pré-remplissage si facture en querystring
        $factureId = $req->query->getInt('facture', 0);
        if ($factureId > 0) {
            /** @var Facture|null $facture */
            $facture = $em->getRepository(Facture::class)->find($factureId);

            if (!$facture || $facture->getEntite()?->getId() !== $entite->getId()) {
                throw $this->createNotFoundException('Facture introuvable.');
            }

            // ✅ payé (déjà en base)
            $paid = (int) $paiementRepo->sumPaidForFacture($facture->getId());

            // ✅ TTC hors débours (stocké sur facture)
            $ttcHorsDebours = (int) ($facture->getMontantTtcHorsDeboursCents() ?? 0);

            // ✅ débours TTC : on récupère via méthode si dispo, sinon fallback par SQL
            $deboursTtc = 0;

            if (method_exists($facture, 'getMontantDeboursTtcCents')) {
                $deboursTtc = (int) $facture->getMontantDeboursTtcCents();
            } else {
                // Fallback SQL (même logique que ton ajax)
                $sql = "
                SELECT COALESCE(SUM(
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
                ), 0) AS debours_ttc
                FROM ligne_facture
                WHERE facture_id = :fid AND is_debours = 1
            ";
                $deboursTtc = (int) $em->getConnection()->fetchOne(
                    $sql,
                    ['fid' => $facture->getId()],
                    ['fid' => ParameterType::INTEGER]
                );
            }

            // ✅ TTC total à payer = TTC hors débours + débours
            $ttcTotal = $ttcHorsDebours + $deboursTtc;

            // ✅ remaining basé sur TTC total
            $remaining = max(0, $ttcTotal - $paid);

            if ($remaining === 0) {
                $this->addFlash('info', 'Cette facture est déjà soldée.');
                return $this->redirectToRoute('app_administrateur_facture_index', [
                    'entite' => $entite->getId()
                ]);
            }

            $p->setFacture($facture);
            $p->setMontantCents($remaining);
            $p->setDevise($facture->getDevise() ?? 'EUR');

            // ✅ auto-payeur : entreprise destinataire sinon user destinataire
            if (method_exists($facture, 'getEntrepriseDestinataire') && $facture->getEntrepriseDestinataire()) {
                $p->setPayeurEntreprise($facture->getEntrepriseDestinataire());
            } elseif ($facture->getDestinataire()) {
                $p->setPayeurUtilisateur($facture->getDestinataire());
            }
        }

        $form = $this->createForm(PaiementType::class, $p, ['entite' => $entite])
            ->handleRequest($req);

        if ($form->isSubmitted() && $form->isValid()) {

            // ✅ une seule fois (tu l’avais en double)
            $this->enforceExclusivePayeur($p);

            $this->handleJustificatifUpload($form->get('justificatif')->getData(), $p);

            // ✅ snapshot ventilation
            $this->hydrateVentilation($p);

            $em->persist($p);
            $em->flush();

            if ($p->getFacture()) {
                $this->syncFacturePaymentStatus($p->getFacture(), $paiementRepo, $em);
            }

            if ($p->getFacture()) {
                $this->syncFacturePaymentStatus($p->getFacture(), $paiementRepo, $em);

                // ✅ sync inscriptions impactées
                $this->inscSync->syncMany($p->getFacture()->getInscriptions()->toArray());
            }

            $this->addFlash('success', 'Paiement enregistré.');
            return $this->redirectToRoute('app_administrateur_paiement_index', [
                'entite' => $entite->getId()
            ]);
        }

        return $this->render('administrateur/paiement/form.html.twig', [
            'form' => $form,
            'title' => 'Nouveau paiement',
            'entite' => $entite,
        ]);
    }



    private function paiementVentilationDisplayCents(Paiement $p): array
    {
        $paidTtc = (int) ($p->getMontantCents() ?? 0);

        // ✅ Si ventilation déjà snapshotée, on l’utilise
        $ht = $p->getVentilationHtHorsDeboursCents();
        $tva = $p->getVentilationTvaHorsDeboursCents();
        $deb = $p->getVentilationDeboursCents();

        if ($ht !== null || $tva !== null || $deb !== null) {
            $ht  = (int) ($ht ?? 0);
            $tva = (int) ($tva ?? 0);
            $deb = (int) ($deb ?? 0);

            return [
                'ht' => $ht,
                'tva' => $tva,
                'debours' => $deb,
                'ttc' => $paidTtc, // TTC payé = montant du paiement
            ];
        }

        // ✅ Sinon fallback : on recalcule depuis la facture (même logique que hydrateVentilation)
        $f = $p->getFacture();
        if (!$f || $paidTtc <= 0) {
            return [
                'ht' => $paidTtc,  // pas de facture -> on ne sait pas ventiler
                'tva' => 0,
                'debours' => 0,
                'ttc' => $paidTtc,
            ];
        }

        $ttcTotal = $this->factureTtcTotalCents($f);        // ✅ TTC total (hors débours + débours)
        $ttcHd    = (int) $f->getMontantTtcHorsDeboursCents();
        $htHd     = (int) $f->getMontantHtHorsDeboursCents();


        if ($ttcTotal <= 0 || $ttcHd <= 0 || $htHd < 0) {
            return [
                'ht' => 0,
                'tva' => 0,
                'debours' => 0,
                'ttc' => $paidTtc,
            ];
        }

        // 1) part TTC hors débours payée (allocation proportionnelle)
        $paidTtcHd = (int) round($paidTtc * ($ttcHd / $ttcTotal));
        $paidDebours = max(0, $paidTtc - $paidTtcHd);

        // 2) conversion TTC(hd) -> HT(hd)
        $paidHtHd = (int) round($paidTtcHd * ($htHd / $ttcHd));
        $paidTvaHd = max(0, $paidTtcHd - $paidHtHd);

        return [
            'ht' => $paidHtHd,
            'tva' => $paidTvaHd,
            'debours' => $paidDebours,
            'ttc' => $paidTtc,
        ];
    }


    // -------------------------
    // EDIT
    // -------------------------
    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Entite $entite, Paiement $paiement, Request $req, EM $em, PaiementRepository $paiementRepo): Response
    {

        /** @var Utilisateur $user */
        $user = $this->getUser();

        if ($paiement->getEntite()?->getId() !== $entite->getId()) {
            throw $this->createAccessDeniedException('Paiement non autorisé pour cette entité.');
        }

        $form = $this->createForm(PaiementType::class, $paiement, ['entite' => $entite])
            ->handleRequest($req);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->enforceExclusivePayeur($paiement);

            $this->enforceExclusivePayeur($paiement);
            $this->handleJustificatifUpload($form->get('justificatif')->getData(), $paiement);

            // ✅ snapshot ventilation aussi en édition
            $this->hydrateVentilation($paiement);

            $em->flush();



            if ($paiement->getFacture()) {
                $this->syncFacturePaymentStatus($paiement->getFacture(), $paiementRepo, $em);
                $this->inscSync->syncMany($paiement->getFacture()->getInscriptions()->toArray());
            }

            $this->addFlash('success', 'Paiement mis à jour.');
            return $this->redirectToRoute('app_administrateur_paiement_index', [
                'entite' => $entite->getId()
            ]);
        }

        return $this->render('administrateur/paiement/form.html.twig', [
            'form' => $form,
            'title' => 'Éditer paiement',
            'entite' => $entite,

        ]);
    }

    // -------------------------
    // DELETE
    // -------------------------
    #[Route('/{id}/supprimer', name: 'delete', methods: ['POST'])]
    public function delete(Entite $entite, Paiement $paiement, Request $request, EM $em, PaiementRepository $paiementRepo): RedirectResponse
    {
        if ($paiement->getEntite()?->getId() !== $entite->getId()) {
            throw $this->createAccessDeniedException('Paiement non autorisé pour cette entité.');
        }

        if (!$this->isCsrfTokenValid('paiement_delete_' . $paiement->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF invalide.');
        }

        $facture = $paiement->getFacture();

        $em->remove($paiement);
        $em->flush();

        if ($facture) {
            $this->syncFacturePaymentStatus($facture, $paiementRepo, $em);
            $this->inscSync->syncMany($facture->getInscriptions()->toArray());
        }

        $this->addFlash('success', 'Paiement supprimé.');
        return $this->redirectToRoute('app_administrateur_paiement_index', [
            'entite' => $entite->getId()
        ]);
    }

    // -------------------------
    // KPIS
    // -------------------------
    // KPIS
    #[Route('/kpis', name: 'kpis', methods: ['GET'])]
    public function kpis(Entite $entite, EM $em, Request $request): JsonResponse
    {
        $modeFilter = (string) $request->query->get('modeFilter', 'all');
        $since = (new \DateTimeImmutable())->sub(new \DateInterval('P30D'));

        $periodType    = (string) $request->query->get('periodType', 'all');
        $yearFilter    = (string) $request->query->get('yearFilter', 'all');
        $monthFilter   = (string) $request->query->get('monthFilter', 'all');
        $quarterFilter = (string) $request->query->get('quarterFilter', 'all');

        // ✅ IDs payeurs (GET query) => mêmes noms que JS
        $payeurUserIds = $request->query->all('payeurUserIds') ?? [];
        $payeurUserIds = array_values(array_filter(array_map('intval', (array) $payeurUserIds)));

        $payeurEntrepriseIds = $request->query->all('payeurEntrepriseIds') ?? [];
        $payeurEntrepriseIds = array_values(array_filter(array_map('intval', (array) $payeurEntrepriseIds)));

        $baseQb = $em->createQueryBuilder()
            ->select('p', 'f')
            ->from(Paiement::class, 'p')
            ->leftJoin('p.facture', 'f')
            ->andWhere('p.entite = :e')
            ->setParameter('e', $entite);

        $this->applyModeFilter($baseQb, 'p', $modeFilter);
        $this->applyPeriodFilter($baseQb, 'p', $periodType, $yearFilter, $monthFilter, $quarterFilter);

        // ✅ on applique les IDs
        $this->applyPayeurIdsFilter($baseQb, 'p', $payeurUserIds, $payeurEntrepriseIds);

        /** @var Paiement[] $all */
        $all = $baseQb->getQuery()->getResult();

        $count = count($all);

        $sumHt = 0;
        $last30Count = 0;
        $last30SumHt = 0;

        foreach ($all as $p) {
            $ht = $p->getVentilationHtHorsDeboursCents();
            if ($ht === null) {
                $ht = $this->paiementHtHorsDeboursCents($p);
            }

            $sumHt += $ht;

            if ($p->getDatePaiement() >= $since) {
                $last30Count++;
                $last30SumHt += $ht;
            }
        }

        return new JsonResponse([
            'count' => $count,
            'sumCents' => $sumHt,
            'last30Count' => $last30Count,
            'last30SumCents' => $last30SumHt,
        ]);
    }




    // -------------------------
    // HELPERS
    // -------------------------
    private function applyModeFilter(QueryBuilder $qb, string $alias, string $modeFilter): void
    {
        if ($modeFilter === '' || $modeFilter === 'all') return;

        $enum = match ($modeFilter) {
            'virement' => ModePaiement::VIREMENT,
            'cb'       => ModePaiement::CB,
            'cheque'   => ModePaiement::CHEQUE,
            'especes'  => ModePaiement::ESPECES,
            'opco'     => ModePaiement::OPCO,
            default    => null,
        };

        if ($enum) {
            $qb->andWhere("$alias.mode = :mode")->setParameter('mode', $enum);
            return;
        }

        if ($modeFilter === 'autre') {
            $qb->andWhere("$alias.mode NOT IN (:modes)")
                ->setParameter('modes', [
                    ModePaiement::VIREMENT,
                    ModePaiement::CB,
                    ModePaiement::CHEQUE,
                    ModePaiement::ESPECES,
                    ModePaiement::OPCO,
                ]);
        }
    }

    private function enforceExclusivePayeur(Paiement $p): void
    {
        if ($p->getPayeurEntreprise()) {
            $p->setPayeurUtilisateur(null);
        } elseif ($p->getPayeurUtilisateur()) {
            $p->setPayeurEntreprise(null);
        }
    }

    private function handleJustificatifUpload(?UploadedFile $file, Paiement $p): void
    {
        if (!$file instanceof UploadedFile) return;

        @mkdir($this->proofDir, 0775, true);

        $ext = $file->guessExtension() ?: 'bin';
        $name = uniqid('proof_', true) . '.' . $ext;

        $file->move($this->proofDir, $name);
        $p->setJustificatif($name);
    }

    private function syncFacturePaymentStatus(Facture $facture, PaiementRepository $paiementRepo, EM $em): void
    {
        if ($facture->getStatus() === FactureStatus::CANCELED) return;

        $ttcTotal = $facture->getTtcTotalCents();
        $paid     = (int) $paiementRepo->sumPaidForFacture($facture->getId());
        $remaining = max(0, $ttcTotal - $paid);

        // (Option B) si tu ajoutes des champs cache :
        // $facture->setMontantPayeCents($paid);
        // $facture->setMontantRestantCents($remaining);
        // $facture->setLastPaymentAt($paid > 0 ? new \DateTimeImmutable() : null);
        // $facture->setPaidAt($ttcTotal > 0 && $paid >= $ttcTotal ? new \DateTimeImmutable() : null);

        if ($ttcTotal > 0 && $paid >= $ttcTotal) {
            $facture->setStatus(FactureStatus::PAID);
        } elseif ($paid > 0) {
            $facture->setStatus(FactureStatus::PARTIALLY_PAID);
        } else {
            $facture->setStatus(FactureStatus::DUE);
        }

        $em->flush();
    }



    private function paiementHtHorsDeboursCents(Paiement $p): int
    {
        $paidTtc = (int) ($p->getMontantCents() ?? 0);
        $f = $p->getFacture();

        if (!$f) {
            // pas de facture => impossible de déduire proprement le HT hors débours
            return $paidTtc;
        }

        $ttcTotal = $this->factureTtcTotalCents($f); // ✅ TTC total réel
        $ttcHd    = (int) $f->getMontantTtcHorsDeboursCents();
        $htHd     = (int) $f->getMontantHtHorsDeboursCents();


        if ($ttcTotal <= 0 || $ttcHd <= 0 || $htHd <= 0) {
            return 0;
        }

        // 1) On enlève la part "débours" du paiement (allocation proportionnelle)
        $paidTtcHd = (int) round($paidTtc * ($ttcHd / $ttcTotal));

        // 2) Puis on convertit TTC(hors débours) -> HT(hors débours)
        return (int) round($paidTtcHd * ($htHd / $ttcHd));
    }


    private function hydrateVentilation(Paiement $p): void
    {
        // si manuel déjà renseigné et pas de facture -> ne touche pas
        if (!$p->getFacture()) {
            $hasManual = $p->getVentilationHtHorsDeboursCents() !== null
                || $p->getVentilationTvaHorsDeboursCents() !== null
                || $p->getVentilationDeboursCents() !== null;

            if ($hasManual) {
                $p->setVentilationSource('manuel');
                return;
            }

            $p->setVentilationSource('non_ventile');
            $p->setVentilationHtHorsDeboursCents(null);
            $p->setVentilationTvaHorsDeboursCents(null);
            $p->setVentilationDeboursCents(null);
            return;
        }

        $paidTtc = (int) ($p->getMontantCents() ?? 0);
        $f = $p->getFacture();

        if ($paidTtc <= 0) {
            $p->setVentilationSource('non_ventile');
            $p->setVentilationHtHorsDeboursCents(null);
            $p->setVentilationTvaHorsDeboursCents(null);
            $p->setVentilationDeboursCents(null);
            return;
        }

        // ✅ TTC total = hors débours + débours
        $ttcTotal = $this->factureTtcTotalCents($f);

        // ✅ TTC / HT hors débours
        $ttcHd = (int) $f->getMontantTtcHorsDeboursCents();
        $htHd  = (int) $f->getMontantHtHorsDeboursCents();

        if ($ttcTotal <= 0 || $ttcHd <= 0 || $htHd < 0) {
            $p->setVentilationSource('facture_auto');
            $p->setVentilationHtHorsDeboursCents(0);
            $p->setVentilationTvaHorsDeboursCents(0);
            $p->setVentilationDeboursCents(0);
            return;
        }

        // 1) part TTC hors débours payée (proportion sur TTC total)
        $paidTtcHd  = (int) round($paidTtc * ($ttcHd / $ttcTotal));
        $paidDebours = max(0, $paidTtc - $paidTtcHd);

        // 2) conversion TTC(hd) -> HT(hd)
        $paidHtHd  = (int) round($paidTtcHd * ($htHd / $ttcHd));
        $paidTvaHd = max(0, $paidTtcHd - $paidHtHd);

        $p->setVentilationSource('facture_auto');
        $p->setVentilationHtHorsDeboursCents($paidHtHd);
        $p->setVentilationTvaHorsDeboursCents($paidTvaHd);
        $p->setVentilationDeboursCents($paidDebours);
    }



    private function factureDeboursTtcCents(Facture $f): int
    {
        if (method_exists($f, 'getMontantDeboursTtcCents')) {
            return (int) ($f->getMontantDeboursTtcCents() ?? 0);
        }
        return 0;
    }

    private function factureTtcTotalCents(Facture $f): int
    {
        // ✅ ton modèle actuel : TTC total = TTC hors débours + débours
        return (int) ($f->getMontantTtcHorsDeboursCents() ?? 0) + $this->factureDeboursTtcCents($f);
    }

    /**
     * ✅ Helper HTML pour DataTables (badges)
     */
    private function moneyCell(int $cents, string $type): string
    {
        $val = number_format($cents / 100, 2, ',', ' ') . ' €';

        return match ($type) {
            // HT hors débours
            'ht' => $cents > 0
                ? '<span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">' . $val . '</span>'
                : '<span class="text-muted">0,00 €</span>',

            // TVA hors débours
            'tva' => $cents > 0
                ? '<span class="badge rounded-pill bg-info-subtle text-info border border-info-subtle px-2 py-1">' . $val . '</span>'
                : '<span class="text-muted">0,00 €</span>',

            // Débours (TTC)
            'debours' => $cents > 0
                ? '<span class="badge rounded-pill bg-secondary-subtle text-secondary border border-secondary-subtle px-2 py-1" data-bs-toggle="tooltip" title="Part du paiement allouée aux lignes marquées en débours (TTC)">' . $val . '</span>'
                : '<span class="text-muted">0,00 €</span>',

            // TTC payé
            'ttc' => $cents > 0
                ? '<span class="badge rounded-pill bg-success-subtle text-success border border-success-subtle px-2 py-1">' . $val . '</span>'
                : '<span class="text-muted">0,00 €</span>',

            default => '<span class="fw-semibold">' . $val . '</span>',
        };
    }


    // -------------------------
    // SHOW
    // -------------------------
    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Entite $entite, Paiement $paiement): Response
    {
        if ($paiement->getEntite()?->getId() !== $entite->getId()) {
            throw $this->createAccessDeniedException('Paiement non autorisé pour cette entité.');
        }

        return $this->render('administrateur/paiement/show.html.twig', [
            'entite' => $entite,
            'p'      => $paiement,
            'title'  => 'Paiement #' . $paiement->getId(),
        ]);
    }
}
