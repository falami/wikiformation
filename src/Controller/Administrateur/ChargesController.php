<?php

namespace App\Controller\Administrateur;

use App\Entity\{Entite, TaxRule, Paiement, Facture, LigneFacture, Utilisateur};
use App\Form\Administrateur\TaxRuleType;
use App\Service\Tax\TaxEngine;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\Security\Permission\TenantPermission;


#[Route('/administrateur/{entite}/charge', name: 'app_administrateur_charge_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::CHARGE_MANAGE, subject: 'entite')]
final class ChargesController extends AbstractController
{

    public function __construct(
        private UtilisateurEntiteManager $utilisateurEntiteManager,
    ) {}
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Entite $entite): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        return $this->render('administrateur/charge/index.html.twig', [
            'entite' => $entite,
        ]);
    }

    #[Route('/preview', name: 'preview', methods: ['GET'])]
    public function preview(Entite $entite, TaxEngine $engine, EM $em, Request $req): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $from = $this->parseFrDate($req->query->get('from'))
            ?? new \DateTimeImmutable('first day of this month 00:00:00');

        $to = $this->parseFrDate($req->query->get('to'))
            ?? new \DateTimeImmutable('last day of this month 23:59:59');

        $from = $from->setTime(0, 0, 0);
        $to   = $to->setTime(23, 59, 59);

        // borne exclusive (lendemain 00:00:00)
        $toExclusive = $to->modify('+1 day')->setTime(0, 0, 0);


        $granularity = $req->query->get('granularity', 'quarter'); // quarter par défaut
        if (!in_array($granularity, ['month', 'quarter'], true)) $granularity = 'quarter';

        $result = $engine->estimate($entite, $from, $toExclusive, 'EUR');
        $series = $this->buildSeries($entite, $engine, $from, $toExclusive, $granularity);
        $caSeries = $this->buildCaSeries($entite, $engine, $from, $toExclusive, $granularity);



        return $this->render('administrateur/charge/preview.html.twig', [
            'entite' => $entite,
            'from' => $from,
            'to' => $to,
            'result' => $result,
            'series' => $series,
            'caSeries' => $caSeries,
            'granularity' => $granularity,

        ]);
    }


    /**
     * Accepte "dd/mm/YYYY" ou "dd/mm/YYYY au dd/mm/YYYY" (si jamais tu passes un range)
     */
    private function parseFrDate(?string $s): ?\DateTimeImmutable
    {
        $s = trim((string) $s);
        if ($s === '') return null;

        // si jamais on reçoit un range "01/01/2025 au 31/12/2025" ou "01/01/2025 to 31/12/2025"
        if (str_contains($s, ' au ')) {
            $s = trim(explode(' au ', $s)[0]);
        } elseif (str_contains($s, ' to ')) {
            $s = trim(explode(' to ', $s)[0]);
        }

        $d = \DateTimeImmutable::createFromFormat('d/m/Y', $s);
        if (!$d) return null;

        // check erreurs parsing
        $errors = \DateTimeImmutable::getLastErrors();
        if (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0) {
            return null;
        }

        return $d;
    }


    // ---------------------------
    // RULES
    // ---------------------------
    #[Route('/rules', name: 'rule_index', methods: ['GET'])]
    public function ruleIndex(Entite $entite): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        return $this->render('administrateur/charge/rule_index.html.twig', [
            'entite' => $entite,

        ]);
    }

    // ---------------------------
    // RULES (DataTables AJAX)
    // ---------------------------
    #[Route('/rules/ajax', name: 'rule_ajax', methods: ['GET', 'POST'])]
    public function ruleAjax(Entite $entite, EM $em, Request $req): JsonResponse
    {
        // DataTables params
        $draw   = (int) $req->request->get('draw', $req->query->get('draw', 1));
        $start  = (int) $req->request->get('start', $req->query->get('start', 0));
        $length = (int) $req->request->get('length', $req->query->get('length', 25));

        $search = (string) ($req->request->all('search')['value'] ?? $req->query->all('search')['value'] ?? '');
        $search = trim($search);

        // Colonnes DataTables (doit matcher l'ordre dans le JS)
        $columns = [
            0 => 'r.id',
            1 => 'r.code',
            2 => 'r.label',
            3 => 'r.kind',
            4 => 'r.base',
            5 => 'r.rate',
            6 => 'r.flatCents',
            7 => 'r.validFrom',
            8 => 'r.validTo',
        ];

        $orderColIndex = (int) ($req->request->all('order')[0]['column'] ?? $req->query->all('order')[0]['column'] ?? 1);
        $orderDir      = strtolower((string) ($req->request->all('order')[0]['dir'] ?? $req->query->all('order')[0]['dir'] ?? 'asc'));
        $orderDir      = in_array($orderDir, ['asc', 'desc'], true) ? $orderDir : 'asc';
        $orderBy       = $columns[$orderColIndex] ?? 'r.code';

        // Base QB
        $qb = $em->createQueryBuilder()
            ->from(TaxRule::class, 'r')
            ->select('r')
            ->andWhere('r.entite = :e')->setParameter('e', $entite);

        // recordsTotal
        $total = (int) (clone $qb)
            ->select('COUNT(r.id)')
            ->getQuery()->getSingleScalarResult();

        // Search (code/label/kind/base)
        if ($search !== '') {
            $qb->andWhere('(
                LOWER(r.code) LIKE :q OR
                LOWER(r.label) LIKE :q OR
                LOWER(CAST(r.kind AS string)) LIKE :q OR
                LOWER(CAST(r.base AS string)) LIKE :q
            )')->setParameter('q', '%' . mb_strtolower($search) . '%');
        }

        // recordsFiltered
        $filtered = (int) (clone $qb)
            ->select('COUNT(r.id)')
            ->getQuery()->getSingleScalarResult();

        // paging + ordering
        if ($length <= 0) $length = 25;
        $qb->orderBy($orderBy, $orderDir)
            ->setFirstResult(max(0, $start))
            ->setMaxResults($length);

        /** @var TaxRule[] $rows */
        $rows = $qb->getQuery()->getResult();

        // Build data
        $data = [];
        foreach ($rows as $r) {
            $data[] = [
                'id'        => $r->getId(),
                'code'      => (string) $r->getCode(),
                'label'     => (string) $r->getLabel(),
                'kind'      => $r->getKind()?->value ?? '',   // enum -> value
                'base'      => $r->getBase()?->value ?? '',
                'rate'      => $r->getRate(),
                'flatCents' => (int) ($r->getFlatCents() ?? 0),
                'validFrom' => $r->getValidFrom()?->format('d/m/Y'),
                'validTo'   => $r->getValidTo()?->format('d/m/Y'),
                'actions'   => $this->renderView('administrateur/charge/_rule_actions.html.twig', [
                    'entite' => $entite,
                    'r'      => $r,
                ]),
            ];
        }

        return $this->json([
            'draw'            => $draw,
            'recordsTotal'    => $total,
            'recordsFiltered' => $filtered,
            'data'            => $data,
        ]);
    }

    #[Route('/rules/new', name: 'rule_new', methods: ['GET', 'POST'])]
    public function ruleNew(Entite $entite, EM $em, Request $req): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $r = new TaxRule();
        $r->setEntite($entite);
        $r->setCreateur($user);
        $r->setDateCreation(new \DateTimeImmutable());

        $form = $this->createForm(TaxRuleType::class, $r)->handleRequest($req);

        if ($form->isSubmitted() && $form->isValid()) {


            $em->persist($r);
            $em->flush();

            $this->addFlash('success', 'Règle créée.');
            return $this->redirectToRoute('app_administrateur_charge_rule_index', ['entite' => $entite->getId()]);
        }

        return $this->render('administrateur/charge/rule_form.html.twig', [
            'entite' => $entite,
            'form' => $form,
            'title' => 'Nouvelle règle',
        ]);
    }

    #[Route('/rules/{id}/edit', name: 'rule_edit', methods: ['GET', 'POST'])]
    public function ruleEdit(Entite $entite, TaxRule $r, EM $em, Request $req): Response
    {

        if ($r->getEntite()?->getId() !== $entite->getId()) {
            throw $this->createAccessDeniedException('Règle non autorisée pour cette entité.');
        }
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $form = $this->createForm(TaxRuleType::class, $r)->handleRequest($req);

        if ($form->isSubmitted() && $form->isValid()) {

            $em->flush();

            $this->addFlash('success', 'Règle mise à jour.');
            return $this->redirectToRoute('app_administrateur_charge_rule_index', ['entite' => $entite->getId()]);
        }

        return $this->render('administrateur/charge/rule_form.html.twig', [
            'entite' => $entite,
            'form' => $form,
            'title' => 'Éditer règle',
        ]);
    }

    #[Route('/rules/{id}/delete', name: 'rule_delete', methods: ['POST'])]
    public function ruleDelete(Entite $entite, TaxRule $r, EM $em, Request $req): Response
    {
        if ($r->getEntite()?->getId() !== $entite->getId()) {
            throw $this->createAccessDeniedException('Règle non autorisée pour cette entité.');
        }

        if (!$this->isCsrfTokenValid('tax_rule_delete_' . $r->getId(), (string)$req->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF invalide.');
        }
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $em->remove($r);
        $em->flush();

        $this->addFlash('success', 'Règle supprimée.');
        return $this->redirectToRoute('app_administrateur_charge_rule_index', [
            'entite' => $entite->getId(),
        ]);
    }

    private function hydrateJsonFields(TaxRule $r): void
    {
        // Si ton form écrit des strings (Textarea) dans des champs array => Doctrine n'aime pas.
        // On accepte:
        // - array déjà OK
        // - string JSON -> decode
        foreach (['conditions', 'meta'] as $field) {
            $getter = 'get' . ucfirst($field);
            $setter = 'set' . ucfirst($field);
            if (!method_exists($r, $getter) || !method_exists($r, $setter)) continue;

            $v = $r->$getter();
            if (is_array($v) || $v === null) continue;

            if (is_string($v)) {
                $trim = trim($v);
                if ($trim === '') {
                    $r->$setter(null);
                    continue;
                }
                $decoded = json_decode($trim, true);
                $r->$setter(is_array($decoded) ? $decoded : null);
            }
        }

        // cohérence : forfait prioritaire -> si flat renseigné, on peut vider rate (optionnel)
        if (($r->getFlatCents() ?? 0) > 0) {
            $r->setRate(0.0);
        }
    }


    private function splitPeriods(\DateTimeImmutable $from, \DateTimeImmutable $toExclusive, string $granularity): array
    {
        $from = $from->setTime(0, 0, 0);

        // ✅ toExclusive est déjà exclusive : on dérive un $toInclusive pour l'affichage / clamp
        $toInclusive = $toExclusive->modify('-1 second')->setTime(23, 59, 59);

        $periods = [];

        if ($granularity === 'quarter') {
            // On part au début du trimestre de $from
            $y = (int)$from->format('Y');
            $m = (int)$from->format('n'); // 1..12
            $qStartMonth = (int)(floor(($m - 1) / 3) * 3) + 1; // 1,4,7,10
            $cursor = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $y, $qStartMonth));

            while ($cursor < $toExclusive) {
                $start = $cursor;
                $endMonth = (int)$start->format('n') + 2;
                $end = $start
                    ->modify(sprintf('+%d months', 2))
                    ->modify('last day of this month')
                    ->setTime(23, 59, 59);
                $endExclusive = $end->modify('+1 day')->setTime(0, 0, 0);


                // clamp
                $pFrom = $start < $from ? $from : $start;
                $pTo = $end > $toInclusive ? $toInclusive : $end;

                $q = (int)floor(((int)$start->format('n') - 1) / 3) + 1;
                $label = 'T' . $q . ' ' . $start->format('Y');

                $periods[] = [
                    'label' => $label,
                    'from' => $pFrom,
                    'to' => $pTo, // pour affichage
                    'toExclusive' => $pTo->modify('+1 day')->setTime(0, 0, 0),
                ];


                $cursor = $start->modify('+3 months')->setTime(0, 0, 0);
            }
            return $periods;
        }

        // default monthly
        // default monthly
        $cursor = $from->modify('first day of this month')->setTime(0, 0, 0);

        // Formatter FR (mois + année)
        $fmt = new \IntlDateFormatter(
            'fr_FR',
            \IntlDateFormatter::NONE,
            \IntlDateFormatter::NONE,
            $from->getTimezone()->getName(),
            \IntlDateFormatter::GREGORIAN,
            'LLLL yyyy' // "janvier 2026"
        );

        while ($cursor <= $toInclusive) {
            $start = $cursor;
            $end   = $start->modify('last day of this month')->setTime(23, 59, 59);

            $pFrom = $start < $from ? $from : $start;
            $pTo   = $end > $toInclusive ? $toInclusive : $end;

            $label = $fmt->format($start);
            // Optionnel : capitale (Janvier 2026)
            $label = mb_convert_case((string)$label, MB_CASE_TITLE, 'UTF-8');

            $periods[] = [
                'label' => $label,
                'from' => $pFrom,
                'to' => $pTo,
                'toExclusive' => $pTo->modify('+1 day')->setTime(0, 0, 0),
            ];


            $cursor = $start->modify('+1 month')->setTime(0, 0, 0);
        }


        return $periods;
    }

    private function buildSeries(
        Entite $entite,
        TaxEngine $engine,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        string $granularity
    ): array {
        $periods = $this->splitPeriods($from, $to, $granularity);

        $labels = [];
        $rows   = [];
        $sum    = 0.0;

        /**
         * datasetsIndex[code] = [
         *   'label' => 'URSSAF ...',
         *   'data'  => [0.0, 12.3, 0.0, ...]
         * ]
         */
        $datasetsIndex = [];

        foreach ($periods as $pIndex => $p) {
            $labels[] = $p['label'];

            $res = $engine->estimate($entite, $p['from'], $p['toExclusive'], 'EUR');


            // compat array / objet
            $totalEur = (int)($res['totalEur'] ?? 0);
            $items    = $res['items'] ?? [];

            $bucketTotal = $totalEur;   // déjà arrondi à l'euro sup
            $sum += $bucketTotal;


            // 1) Ajoute une valeur "0" à tous les datasets existants pour cette période
            foreach ($datasetsIndex as $code => $ds) {
                $datasetsIndex[$code]['data'][$pIndex] = 0.0;
            }

            // 2) Remplit les montants par règle pour cette période
            foreach ($items as $it) {
                $code = (string)($it['code'] ?? $it->code ?? 'RULE');
                $lab  = (string)($it['label'] ?? $it->label ?? $code);
                $amt = (int)($it['amountEur'] ?? 0);

                if (!isset($datasetsIndex[$code])) {
                    // création dataset, + backfill des périodes précédentes à 0
                    $datasetsIndex[$code] = [
                        'label' => $lab,
                        'data'  => array_fill(0, $pIndex, 0.0),
                    ];
                }

                // si plusieurs items ont le même code, on additionne
                $datasetsIndex[$code]['data'][$pIndex] = (int)(($datasetsIndex[$code]['data'][$pIndex] ?? 0) + $amt);
            }

            $rows[] = [
                'label' => $p['label'],
                'from'  => $p['from']->format('d/m/Y'),
                'to'    => $p['to']->format('d/m/Y'),
                'eur'   => $bucketTotal, // int
                'cents' => (int)($res['totalCents'] ?? 0),
            ];
        }

        // 3) Transforme en array indexé (Chart.js)
        $datasets = array_values($datasetsIndex);

        return [
            'granularity' => $granularity,
            'labels'      => $labels,
            'datasets'    => $datasets, // <-- NOUVEAU
            'rows'        => $rows,
            'sum'         => (int)$sum,
        ];
    }


    #[Route('/preview.json', name: 'preview_json', methods: ['GET'])]
    public function previewJson(Entite $entite, TaxEngine $engine, EM $em, Request $req): JsonResponse
    {
        $from = $this->parseFrDate($req->query->get('from'))
            ?? new \DateTimeImmutable('first day of this month 00:00:00');

        $to = $this->parseFrDate($req->query->get('to'))
            ?? new \DateTimeImmutable('last day of this month 23:59:59');

        $from = $from->setTime(0, 0, 0);
        $to   = $to->setTime(23, 59, 59);

        // borne exclusive (lendemain 00:00:00)
        $toExclusive = $to->modify('+1 day')->setTime(0, 0, 0);


        $granularity = $req->query->get('granularity', 'quarter');
        if (!in_array($granularity, ['month', 'quarter'], true)) $granularity = 'quarter';

        $result = $engine->estimate($entite, $from, $toExclusive, 'EUR');
        $series = $this->buildSeries($entite, $engine, $from, $toExclusive, $granularity);
        $caSeries = $this->buildCaSeries($entite, $engine, $from, $toExclusive, $granularity);



        // Normalisation array pour JSON (au cas où estimate renvoie objet)
        $itemsRaw    = $result['items'] ?? ($result->items ?? []);



        $totalCents = (int)($result['totalCents'] ?? 0);
        $totalEur   = (int)($result['totalEur'] ?? 0);

        $baseTotals     = (array)($result['baseTotals'] ?? []);
        $baseTotalsEur  = (array)($result['baseTotalsEur'] ?? []);


        $items = [];
        foreach ($itemsRaw as $it) {
            $items[] = [
                'code'        => (string)($it['code'] ?? $it->code ?? ''),
                'label'       => (string)($it['label'] ?? $it->label ?? ''),
                'kind'        => (string)($it['kind'] ?? $it->kind ?? ''),
                'base'        => (string)($it['base'] ?? $it->base ?? ''),
                'baseCents'   => (int)($it['baseCents'] ?? $it->baseCents ?? 0),
                'rate'        => ($it['rate'] ?? $it->rate ?? null),
                'flatCents'   => (int)($it['flatCents'] ?? $it->flatCents ?? 0),
                'amountCents' => (int)($it['amountCents'] ?? $it->amountCents ?? 0),
                'baseEur'   => (int)($it['baseEur'] ?? 0),
                'amountEur' => (int)($it['amountEur'] ?? 0),

            ];
        }

        return $this->json([
            'from' => $from->format('d/m/Y'),
            'to'   => $to->format('d/m/Y'),
            'granularity' => $granularity,
            'result' => [
                'totalCents'    => $totalCents,
                'totalEur'      => $totalEur,
                'baseTotals'    => $baseTotals,
                'baseTotalsEur' => $baseTotalsEur,
                'items'         => $items,
            ],
            'series' => $series,
            'ca' => $caSeries,
        ]);
    }



    private function buildCaSeries(Entite $entite, TaxEngine $engine, \DateTimeImmutable $from, \DateTimeImmutable $toExclusive, string $granularity): array
    {
        $periods = $this->splitPeriods($from, $toExclusive, $granularity);

        $labels = [];
        $rows   = [];
        $sumTtc = 0.0;
        $sumHt  = 0.0;

        foreach ($periods as $p) {
            $labels[] = $p['label'];

            $ttcCents = $engine->caEncaisseTtcHorsDebours($entite, $p['from'], $p['toExclusive']);
            $htCents  = $engine->caEncaisseHtHorsDebours($entite, $p['from'], $p['toExclusive']);




            $ttc = round($ttcCents / 100, 2);
            $ht  = round($htCents / 100, 2);

            $sumTtc += $ttc;
            $sumHt  += $ht;

            $rows[] = [
                'label' => $p['label'],
                'from'  => $p['from']->format('d/m/Y'),
                'toExclusive'    => $p['toExclusive']->format('d/m/Y'),
                'ttc'   => $ttc,
                'ht'    => $ht,
                'ttcCents' => $ttcCents,
                'htCents'  => $htCents,
            ];
        }

        return [
            'granularity' => $granularity,
            'labels' => $labels,
            'rows' => $rows,
            'sumTtc' => round($sumTtc, 2),
            'sumHt'  => round($sumHt, 2),
            'datasets' => [
                [
                    'label' => 'CA encaissé TTC',
                    'data'  => array_map(fn($r) => $r['ttc'], $rows),
                    // on ne fixe pas de couleur ici, Chart.js prendra des couleurs par défaut
                    'type'  => 'line',
                    'tension' => 0.25,
                    'fill' => false,
                ],
                [
                    'label' => 'CA encaissé HT (prorata)',
                    'data'  => array_map(fn($r) => $r['ht'], $rows),
                    'type'  => 'line',
                    'tension' => 0.25,
                    'fill' => false,
                ],
            ],
        ];
    }

    private function sumEncaisseTtc(EM $em, Entite $entite, \DateTimeImmutable $from, \DateTimeImmutable $toExclusive): int
    {
        try {
            $qb = $em->createQueryBuilder()
                ->select('COALESCE(SUM(p.montantCents),0)')
                ->from(Paiement::class, 'p')
                ->leftJoin('p.facture', 'f')
                ->andWhere('p.entite = :e')->setParameter('e', $entite)
                ->andWhere('p.datePaiement >= :from')->setParameter('from', $from)
                ->andWhere('p.datePaiement < :to')->setParameter('to', $toExclusive);

            // Exclure débours si Facture.isDebours existe
            if ($this->entityHasField($em, Facture::class, 'isDebours')) {
                // si paiement sans facture -> on le garde (à toi de choisir)
                $qb->andWhere('(f.id IS NULL OR f.isDebours = 0)');
            }

            return (int) $qb->getQuery()->getSingleScalarResult();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Helper */
    private function entityHasField(EM $em, string $class, string $field): bool
    {
        try {
            return $em->getClassMetadata($class)->hasField($field);
        } catch (\Throwable) {
            return false;
        }
    }


    /**
     * HT encaissé proratisé = paiement TTC * (facture HT / facture TTC)
     * (OK pour paiements partiels)
     */
    private function sumEncaisseHtProrata(EM $em, Entite $entite, \DateTimeImmutable $from, \DateTimeImmutable $toExclusive): int
    {
        try {
            $qb = $em->createQueryBuilder()
                ->select('COALESCE(SUM( (p.montantCents * f.montantHtCents) / NULLIF(f.montantTtcCents, 0) ), 0)')
                ->from(Paiement::class, 'p')
                ->innerJoin('p.facture', 'f')
                ->andWhere('p.entite = :e')->setParameter('e', $entite)
                ->andWhere('p.datePaiement >= :from')->setParameter('from', $from)
                ->andWhere('p.datePaiement < :to')->setParameter('to', $toExclusive);

            if ($this->entityHasField($em, Facture::class, 'isDebours')) {
                $qb->andWhere('f.isDebours = 0');
            }

            $raw = $qb->getQuery()->getSingleScalarResult();
            return (int) round((float) $raw);
        } catch (\Throwable) {
            return 0;
        }
    }


    private function sumEncaisseTtcHorsDebours(EM $em, Entite $entite, \DateTimeImmutable $from, \DateTimeImmutable $toExclusive): int
    {
        // Paiement TTC * (TTC_non_debours / TTC_facture)
        // Or TTC_non_debours = HT_non_debours + TVA_non_debours
        // Comme on n'a pas TVA_non_debours facilement, on va utiliser un ratio HT (plus stable):
        // TTC encaissé hors débours ≈ paiement TTC * (HT_non_debours / HT_facture)
        // (si tu veux être ultra exact TTC, il faut aussi calculer TVA non débours.)
        $mapHtNonDebours = $this->mapFactureNonDeboursHtCents($em, $entite, $from, $toExclusive);

        try {
            $rows = $em->createQueryBuilder()
                ->select('p.montantCents AS payCents, f.id AS factureId, f.montantHtCents AS fHt, f.montantTtcCents AS fTtc')
                ->from(Paiement::class, 'p')
                ->innerJoin('p.facture', 'f')
                ->andWhere('p.entite = :e')->setParameter('e', $entite)
                ->andWhere('p.datePaiement >= :from')->setParameter('from', $from)
                ->andWhere('p.datePaiement < :to')->setParameter('to', $toExclusive)
                ->getQuery()->getArrayResult();

            $sum = 0.0;
            foreach ($rows as $r) {
                $factureId = (int)$r['factureId'];
                $pay = (int)$r['payCents'];
                $fHt = (int)$r['fHt'];

                $htNonDeb = (int)($mapHtNonDebours[$factureId] ?? 0);

                if ($fHt > 0 && $htNonDeb > 0) {
                    $ratio = $htNonDeb / $fHt;
                    $sum += $pay * $ratio; // TTC pondéré par ratio HT
                } else {
                    // si pas de base, on considère 0 hors débours
                    $sum += 0;
                }
            }

            return (int) round($sum);
        } catch (\Throwable) {
            return 0;
        }
    }


    private function mapFactureNonDeboursHtCents(EM $em, Entite $entite, \DateTimeImmutable $from, \DateTimeImmutable $toExclusive): array
    {
        // Retourne [factureId => htNonDeboursCents]
        // NOTE: ici je prends HT brut sans remises % / montants car c’est dur en DQL.
        // ✅ Idéalement, tu stockes "montantHtCents" par ligne après remise, ou tu pré-calcules.
        // Si tu as déjà recalculé Facture.montantHtCents correctement côté serveur, alors
        // tu dois avoir une méthode qui calcule aussi les parts debours/non-debours au moment du recalcul facture.
        try {
            $rows = $em->createQueryBuilder()
                ->select('f.id AS factureId')
                ->addSelect('COALESCE(SUM(CASE WHEN lf.isDebours = 0 THEN lf.qte * lf.puHtCents ELSE 0 END),0) AS htNonDebours')
                ->from(LigneFacture::class, 'lf')
                ->innerJoin('lf.facture', 'f')
                ->andWhere('f.entite = :e')->setParameter('e', $entite)
                ->andWhere('f.dateEmission >= :from')->setParameter('from', $from)
                ->andWhere('f.dateEmission < :to')->setParameter('to', $toExclusive)
                ->groupBy('f.id')
                ->getQuery()->getArrayResult();

            $map = [];
            foreach ($rows as $r) {
                $map[(int)$r['factureId']] = (int)round((float)$r['htNonDebours']);
            }
            return $map;
        } catch (\Throwable) {
            return [];
        }
    }

    private function sumEncaisseHtHorsDebours(EM $em, Entite $entite, \DateTimeImmutable $from, \DateTimeImmutable $toExclusive): int
    {
        // Paiement TTC * (HT_non_debours / TTC_facture) ? non
        // Meilleur : Paiement TTC * (HT_non_debours / TTC_facture) revient à HT encaissé approx
        // Encore plus simple : Paiement TTC * (HT_non_debours / HT_facture) * (HT_facture / TTC_facture)
        // => Paiement TTC * (HT_non_debours / TTC_facture)
        $mapHtNonDebours = $this->mapFactureNonDeboursHtCents($em, $entite, $from, $toExclusive);

        try {
            $rows = $em->createQueryBuilder()
                ->select('p.montantCents AS payCents, f.id AS factureId, f.montantTtcCents AS fTtc')
                ->addSelect('f.montantHtCents AS fHt')
                ->from(Paiement::class, 'p')
                ->innerJoin('p.facture', 'f')
                ->andWhere('p.entite = :e')->setParameter('e', $entite)
                ->andWhere('p.datePaiement >= :from')->setParameter('from', $from)
                ->andWhere('p.datePaiement < :to')->setParameter('to', $toExclusive)
                ->getQuery()->getArrayResult();

            $sum = 0.0;
            foreach ($rows as $r) {
                $factureId = (int)$r['factureId'];
                $pay = (int)$r['payCents'];
                $fTtc = (int)$r['fTtc'];

                $htNonDeb = (int)($mapHtNonDebours[$factureId] ?? 0);

                if ($fTtc > 0 && $htNonDeb > 0) {
                    $ratio = $htNonDeb / $fTtc;
                    $sum += $pay * $ratio;
                }
            }

            return (int) round($sum);
        } catch (\Throwable) {
            return 0;
        }
    }
}
