<?php
// src/Controller/Stagiaire/DashboardStagiaireController.php
declare(strict_types=1);

namespace App\Controller\Stagiaire;

use App\Service\FileUploader;
use App\Entity\{SupportAssignUser, Entite, Utilisateur, Inscription, SessionJour, Session, SatisfactionAssignment, SupportAssignSession};
use App\Service\Photo\PhotoManager;
use App\Service\Email\MailerManager;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\InscriptionRepository;
use Symfony\Component\Routing\Attribute\Route;
use App\Service\Satisfaction\SatisfactionAccess;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse};
use App\Entity\QcmAssignment;
use App\Enum\QcmPhase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use App\Security\Permission\TenantPermission;



#[Route('/stagiaire/{entite}', name: 'app_stagiaire_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::STAGIAIRE_DASHBOARD_MANAGE, subject: 'entite')]
class DashboardStagiaireController extends AbstractController
{

    public function __construct(
        private UtilisateurEntiteManager $utilisateurEntiteManager,
        private MailerManager $mailerManager,
        private PhotoManager $photoManager,
        private FileUploader $fileUploader,
        private ParameterBagInterface $params,
    ) {}
    /* =========================
     * Helpers (mêmes signatures)
     * ========================= */
    private function formatSessionJoursShort(Session $s, int $max = 3): string
    {
        $items = [];
        $i = 0;
        foreach ($s->getJours() as $j) {
            $items[] = $j->getDateDebut()->format('d/m H:i') . '–' . $j->getDateFin()->format('H:i');
            $i++;
            if ($i >= $max) break;
        }
        if (\count($s->getJours()) > $max) {
            $items[] = '…';
        }
        return implode(', ', $items);
    }
    private function firstStart(Session $s): ?\DateTimeImmutable
    {
        $first = null;
        foreach ($s->getJours() as $j) {
            $d = $j->getDateDebut();
            $first = $first ? min($first, $d) : $d;
        }
        return $first;
    }
    private function lastEnd(Session $s): ?\DateTimeImmutable
    {
        $last = null;
        foreach ($s->getJours() as $j) {
            $d = $j->getDateFin();
            $last = $last ? max($last, $d) : $d;
        }
        return $last;
    }

    /* =========================
     * Dashboard
     * ========================= */
    #[Route('/dashboard', name: 'dashboard', methods: ['GET'])]
    public function dashboard(
        Entite $entite,
        EntityManagerInterface $em,
        InscriptionRepository $inscriptionRepo,
        SatisfactionAccess $satisfactionAccess
    ): Response {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $today = (new \DateTimeImmutable('today'))->setTime(0, 0);

        $now = new \DateTimeImmutable();

        // ✅ “pas finie” (donc à venir OU en cours)
        $todayStart = (new \DateTimeImmutable('today'))->setTime(0, 0);

        $next = $em->createQueryBuilder()
            ->select('s, fo, f, fu, si')
            ->addSelect('(SELECT MIN(j2.dateDebut) FROM ' . SessionJour::class . ' j2 WHERE j2.session = s) AS HIDDEN firstStart')
            ->from(Session::class, 's')
            ->join(Inscription::class, 'i', 'WITH', 'i.session = s')
            ->andWhere('i.stagiaire = :me')->setParameter('me', $user)
            ->join('s.formation', 'fo')
            ->leftJoin('s.formateur', 'f')
            ->leftJoin('f.utilisateur', 'fu')
            ->leftJoin('s.site', 'si')

            ->andWhere('(SELECT MAX(j3.dateFin) FROM ' . SessionJour::class . ' j3 WHERE j3.session = s) >= :todayStart')
            ->setParameter('todayStart', $todayStart)


            ->addOrderBy('firstStart', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();



        $nextSession = null;

        if ($next) {
            // dates (tu as déjà getDateDebut/getDateFin)
            $start = $next->getDateDebut();
            $end   = $next->getDateFin();

            // formateur nom (si présent)
            $formateurNom = null;
            if ($next->getFormateur()?->getUtilisateur()) {
                $u = $next->getFormateur()->getUtilisateur();
                $formateurNom = trim(($u->getPrenom() ?? '') . ' ' . ($u->getNom() ?? ''));
            }

            // SITE
            $site = $next->getSite();
            $siteNom = $site?->getNom();

            // Adresse complète lisible
            $destParts = [];
            if ($site?->getAdresse())      $destParts[] = $site->getAdresse();
            if ($site?->getComplement())   $destParts[] = $site->getComplement();
            $cpVille = trim(($site?->getCodePostal() ?? '') . ' ' . ($site?->getVille() ?? ''));
            if ($cpVille)                  $destParts[] = $cpVille;
            if ($site?->getPays())         $destParts[] = $site->getPays();

            $destAddress = trim(implode(', ', $destParts)) ?: null;

            // Google Maps embed : priorité aux coordonnées si dispo (plus fiable),
            // sinon fallback sur l'adresse.
            $gmapsEmbedUrl = null;
            if ($site && $site->getLatitude() !== null && $site->getLongitude() !== null) {
                $gmapsEmbedUrl = sprintf(
                    'https://www.google.com/maps?q=%s,%s&output=embed',
                    rawurlencode((string)$site->getLatitude()),
                    rawurlencode((string)$site->getLongitude())
                );
            } elseif ($destAddress) {
                $gmapsEmbedUrl = 'https://www.google.com/maps?q=' . rawurlencode($destAddress) . '&output=embed';
            }

            // ÉQUIPEMENTS = SESSION (TES BOOL)
            $equipements = [
                'equipOrdinateurFormateur'        => $next->isEquipOrdinateurFormateur(),
                'equipVideoprojecteurEcran'      => $next->isEquipVideoprojecteurEcran(),
                'equipInternetStable'            => $next->isEquipInternetStable(),
                'equipTableauPaperboard'         => $next->isEquipTableauPaperboard(),
                'equipMarqueursSupportsImprimes' => $next->isEquipMarqueursSupportsImprimes(),
                'salleAdapteeTailleGroupe'       => $next->isSalleAdapteeTailleGroupe(),
                'salleTablesChaisesErgo'         => $next->isSalleTablesChaisesErgo(),
                'salleLumiereChauffageClim'      => $next->isSalleLumiereChauffageClim(),
                'salleEauCafe'                   => $next->isSalleEauCafe(),
            ];


            $emaDefaultIso = $this->defaultEmaDateIso($next, $now);


            // ✅ Satisfaction (pour la "prochaine session")
            $inscription = $inscriptionRepo->findOneBy([
                'session'   => $next,
                'stagiaire' => $user,
            ]);

            $assign = $em->getRepository(SatisfactionAssignment::class)->findOneBy([
                'session'   => $next,
                'stagiaire' => $user,
            ]);

            $submittedAt = $assign?->getAttempt()?->getSubmittedAt();
            $isSubmitted = $submittedAt !== null;

            $isLastDay = $this->isLastDayNow($next, $now);

            // même règle métier que tu utilises déjà (dernière journée + tolérance)
            $canFillByRule = $satisfactionAccess->canFill($next, 7);

            // URL si possible
            $satisfactionUrl = null;
            if ($inscription) {
                $satisfactionUrl = $this->generateUrl('app_stagiaire_satisfaction_fill', [
                    'entite'      => $entite->getId(),
                    'inscription' => $inscription->getId(),
                ]);
            }


            $nextSession = [
                'id'            => $next->getId(),
                'code'          => $next->getCode(),
                'formationTitre' => $next->getFormation()
                    ? $next->getFormation()->getTitre()
                    : $next->getFormationIntituleLibre(),
                'start'         => $start,
                'end'           => $end,
                'nbJours'       => $next->getJours()->count(),
                'formateur'     => $formateurNom,
                'siteNom'       => $siteNom,
                'destAddress'   => $destAddress,
                'gmapsEmbedUrl' => $gmapsEmbedUrl,
                'equipements'   => $equipements,
                'emaDefaultIso' => $emaDefaultIso,
                'hasSatisfactionAssign' => (bool)$assign,
                'satisfactionSubmitted' => $isSubmitted,
                'isLastDay'             => $isLastDay,
                'canFillSatisfaction'   => $canFillByRule,
                'satisfactionUrl'       => $satisfactionUrl,
            ];
        }

        return $this->render('stagiaire/dashboard.html.twig', [
            'entite' => $entite,
            'nextSession' => $nextSession,
        ]);
    }



    /** DataTables - mes sessions (où je suis inscrit) */
    /** DataTables - mes sessions (où je suis inscrit) */
    #[Route('/sessions/ajax', name: 'sessions_ajax', methods: ['POST'])]
    public function sessionsAjax(
        Entite $entite,
        Request $request,
        EntityManagerInterface $em,
        InscriptionRepository $inscriptionRepo,   // 👈 ajout ici
        SatisfactionAccess $satisfactionAccess,
    ): JsonResponse {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        // Sessions via Reservation
        $list = $em->createQueryBuilder()
            ->select('s')
            ->distinct()
            ->addSelect('(SELECT MIN(j2.dateDebut) FROM ' . SessionJour::class . ' j2 WHERE j2.session = s) AS HIDDEN firstStart')
            ->from(Session::class, 's')

            // joindre Inscription pour filtrer "mes sessions"
            ->join(Inscription::class, 'i', 'WITH', 'i.session = s')
            ->andWhere('i.stagiaire = :me')->setParameter('me', $user)

            // fetch joins (optionnel mais mieux perf)
            ->join('s.formation', 'fo')->addSelect('fo')
            ->leftJoin('s.formateur', 'f')->addSelect('f')
            ->leftJoin('f.utilisateur', 'fu')->addSelect('fu')

            // (optionnel mais conseillé) filtrer par entité si Session a bien un champ entite
            // ->andWhere('s.entite = :entite')->setParameter('entite', $entite)

            ->addOrderBy('firstStart', 'DESC')
            ->getQuery()
            ->getResult();


        $sessionIds = array_map(fn(Session $s) => (int)$s->getId(), $list);

        $assignBySessionId = [];
        if (!empty($sessionIds)) {
            $rowsAssign = $em->createQueryBuilder()
                ->select('IDENTITY(ass.session) AS sid, att.submittedAt AS submittedAt')
                ->from(SatisfactionAssignment::class, 'ass')
                ->leftJoin('ass.attempt', 'att')
                ->andWhere('ass.stagiaire = :me')->setParameter('me', $user)
                ->andWhere('ass.session IN (:sids)')->setParameter('sids', $sessionIds)
                ->getQuery()->getArrayResult();

            foreach ($rowsAssign as $r) {
                $assignBySessionId[(int)$r['sid']] = [
                    'submittedAt' => $r['submittedAt'] ?? null,
                ];
            }
        }





        $qcmBySessionId = [];
        if (!empty($sessionIds)) {
            $rowsQcm = $em->createQueryBuilder()
                ->select('IDENTITY(qa.session) AS sid, qa.id AS qcmAid, qa.phase AS phase, att.submittedAt AS submittedAt')
                ->from(QcmAssignment::class, 'qa')
                ->leftJoin('qa.attempt', 'att')
                ->andWhere('qa.inscription IS NOT NULL')
                ->andWhere('qa.inscription IN (
            SELECT i3.id FROM ' . Inscription::class . ' i3
            WHERE i3.session = qa.session AND i3.stagiaire = :me
        )')
                ->andWhere('qa.session IN (:sids)')->setParameter('sids', $sessionIds)
                ->andWhere('att.submittedAt IS NOT NULL') // uniquement exportable si soumis
                ->setParameter('me', $user)
                ->getQuery()->getArrayResult();

            // Choix par session : priorité POST, sinon PRE
            foreach ($rowsQcm as $r) {
                $sid = (int)$r['sid'];

                $phaseRaw = $r['phase'] ?? null;
                $phase = $phaseRaw instanceof QcmPhase ? $phaseRaw->value : (string)$phaseRaw;

                $aid = (int)$r['qcmAid'];

                if (!isset($qcmBySessionId[$sid])) {
                    $qcmBySessionId[$sid] = ['id' => $aid, 'phase' => $phase];
                    continue;
                }

                $current = $qcmBySessionId[$sid];
                $isPost = ($phase === QcmPhase::POST->value);
                $curIsPost = ((string)$current['phase'] === QcmPhase::POST->value);

                if ($isPost && !$curIsPost) {
                    $qcmBySessionId[$sid] = ['id' => $aid, 'phase' => $phase];
                }
            }
        }


        $rows = array_map(function (Session $s) use (
            $inscriptionRepo,
            $user,
            $entite,
            $assignBySessionId,
            $satisfactionAccess,
            $qcmBySessionId,
        ) {
            $first = $this->firstStart($s);
            $last  = $this->lastEnd($s);

            $jours = $s->getJours();

            if ($jours->isEmpty()) {
                $dates = '<span class="text-muted">-</span>';
            } else {
                $first = $this->firstStart($s);
                $last  = $this->lastEnd($s);
                $nbJ   = $jours->count();

                $rangeLabel = ($first && $last)
                    ? sprintf('%s → %s', $first->format('d/m/Y'), $last->format('d/m/Y'))
                    : '-';

                $timeLabel = ($first && $last)
                    ? sprintf('%s–%s', $first->format('H:i'), $last->format('H:i'))
                    : '';

                // Timeline : max 3 lignes
                $lines = [];
                $i = 0;
                foreach ($jours as $j) {
                    $lines[] = [
                        'd'  => $j->getDateDebut()->format('d/m'),
                        'h1' => $j->getDateDebut()->format('H:i'),
                        'h2' => $j->getDateFin()->format('H:i'),
                    ];
                    if (++$i >= 3) break;
                }
                $more = $nbJ > 3 ? ('+' . ($nbJ - 3) . ' jour(s)') : null;

                $timelineItems = '';
                foreach ($lines as $idx => $it) {
                    $timelineItems .= sprintf(
                        '<div class="dt-tl-item">
               <span class="dt-tl-dot"></span>
               <div class="dt-tl-content">
                 <div class="dt-tl-date">%s</div>
                 <div class="dt-tl-time">%s–%s</div>
               </div>
             </div>',
                        htmlspecialchars($it['d'], ENT_QUOTES),
                        htmlspecialchars($it['h1'], ENT_QUOTES),
                        htmlspecialchars($it['h2'], ENT_QUOTES),
                    );
                }

                $dates = sprintf(
                    '<div class="dt-dates" data-order="%s">
            <div class="dt-dates-top">
              <span class="dt-chip dt-chip-primary"><i class="bi bi-calendar-event"></i> %s</span>
              <span class="dt-chip"><i class="bi bi-clock"></i> %s</span>
              <span class="dt-chip dt-chip-days"><i class="bi bi-hourglass-split"></i> %d j</span>
            </div>

            <div class="dt-timeline">
              %s
              %s
            </div>
         </div>',
                    htmlspecialchars($first?->format(\DateTimeInterface::ATOM) ?? '', ENT_QUOTES),
                    htmlspecialchars($rangeLabel, ENT_QUOTES),
                    htmlspecialchars($timeLabel, ENT_QUOTES),
                    $nbJ,
                    $timelineItems,
                    $more ? ('<div class="dt-tl-more">' . htmlspecialchars($more, ENT_QUOTES) . '</div>') : ''
                );
            }


            $formateurNom = $s->getFormateur()?->getUtilisateur()
                ? trim(($s->getFormateur()->getUtilisateur()->getPrenom() ?? '') . ' ' . ($s->getFormateur()->getUtilisateur()->getNom() ?? ''))
                : '-';

            // 🔎 inscription du stagiaire sur la session
            $inscription = $inscriptionRepo->findOneBy([
                'session'   => $s,
                'stagiaire' => $user,
            ]);

            // ✅ bouton émargement
            $emargementBtn = sprintf(
                '<button class="btn btn-sm btn-outline-success js-emargement"
                 data-session-id="%d"
                 data-session-label="%s">
            <i class="bi bi-pencil"></i> Émargements
         </button>',
                $s->getId(),
                htmlspecialchars(($s->getFormation()
                    ? $s->getFormation()->getTitre()
                    : $s->getFormationIntituleLibre()) . ' - ' . $s->getCode(), ENT_QUOTES)
            );

            // ✅ bouton satisfaction (si assignment existe)
            $satisfactionBtn = '';
            $sid = (int)$s->getId();
            $hasAssign = array_key_exists($sid, $assignBySessionId);

            if ($inscription && $hasAssign) {
                $isSubmitted = !empty($assignBySessionId[$sid]['submittedAt']);

                if ($isSubmitted) {
                    $satisfactionBtn = '<button class="btn btn-sm btn-outline-secondary" disabled title="Questionnaire déjà envoyé">
                <i class="bi bi-check2-circle"></i> Satisfaction
            </button>';
                } else {
                    $url = $this->generateUrl('app_stagiaire_satisfaction_fill', [
                        'entite'      => $entite->getId(),
                        'inscription' => $inscription->getId(),
                    ]);

                    $canFill = $satisfactionAccess->canFill($s, 7);

                    if ($canFill) {
                        $satisfactionBtn = sprintf(
                            '<a href="%s" class="btn btn-sm btn-outline-warning">
                        <i class="bi bi-ui-checks"></i> Satisfaction
                     </a>',
                            $url
                        );
                    } else {
                        $satisfactionBtn = sprintf(
                            '<a href="%s" class="btn btn-sm btn-outline-warning disabled"
                        tabindex="-1" aria-disabled="true"
                        title="Disponible à la dernière journée (et quelques jours après)">
                        <i class="bi bi-ui-checks"></i> Satisfaction
                     </a>',
                            $url
                        );
                    }
                }
            }

            $qcmBtn = '';
            $sid = (int)$s->getId();

            if (isset($qcmBySessionId[$sid]['id'])) {
                $qcmBtn = sprintf(
                    '<a class="btn btn-sm btn-outline-secondary" href="%s" title="Télécharger le QCM en PDF">
            <i class="bi bi-download"></i> QCM PDF
         </a>',
                    $this->generateUrl('app_stagiaire_qcm_pdf', [
                        'entite' => $entite->getId(),
                        'id' => (int)$qcmBySessionId[$sid]['id'],
                    ])
                );
            }


            $actions = $this->renderView('stagiaire/_actions_dashboard.html.twig', [
                'entite'       => $entite,
                's'            => $s,
                'inscription'  => $inscription, // ✅
                'satisfactionBtn' => $satisfactionBtn ?? '',
                'qcmBtn'          => $qcmBtn ?? '',
            ]);



            $first = $this->firstStart($s);
            $last  = $this->lastEnd($s);


            return [
                'code'         => $s->getCode(),
                'formation' => $s->getFormation()
                    ? $s->getFormation()->getTitre()
                    : $s->getFormationIntituleLibre(),
                'dates'        => $dates,
                'formateur'    => $formateurNom,
                'actions'      => $actions,
                'firstStartIso' => $first?->format(\DateTimeInterface::ATOM),
                'lastEndIso'   => $last?->format(\DateTimeInterface::ATOM),
            ];
        }, $list);


        return new JsonResponse([
            'data' => $rows,
            'recordsTotal' => count($rows),
            'recordsFiltered' => count($rows),
            'draw' => (int)($request->attributes->get('draw') ?? 1),
        ]);
    }

    /** Feed FullCalendar - mes sessions */
    #[Route('/calendar/feed', name: 'calendar_feed', methods: ['GET'])]
    public function calendarFeed(EntityManagerInterface $em): JsonResponse
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $list = $em->createQueryBuilder()
            ->select('s')
            ->distinct()
            ->from(Session::class, 's')
            ->join(Inscription::class, 'i', 'WITH', 'i.session = s')
            ->andWhere('i.stagiaire = :me')->setParameter('me', $user)

            ->join('s.formation', 'fo')->addSelect('fo')
            ->leftJoin('s.jours', 'j')->addSelect('j')

            // ->andWhere('s.entite = :entite')->setParameter('entite', $entite) // si dispo

            ->addOrderBy('j.dateDebut', 'ASC')
            ->getQuery()
            ->getResult();



        $events = [];
        foreach ($list as $s) {
            foreach ($s->getJours() as $j) {
                $events[] = [
                    'id'    => $s->getId(),
                    'title' => trim(($s->getFormation()
                        ? $s->getFormation()->getTitre()
                        : $s->getFormationIntituleLibre()) . ' - ' . $s->getCode()),
                    'start' => $j->getDateDebut()->format('c'),
                    'end'   => $j->getDateFin()->format('c'),
                    'extendedProps' => [
                        'sessionId' => $s->getId(),
                        'jourId'    => $j->getId(),
                    ],
                ];
            }
        }
        return new JsonResponse($events);
    }

    /** Supports visibles pour le stagiaire (assignés à ses sessions en visibleToTrainee = true OU assignés directement au user en visible = true) */
    #[Route('/supports/assets', name: 'supports_assets_feed', methods: ['GET'])]
    public function assetsFeed(Entite $entite, EntityManagerInterface $em, Request $request): JsonResponse
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        // Sessions de l’utilisateur
        $sessionIds = $em->createQueryBuilder()
            ->select('s2.id')
            ->distinct()
            ->from(Session::class, 's2')
            ->join(Inscription::class, 'i2', 'WITH', 'i2.session = s2')
            ->andWhere('i2.stagiaire = :me')->setParameter('me', $user)
            // ->andWhere('s2.entite = :entite')->setParameter('entite', $entite) // si dispo
            ->getQuery()
            ->getSingleColumnResult();




        // Supports via sessions (visibles)
        $sessRows = [];
        if (!empty($sessionIds)) {
            $sessRows = $em->createQueryBuilder()
                ->select('a.id, a.titre, a.originalName, a.mimeType, a.filename, a.uploadedAt')
                ->from(SupportAssignSession::class, 'link')
                ->join('link.asset', 'a')
                ->andWhere('link.session IN (:ids)')->setParameter('ids', $sessionIds)
                ->andWhere('link.isVisibleToTrainee = :v')->setParameter('v', true)
                ->getQuery()->getArrayResult();
        }

        // Supports assignés direct au user (visibles)
        $userRows = $em->createQueryBuilder()
            ->select('a.id, a.titre, a.originalName, a.mimeType, a.filename, a.uploadedAt')
            ->from(SupportAssignUser::class, 'linku')
            ->join('linku.asset', 'a')
            ->andWhere('linku.user = :me')->setParameter('me', $user)
            ->andWhere('linku.isVisibleToTrainee = :v')->setParameter('v', true)
            ->getQuery()->getArrayResult();

        // Merge + distinct par id
        $byId = [];
        foreach (array_merge($sessRows, $userRows) as $r) {
            $byId[(int)$r['id']] = $r;
        }
        $rows = array_values($byId);

        $basePath = rtrim($request->getBasePath(), '/');
        $out = array_map(function (array $a) use ($basePath) {
            return [
                'id'          => (int)$a['id'],
                'titre'       => (string)($a['titre'] ?: pathinfo((string)$a['originalName'], PATHINFO_FILENAME)),
                'originalName' => (string)$a['originalName'],
                'mimeType'    => (string)$a['mimeType'],
                'uploadedAt'  => $a['uploadedAt'] instanceof \DateTimeInterface ? $a['uploadedAt']->format('d/m/Y H:i') : '',
                'url'         => $basePath . '/uploads/supports/library/' . (string)$a['filename'],
            ];
        }, $rows);

        return new JsonResponse(['data' => $out]);
    }


    private function defaultEmaDateIso(Session $s, \DateTimeImmutable $now): ?string
    {
        $bestNext = null;
        $first = null;

        foreach ($s->getJours() as $j) {
            $dStart = $j->getDateDebut();
            $dEnd   = $j->getDateFin();

            $first = $first ? min($first, $dStart) : $dStart;

            // ✅ on considère la journée "active" jusqu'à 23:59:59
            $endOfDay = $dEnd->setTime(23, 59, 59);

            // si on est sur cette journée (même après la fin réelle, mais avant minuit)
            if ($now >= $dStart && $now <= $endOfDay) {
                return $dStart->format('Y-m-d');
            }

            if ($dStart > $now) {
                $bestNext = $bestNext ? min($bestNext, $dStart) : $dStart;
            }
        }

        if ($bestNext) return $bestNext->format('Y-m-d');
        return $first?->format('Y-m-d');
    }


    private function isLastDayNow(Session $s, \DateTimeImmutable $now): bool
    {
        $lastStart = null;
        $lastEnd   = null;

        foreach ($s->getJours() as $j) {
            $dStart = $j->getDateDebut();
            $dEnd   = $j->getDateFin();

            if ($lastStart === null || $dStart > $lastStart) {
                $lastStart = $dStart;
                $lastEnd   = $dEnd;
            }
        }

        if (!$lastStart || !$lastEnd) return false;

        // ✅ dernière journée "active" jusqu'à 23:59:59
        $endOfDay = $lastEnd->setTime(23, 59, 59);

        return $now >= $lastStart && $now <= $endOfDay;
    }



    private function publicPath(string $relative): string
    {
        $projectDir = $this->params->get('kernel.project_dir');
        $rel = ltrim($relative, '/');
        return rtrim($projectDir, '/') . '/public/' . $rel;
    }

    #[Route('/inscriptions/{inscription}/attestation', name: 'attestation_download', methods: ['GET'])]
    public function downloadAttestation(
        Entite $entite,
        Inscription $inscription,
        EntityManagerInterface $em
    ): BinaryFileResponse {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        // ✅ Sécurité : l'inscription doit appartenir au stagiaire connecté
        if ($inscription->getStagiaire()?->getId() !== $user->getId()) {
            throw $this->createNotFoundException();
        }

        // ✅ Sécurité : l’inscription doit appartenir à une session de cette entité
        $session = $inscription->getSession();
        if (!$session || $session->getEntite()?->getId() !== $entite->getId()) {
            throw $this->createNotFoundException();
        }

        $att = $inscription->getAttestation();
        $pdfRel = $att?->getPdfPath();

        if (!$att || !$pdfRel) {
            throw $this->createNotFoundException('Attestation non disponible.');
        }


        $abs = $this->publicPath($pdfRel);
        dd($abs);
        if (!is_file($abs)) {
            throw $this->createNotFoundException('Fichier introuvable.');
        }

        // Nom de fichier propre
        $downloadName = ($att->getNumeroOrNull() ?: ('attestation_' . $inscription->getId())) . '.pdf';

        $response = new BinaryFileResponse($abs);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $downloadName
        );
        $response->headers->set('Content-Type', 'application/pdf');

        return $response;
    }
}
