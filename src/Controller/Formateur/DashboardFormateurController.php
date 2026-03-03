<?php

namespace App\Controller\Formateur;

use App\Entity\{Inscription, Entite, Utilisateur, Session, SupportAsset, SupportAssignSession, SupportAssignUser, ContratFormateur, SessionJour};
use App\Repository\SessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{
    Request,
    Response,
    JsonResponse,
    File\UploadedFile
};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use App\Service\FileUploader;
use App\Service\Photo\PhotoManager;
use App\Service\Email\MailerManager;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use App\Enum\ContratFormateurStatus;
use App\Service\Pdf\PdfManager;
use App\Security\Permission\TenantPermission;

#[Route('/formateur/{entite}', name: 'app_formateur_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::FORMATEUR_DASHBOARD_MANAGE, subject: 'entite')]
class DashboardFormateurController extends AbstractController
{
    public function __construct(
        private UtilisateurEntiteManager $utilisateurEntiteManager,
        private MailerManager $mailerManager,
        private PhotoManager $photoManager,
        private FileUploader $fileUploader,
        private PdfManager $pdfManager,
        private HttpClientInterface $httpClient,
    ) {}

    /* =========================================================
     * Helpers
     * ========================================================= */
    /** Retourne "12/11 09:00–17:00, 15/11 09:00–12:30" (limité) */
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

    /** Premier début de jour (ou null) */
    private function firstStart(Session $s): ?\DateTimeImmutable
    {
        $first = null;
        foreach ($s->getJours() as $j) {
            $d = $j->getDateDebut();
            $first = $first ? min($first, $d) : $d;
        }
        return $first;
    }

    /** Dernière fin de jour (ou null) */
    private function lastEnd(Session $s): ?\DateTimeImmutable
    {
        $last = null;
        foreach ($s->getJours() as $j) {
            $d = $j->getDateFin();
            $last = $last ? max($last, $d) : $d;
        }
        return $last;
    }

    /* =========================================================
     * DASHBOARD
     * ========================================================= */
    #[Route('/dashboard', name: 'dashboard', methods: ['GET'])]
    public function dashboard(Entite $entite, EntityManagerInterface $em): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $utilisateurEntite = $this->utilisateurEntiteManager->getUserEntiteLink($entite);

        // ============================
        // Prochaine session (à venir)
        // ============================
        $nextSession = null;

        $formateur = $user->getFormateur();
        if ($formateur) {

            $tz = new \DateTimeZone('Europe/Paris'); // ou self::TZ si tu veux
            $now = new \DateTimeImmutable('now', $tz);
            $todayStart = $now->setTime(0, 0, 0); // ✅ début de journée

            $nextJour = $em->createQueryBuilder()
                ->select('j', 's', 'fo', 'si')
                ->from(SessionJour::class, 'j')
                ->join('j.session', 's')
                ->join('s.formation', 'fo')
                ->leftJoin('s.site', 'si')
                ->andWhere('s.formateur = :f')->setParameter('f', $formateur)
                ->andWhere('s.entite = :e')->setParameter('e', $entite)

                // ✅ inclut aujourd'hui même si l'horaire est déjà passé
                ->andWhere('j.dateDebut >= :todayStart')->setParameter('todayStart', $todayStart)

                ->orderBy('j.dateDebut', 'ASC')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();


            if ($nextJour) {
                /** @var SessionJour $nextJour */
                $s = $nextJour->getSession();

                $inscrits = (int) $em->createQueryBuilder()
                    ->select('COUNT(i.id)')
                    ->from(Inscription::class, 'i')
                    ->andWhere('i.session = :s')->setParameter('s', $s)
                    ->andWhere('i.stagiaire IS NOT NULL')
                    ->getQuery()
                    ->getSingleScalarResult();

                $nextSessionAddress = (
                    method_exists($s, 'getSite') && $s->getSite()
                    ? trim(implode(', ', array_filter([
                        method_exists($s->getSite(), 'getAdresse') ? $s->getSite()->getAdresse() : null,
                        method_exists($s->getSite(), 'getCodePostal') ? $s->getSite()->getCodePostal() : null,
                        method_exists($s->getSite(), 'getVille') ? $s->getSite()->getVille() : null,
                        method_exists($s->getSite(), 'getPays') ? $s->getSite()->getPays() : null,
                    ])))
                    : null
                );

                $nextSession = [
                    'id'             => $s->getId(),
                    'code'           => $s->getCode(),
                    'formationTitre' => $s->getFormation()
                        ? $s->getFormation()->getTitre()
                        : $s->getFormationIntituleLibre(),
                    'siteNom'        => method_exists($s, 'getSite') ? ($s->getSite()?->getNom()) : null,
                    'start'          => $nextJour->getDateDebut(),
                    'end'            => $nextJour->getDateFin(),
                    'capacite'       => method_exists($s, 'getCapacite') ? $s->getCapacite() : null,
                    'inscrits'       => $inscrits,
                    'adresseComplete' => $nextSessionAddress,
                    'equipements' => [
                        'equipOrdinateurFormateur'       => method_exists($s, 'isEquipOrdinateurFormateur') ? $s->isEquipOrdinateurFormateur() : null,
                        'equipVideoprojecteurEcran'      => method_exists($s, 'isEquipVideoprojecteurEcran') ? $s->isEquipVideoprojecteurEcran() : null,
                        'equipInternetStable'            => method_exists($s, 'isEquipInternetStable') ? $s->isEquipInternetStable() : null,
                        'equipTableauPaperboard'         => method_exists($s, 'isEquipTableauPaperboard') ? $s->isEquipTableauPaperboard() : null,
                        'equipMarqueursSupportsImprimes' => method_exists($s, 'isEquipMarqueursSupportsImprimes') ? $s->isEquipMarqueursSupportsImprimes() : null,
                        'salleAdapteeTailleGroupe'       => method_exists($s, 'isSalleAdapteeTailleGroupe') ? $s->isSalleAdapteeTailleGroupe() : null,
                        'salleTablesChaisesErgo'         => method_exists($s, 'isSalleTablesChaisesErgo') ? $s->isSalleTablesChaisesErgo() : null,
                        'salleLumiereChauffageClim'      => method_exists($s, 'isSalleLumiereChauffageClim') ? $s->isSalleLumiereChauffageClim() : null,
                        'salleEauCafe'                   => method_exists($s, 'isSalleEauCafe') ? $s->isSalleEauCafe() : null,
                    ],
                ];

                // ==========================================
                // ✅ Distance/Durée via ROUTES API (Directions v2)
                // ==========================================
                $origin = trim((string) ($user->getAdresseComplete() ?? ''));
                $dest   = trim((string) ($nextSessionAddress ?? ''));

                // $origin et $dest = adresses texte (ou mieux lat/lng, voir plus bas)
                $distanceText = null;
                $durationText = null;
                $gmapsError   = null;

                $serverKey = (string) $this->getParameter('GOOGLE_MAPS_SERVER_KEY');

                if ($origin !== '' && $dest !== '' && $serverKey) {
                    try {
                        $payload = [
                            'origin' => [
                                'address' => $origin,
                            ],
                            'destination' => [
                                'address' => $dest,
                            ],
                            'travelMode' => 'DRIVE',
                            'routingPreference' => 'TRAFFIC_AWARE',
                            'languageCode' => 'fr-FR',
                            'regionCode' => 'FR',
                            // optionnel : évite péages/autoroutes
                            // 'routeModifiers' => ['avoidTolls' => false, 'avoidHighways' => false],
                        ];

                        $resp = $this->httpClient->request('POST', 'https://routes.googleapis.com/directions/v2:computeRoutes', [
                            'headers' => [
                                'X-Goog-Api-Key'    => $serverKey,
                                // ✅ indispensable : FieldMask
                                'X-Goog-FieldMask'  => 'routes.distanceMeters,routes.duration',
                                'Content-Type'      => 'application/json',
                            ],
                            'json' => $payload,
                            'timeout' => 8,
                        ]);

                        $json = $resp->toArray(false);

                        $route = $json['routes'][0] ?? null;
                        if (!$route) {
                            $gmapsError = 'Routes API: aucune route retournée'
                                . (isset($json['error']['message']) ? (' - ' . $json['error']['message']) : '');
                        } else {
                            $meters = (int) ($route['distanceMeters'] ?? 0);
                            $dur    = (string) ($route['duration'] ?? '');

                            // distance formatée
                            if ($meters > 0) {
                                $km = $meters / 1000;
                                $distanceText = ($km >= 10)
                                    ? number_format($km, 0, ',', ' ') . ' km'
                                    : number_format($km, 1, ',', ' ') . ' km';
                            }

                            // duration est du type "4876s"
                            if (preg_match('/^(\d+)s$/', $dur, $m)) {
                                $sec = (int) $m[1];
                                $min = (int) floor($sec / 60);
                                $h   = (int) floor($min / 60);
                                $m2  = $min % 60;
                                $durationText = $h > 0 ? ($h . ' h ' . $m2 . ' min') : ($m2 . ' min');
                            }
                        }
                    } catch (\Throwable $e) {
                        $gmapsError = 'Routes API exception: ' . $e->getMessage();
                    }
                } else {
                    $gmapsError = 'Origin/Destination manquante (adresse profil ou adresse site) ou clé server absente';
                }


                // ==========================================
                // ✅ Liens + embed
                // ==========================================
                $gmapsDirectionsUrl = ($origin && $dest)
                    ? 'https://www.google.com/maps/dir/?api=1&origin=' . rawurlencode($origin)
                    . '&destination=' . rawurlencode($dest)
                    . '&travelmode=driving'
                    : (($dest) ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($dest) : null);


                // ⚠️ Embed directions (nécessite Maps Embed API + billing)
                $serverKey  = (string) $this->getParameter('GOOGLE_MAPS_SERVER_KEY');
                $browserKey = (string) $this->getParameter('GOOGLE_MAPS_BROWSER_KEY'); // <-- ajoute ça

                // ...
                // Routes API (serveur)
                if ($origin !== '' && $dest !== '' && $serverKey) {
                    // computeRoutes avec $serverKey (inchangé)
                }

                // Embed (navigateur)
                $gmapsEmbedUrl = null;
                if ($browserKey !== '' && $origin !== '' && $dest !== '') {
                    $gmapsEmbedUrl = 'https://www.google.com/maps/embed/v1/directions?key=' . rawurlencode($browserKey)
                        . '&origin=' . rawurlencode($origin)
                        . '&destination=' . rawurlencode($dest)
                        . '&mode=driving';
                }



                // ✅ fallback iframe: simple carte sur destination (toujours OK)
                $gmapsEmbedUrlFallback = ($dest !== '')
                    ? 'https://www.google.com/maps?q=' . rawurlencode($dest) . '&output=embed'
                    : null;

                $nextSession = array_merge($nextSession, [
                    'originAddress' => $origin ?: null,
                    'destAddress'   => $dest ?: null,
                    'distanceText'  => $distanceText,
                    'durationText'  => $durationText,
                    'gmapsError'    => $gmapsError,

                    'gmapsDirectionsUrl'     => $gmapsDirectionsUrl,
                    'wazeUrl'                => ($dest) ? 'https://waze.com/ul?q=' . rawurlencode($dest) . '&navigate=yes' : null,

                    'gmapsEmbedUrl'          => $gmapsEmbedUrl,
                    'gmapsEmbedUrlFallback'  => $gmapsEmbedUrlFallback,
                ]);
            }
        }

        return $this->render('formateur/dashboard.html.twig', [
            'entite'            => $entite,
            'utilisateurEntite' => $utilisateurEntite,
            'nextSession'       => $nextSession,

            'google_maps_server_key'  => (string) $this->getParameter('GOOGLE_MAPS_SERVER_KEY'),
            'google_maps_browser_key' => (string) $this->getParameter('GOOGLE_MAPS_BROWSER_KEY'),
        ]);
    }






    #[Route('/dashboard/sessions/ajax', name: 'dashboard_sessions_ajax', methods: ['POST'])]
    public function sessionsAjax(
        Entite $entite,
        Request $request,
        SessionRepository $sessions,
        EntityManagerInterface $em
    ): JsonResponse {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        // 1) Charge les sessions du formateur (avec formation + jours pour formater dates)
        /** @var Session[] $list */
        $list = $sessions->createQueryBuilder('s')
            ->leftJoin('s.formateur', 'f')->addSelect('f')
            ->leftJoin('f.utilisateur', 'u')->addSelect('u')
            ->leftJoin('s.formation', 'fo')->addSelect('fo')
            ->leftJoin('s.site', 'si')->addSelect('si')                 // ✅
            ->leftJoin('s.organismeFormation', 'org')->addSelect('org') // ✅ (Entreprise)
            ->leftJoin('s.jours', 'j')->addSelect('j')
            ->andWhere('u = :me')->setParameter('me', $user)
            ->addSelect('(SELECT MIN(j2.dateDebut) FROM App\Entity\SessionJour j2 WHERE j2.session = s) AS HIDDEN firstStart')
            ->addSelect('(SELECT MAX(j3.dateFin)   FROM App\Entity\SessionJour j3 WHERE j3.session = s) AS HIDDEN lastEnd')
            ->addOrderBy('firstStart', 'DESC')
            ->getQuery()
            ->getResult();

        if (!$list) {
            return new JsonResponse([
                'data' => [],
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'draw' => (int)($request->attributes->get('draw') ?? 1),
            ]);
        }

        // 2) Récupère en 1 requête le nb de stagiaires par session via INSCRIPTION, en excluant le formateur
        $sessionIds = array_map(fn(Session $s) => $s->getId(), $list);

        $counts = $em->createQueryBuilder()
            ->select('IDENTITY(i.session) AS sid, COUNT(i.id) AS c')
            ->from(Inscription::class, 'i')
            ->join('i.session', 's2')
            ->leftJoin('s2.formateur', 'f2')
            ->leftJoin('f2.utilisateur', 'u2')
            ->andWhere('i.session IN (:ids)')->setParameter('ids', $sessionIds)
            ->andWhere('i.stagiaire IS NOT NULL')
            // ✅ exclusion formateur (si jamais le formateur est aussi “inscrit”)
            ->andWhere('(u2 IS NULL OR i.stagiaire != u2)')
            ->groupBy('sid')
            ->getQuery()
            ->getArrayResult();

        $bySid = [];
        foreach ($counts as $row) {
            $bySid[(int)$row['sid']] = (int)$row['c'];
        }


        // 3) Format JSON pour DataTable
        $rows = array_map(function (Session $s) use ($entite, $bySid) {
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

                // Timeline : max 3 jours affichés
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
                foreach ($lines as $it) {
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


            $inscrits = $bySid[$s->getId()] ?? 0;

            $formationTitre = $s->getFormation()
                ? (string) $s->getFormation()->getTitre()
                : (string) ($s->getFormationIntituleLibre() ?: '—');

            $siteNom = $s->getSite()?->getNom();
            $orgNom  = $s->getOrganismeFormation()?->getRaisonSociale(); // Entreprise

            // Prix (si tu veux l’afficher)
            $prix = null;
            if (method_exists($s, 'getMontantCents') && $s->getMontantCents() !== null) {
                $prix = number_format($s->getMontantCents() / 100, 2, ',', ' ') . ' €';
            }

            // Capacité
            $capacite = method_exists($s, 'getCapacite') ? $s->getCapacite() : null;

            // Financement (enum)
            $fin = method_exists($s, 'getTypeFinancement') ? $s->getTypeFinancement() : null;
            $finLabel = $fin ? $fin->name : null; // ou ->value si tu as des values

            // Statut (enum)
            $st = method_exists($s, 'getStatus') ? $s->getStatus() : null;
            $stLabel = $st?->label();

            // Petites chips (avec icônes)
            $chips = [];

            if ($siteNom) {
                $chips[] = sprintf(
                    '<span class="dt-chip-sm"><i class="bi bi-geo-alt"></i> %s</span>',
                    htmlspecialchars($siteNom, ENT_QUOTES)
                );
            }
            if ($finLabel) {
                $chips[] = sprintf(
                    '<br /><span class="dt-chip-sm dt-chip-muted"><i class="bi bi-cash-coin"></i> %s</span>',
                    htmlspecialchars($finLabel, ENT_QUOTES)
                );
            }
            if ($stLabel) {
                // tu peux affiner selon tes statuts réels
                $cls = in_array($stLabel, ['PUBLISHED', 'ACTIVE', 'VALIDEE'], true) ? 'dt-chip-ok' : 'dt-chip-warn';
                $chips[] = sprintf(
                    '<br /><span class="dt-chip-sm %s"><i class="bi bi-shield-check"></i> %s</span>',
                    $cls,
                    htmlspecialchars($stLabel, ENT_QUOTES)
                );
            }
            if ($capacite !== null) {
                if ((int)$capacite > 1)
                    $chips[] = sprintf(
                        '<br /><span class="dt-chip-sm"><i class="bi bi-people"></i> %d stagiaires</span>',
                        (int)$capacite
                    );
                else
                    $chips[] = sprintf(
                        '<br /><span class="dt-chip-sm"><i class="bi bi-people"></i> %d stagiaire</span>',
                        (int)$capacite
                    );
            }
            if ($prix) {
                $chips[] = sprintf(
                    '<br /><span class="dt-chip-sm"><i class="bi bi-tag"></i> %s</span>',
                    htmlspecialchars($prix, ENT_QUOTES)
                );
            }

            // Sous-ligne organisme formation (si présent)
            $sub = $orgNom
                ? '<div class="dt-formation-sub"><i class="bi bi-building"></i> ' . htmlspecialchars($orgNom, ENT_QUOTES) . '</div>'
                : '';


            $formationHtml = sprintf(
                '<div class="dt-formation" data-order="%s">
                    <div class="dt-formation-title"><i class="bi bi-mortarboard me-2"></i>%s</div>
                    %s
                    %s
                </div>',
                htmlspecialchars($formationTitre, ENT_QUOTES),
                htmlspecialchars($formationTitre, ENT_QUOTES),
                $sub,
                $chips ? ('<div class="dt-formation-chips">' . implode('', $chips) . '</div>') : ''
            );

            return [
                'sessionId' => $s->getId(),
                'code'      => $s->getCode() ?: '-',
                'formation' => $formationHtml, // ✅ HTML riche
                'dates'     => $dates,
                'inscrits'  => $inscrits,
                'actions'   => $this->renderView('formateur/_actions_session.html.twig', [
                    's' => $s,
                    'entite' => $entite,
                ]),
                'firstStartIso' => $first?->format(\DateTimeInterface::ATOM),
                'lastEndIso'    => $last?->format(\DateTimeInterface::ATOM),
            ];
        }, $list);

        return new JsonResponse([
            'data' => $rows,
            'recordsTotal' => \count($rows),
            'recordsFiltered' => \count($rows),
            'draw' => (int)($request->attributes->get('draw') ?? 1),
        ]);
    }


    #[Route('/calendar/feed', name: 'calendar_feed', methods: ['GET'])]
    public function calendarFeed(Entite $entite, SessionRepository $sessions): JsonResponse
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $formateur = $user->getFormateur();
        if (!$formateur) {
            return new JsonResponse([]); // pas de formateur => pas d'événements
        }

        /** @var Session[] $list */
        $list = $sessions->createQueryBuilder('s')
            ->leftJoin('s.jours', 'j')->addSelect('j')
            ->leftJoin('s.formation', 'fo')->addSelect('fo')
            ->andWhere('s.entite = :e')->setParameter('e', $entite)      // ✅ tenant
            ->andWhere('s.formateur = :f')->setParameter('f', $formateur) // ✅ ownership
            ->andWhere('j.id IS NOT NULL')                                // ✅ évite sessions sans jours
            ->addOrderBy('j.dateDebut', 'ASC')
            ->getQuery()
            ->getResult();

        $events = [];
        foreach ($list as $s) {
            foreach ($s->getJours() as $j) {
                $title = trim(sprintf(
                    '%s - %s',
                    $s->getFormation() ? $s->getFormation()->getTitre() : ($s->getFormationIntituleLibre() ?: 'Session'),
                    $s->getCode() ?: ('#' . $s->getId())
                ));

                $events[] = [
                    'id'    => (string) $s->getId(),
                    'title' => $title,
                    'start' => $j->getDateDebut()->format(\DateTimeInterface::ATOM),
                    'end'   => $j->getDateFin()->format(\DateTimeInterface::ATOM),
                    'extendedProps' => [
                        'sessionId' => (int) $s->getId(),
                        'jourId'    => (int) $j->getId(),
                    ],
                ];
            }
        }

        return new JsonResponse($events);
    }





    /* =========================================================
     * GESTION SUPPORTS PAR SESSION
     * ========================================================= */

    #[Route('/session/{id}/supports', name: 'session_supports', methods: ['GET'])]
    public function libraryFromSession(
        Entite $entite,
        Session $id,
        EntityManagerInterface $em
    ): Response {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $isOwner = $id->getFormateur()?->getUtilisateur()?->getId() === $user->getId();


        if (!$isOwner && !$this->isEntiteAdmin($entite)) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('formateur/supports.html.twig', [
            'entite' => $entite,
            'session' => $id,

        ]);
    }

    /** Upload vers la bibliothèque personnelle */
    #[Route('/supports/library/upload', name: 'supports_library_upload', methods: ['POST'])]
    public function libraryUpload(Entite $entite, Request $request, EntityManagerInterface $em): JsonResponse
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        if (!$request->files->has('files')) {
            return new JsonResponse(['success' => false, 'message' => 'Aucun fichier.'], 400);
        }

        /** @var UploadedFile[] $files */
        $files = $request->files->get('files');
        $dir = $this->getParameter('kernel.project_dir') . '/public/uploads/supports/library';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);

        $created = 0;
        foreach ($files as $file) {
            if (!$file instanceof UploadedFile) {
                continue;
            }

            $originalName = $file->getClientOriginalName();
            $clientMime   = $file->getClientMimeType();
            $clientSize   = $file->getSize();
            $title        = pathinfo($originalName, PATHINFO_FILENAME);
            $ext          = $file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin';

            $newName = sprintf('asset_%s.%s', bin2hex(random_bytes(6)), $ext);
            $file->move($dir, $newName);

            $absPath   = rtrim($dir, '/') . '/' . $newName;
            $finalSize = $clientSize ?: @filesize($absPath) ?: null;

            if (!$clientMime && is_file($absPath)) {
                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $clientMime = $finfo->file($absPath) ?: null;
            }

            $asset = (new SupportAsset())
                ->setCreateur($user)
                ->setUploadedBy($user)
                ->setTitre($title)
                ->setFilename($newName)
                ->setOriginalName($originalName)
                ->setMimeType($clientMime)
                ->setSizeBytes($finalSize ? (int)$finalSize : null)
                ->setUploadedAt(new \DateTimeImmutable())
                ->setEntite($entite);

            $em->persist($asset);
            $created++;
        }

        if ($created > 0) $em->flush();

        return new JsonResponse(['success' => true, 'count' => $created]);
    }

    /** Basculer visibilité d’un support lié à une session (AJAX) */
    #[Route('/support/{link}/toggle', name: 'support_toggle', methods: ['POST'])]
    public function toggleSupport(Entite $entite, SupportAssignSession $link, EntityManagerInterface $em): JsonResponse
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $isOwnerOfAsset   = $link->getAsset()?->getUploadedBy()?->getId() === $user->getId();
        $isOwnerOfSession = $link->getSession()?->getFormateur()?->getUtilisateur()?->getId() === $user->getId();

        if (!($isOwnerOfAsset || $isOwnerOfSession) && !$this->isEntiteAdmin($entite)) {
            return new JsonResponse(['success' => false], 403);
        }

        $link->setIsVisibleToTrainee(!$link->isVisibleToTrainee());
        $em->flush();

        return new JsonResponse(['success' => true, 'visible' => $link->isVisibleToTrainee()]);
    }


    #[Route('/contrats/kpis', name: 'contrats_kpis', methods: ['GET'])]
    public function contratsKpis(Entite $entite, EntityManagerInterface $em, Request $request): JsonResponse
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $formateur = $user->getFormateur();
        if (!$formateur) {
            return new JsonResponse(['count' => 0, 'draft' => 0, 'pending' => 0, 'signed' => 0]);
        }

        // Optionnel : si tu veux que les KPIs suivent le filtre, tu peux le garder,
        // mais ici je te donne des KPIs "globaux" (plus utile la plupart du temps).
        $qb = $em->createQueryBuilder()
            ->from(ContratFormateur::class, 'c')
            ->select('COUNT(c.id)')
            ->andWhere('c.entite = :e')->setParameter('e', $entite)
            ->andWhere('c.formateur = :f')->setParameter('f', $formateur);

        $count = (int)(clone $qb)->select('COUNT(c.id)')->getQuery()->getSingleScalarResult();

        $draft = (int)(clone $qb)
            ->select('COUNT(c.id)')
            ->andWhere('c.status = :s')->setParameter('s', ContratFormateurStatus::BROUILLON)
            ->getQuery()->getSingleScalarResult();

        $pending = (int)(clone $qb)
            ->select('COUNT(c.id)')
            ->andWhere('c.status = :s2')->setParameter('s2', ContratFormateurStatus::ENVOYE)
            ->getQuery()->getSingleScalarResult();

        $signed = (int)(clone $qb)
            ->select('COUNT(c.id)')
            ->andWhere('c.status = :s3')->setParameter('s3', ContratFormateurStatus::SIGNE)
            ->getQuery()->getSingleScalarResult();

        return new JsonResponse([
            'count'   => $count,
            'draft'   => $draft,
            'pending' => $pending,
            'signed'  => $signed,
        ]);
    }





    /* =========================================================
     * BIBLIOTHÈQUE DE SUPPORTS
     * ========================================================= */

    #[Route('/supports/library', name: 'supports_library', methods: ['GET'])]
    public function library(Entite $entite): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        return $this->render('formateur/supports_library.html.twig', [
            'entite' => $entite,
            'user' => $user,

        ]);
    }




    #[Route('/supports/library/assets', name: 'supports_assets_feed', methods: ['GET'])]
    public function assetsFeed(Entite $entite, EntityManagerInterface $em, Request $request): JsonResponse
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $assets = $em->getRepository(SupportAsset::class)->createQueryBuilder('a')
            ->andWhere('a.uploadedBy = :me')->setParameter('me', $user)
            ->orderBy('a.uploadedAt', 'DESC')->getQuery()->getResult();

        $basePath = rtrim($request->getBasePath(), '/');

        $rows = array_map(function (SupportAsset $a) use ($basePath) {
            $publicUrl = $basePath . '/uploads/supports/library/' . $a->getFilename();

            $mime = (string) ($a->getMimeType() ?? '');

            // ✅ Label court (PDF, Word, Image, etc.)
            $mimeLabel = match (true) {
                str_contains($mime, 'pdf') => 'PDF',
                str_starts_with($mime, 'image/') => 'Image',
                str_contains($mime, 'msword') || str_contains($mime, 'wordprocessingml') => 'Word',
                str_contains($mime, 'excel') || str_contains($mime, 'spreadsheetml') => 'Excel',
                str_contains($mime, 'powerpoint') || str_contains($mime, 'presentationml') => 'PowerPoint',
                str_starts_with($mime, 'text/') => 'Texte',
                $mime !== '' => strtoupper(substr($mime, strrpos($mime, '/') + 1)), // ex: "json", "zip" => JSON, ZIP
                default => '-',
            };

            return [
                'titre'       => $a->getTitre(),
                'filename'    => $a->getFilename(),
                'originalName' => $a->getOriginalName(),
                'mimeType'    => $mimeLabel, // ✅ au lieu de "application/pdf"
                'uploadedAt'  => $a->getUploadedAt()?->format('d/m/Y'),
                'size'        => $a->getSizeBytes(),
                'url'         => $publicUrl,
            ];
        }, $assets);

        return new JsonResponse(['data' => $rows]);
    }




    /**
     * Feed des sessions du formateur (pour affectation depuis la bibliothèque)
     * Retour: [{id, label, code, formation, dates}]
     */
    #[Route('/supports/library/sessions', name: 'supports_sessions_feed', methods: ['GET'])]
    public function sessionsFeed(Entite $entite, EntityManagerInterface $em): JsonResponse
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();



        // Sessions appartenant au formateur connecté ET à l'entité courante
        $qb = $em->createQueryBuilder()
            ->select('s, fo')
            ->addSelect('MIN(j.dateDebut) AS HIDDEN minStart')
            ->addSelect('MAX(j.dateFin)   AS HIDDEN maxEnd')
            ->from(Session::class, 's')
            ->join('s.formateur', 'f')
            ->join('f.utilisateur', 'u')
            ->join('s.formation', 'fo')
            ->leftJoin('s.jours', 'j')
            ->andWhere('u = :me')->setParameter('me', $user)
            ->andWhere('s.entite = :e')->setParameter('e', $entite)
            ->groupBy('s.id, fo.id')
            ->orderBy('minStart', 'DESC');

        /** @var Session[] $list */
        $list = $qb->getQuery()->getResult();

        $rows = array_map(function (Session $s) {
            $first = $this->firstStart($s);
            $last  = $this->lastEnd($s);

            $dates = $s->getJours()->isEmpty()
                ? '-'
                : sprintf(
                    '%s → %s (%d j)',
                    $first?->format('d/m/Y H:i') ?? '…',
                    $last?->format('d/m/Y H:i') ?? '…',
                    \count($s->getJours())
                );

            $label = trim(sprintf(
                '%s - %s • %s',
                $s->getCode() ?: ('Session #' . $s->getId()),
                $s->getFormation()
                    ? $s->getFormation()->getTitre()
                    : $s->getFormationIntituleLibre(),
                $dates
            ));

            return [
                'id'        => (int) $s->getId(),
                'label'     => $label,               // pratique pour TomSelect
                'code'      => $s->getCode(),
                'formation' => $s->getFormation()
                    ? $s->getFormation()->getTitre()
                    : $s->getFormationIntituleLibre(),
                'dates'     => $dates,
            ];
        }, $list);

        return new JsonResponse(['data' => $rows]);
    }

    /**
     * Feed des stagiaires (pour affectation utilisateur) - via INSCRIPTION
     * GET ?sessionId=123
     * Retour: [{id, label, nom, email}]
     */
    #[Route('/supports/library/trainees', name: 'supports_trainees_feed', methods: ['GET'])]
    public function traineesFeed(Entite $entite, EntityManagerInterface $em, Request $request): JsonResponse
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $sessionId = (int) $request->query->get('sessionId');
        if ($sessionId <= 0) {
            return new JsonResponse(['data' => []]);
        }

        // Sécurité: la session doit appartenir à l'entité + au formateur connecté
        $sessionOk = $em->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(Session::class, 's')
            ->join('s.formateur', 'f')
            ->join('f.utilisateur', 'u')
            ->andWhere('s.id = :sid')->setParameter('sid', $sessionId)
            ->andWhere('s.entite = :e')->setParameter('e', $entite)
            ->andWhere('u = :me')->setParameter('me', $user)
            ->getQuery()
            ->getSingleScalarResult();

        if ((int)$sessionOk === 0 && !$this->isEntiteAdmin($entite)) {
            return new JsonResponse(['data' => []], 403);
        }

        // Liste des stagiaires inscrits à la session (distinct)
        $rowsRaw = $em->createQueryBuilder()
            ->select('DISTINCT u.id AS id, u.nom AS nom, u.prenom AS prenom, u.email AS email')
            ->from(Inscription::class, 'i')
            ->join('i.stagiaire', 'u')
            ->join('i.session', 's')
            ->andWhere('s.id = :sid')->setParameter('sid', $sessionId)
            ->andWhere('i.stagiaire IS NOT NULL')
            ->orderBy('u.nom', 'ASC')
            ->addOrderBy('u.prenom', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $rows = array_map(static function (array $x) {
            $fullName = trim(($x['prenom'] ?? '') . ' ' . ($x['nom'] ?? ''));
            $email = (string)($x['email'] ?? '');

            return [
                'id'    => (int) $x['id'],                // <- IMPORTANT: id numérique pour select
                'label' => $email ? ($fullName . ' - ' . $email) : $fullName, // pratique TomSelect
                'nom'   => $fullName,
                'email' => $email,
            ];
        }, $rowsRaw);

        return new JsonResponse(['data' => $rows]);
    }






    /** Feed des affectations d’un asset (sessions + users) */
    #[Route('/supports/library/assignments', name: 'supports_assignments_feed', methods: ['GET'])]
    public function assignmentsFeed(Request $req, EntityManagerInterface $em): JsonResponse
    {
        $assetId = (int)$req->query->get('assetId');
        $asset = $em->getRepository(SupportAsset::class)->find($assetId);
        if (!$asset) return new JsonResponse(['sessions' => [], 'users' => []]);

        // Sessions liées à l’asset, avec fenêtre min/max
        $sess = $em->createQueryBuilder()
            ->select('l.id as linkId, s.id as sessionId, s.code as code, fo.titre as formation, MIN(j.dateDebut) as d1, MAX(j.dateFin) as d2, COUNT(j.id) as nb')
            ->from(SupportAssignSession::class, 'l')
            ->join('l.session', 's')
            ->join('s.formation', 'fo')
            ->leftJoin('s.jours', 'j')
            ->andWhere('l.asset = :a')->setParameter('a', $asset)
            ->groupBy('l.id, s.id, fo.id, code')
            ->addOrderBy('d1', 'DESC')
            ->getQuery()->getArrayResult();

        $sessions = array_map(function ($r) {
            $d1 = $r['d1'] instanceof \DateTimeInterface ? $r['d1']->format('d/m/Y H:i') : '';
            $d2 = $r['d2'] instanceof \DateTimeInterface ? $r['d2']->format('d/m/Y H:i') : '';
            return [
                'linkId'   => (int)$r['linkId'],
                'sessionId' => (int)$r['sessionId'],
                'code'     => (string)$r['code'],
                'formation' => (string)$r['formation'],
                'dates'    => $d1 && $d2 ? "$d1 → $d2 ({$r['nb']} j)" : '-',
            ];
        }, $sess);

        // Utilisateurs liés inchangé
        $usr = $em->createQueryBuilder()
            ->select('l.id as linkId, u.id as userId, u.prenom, u.nom, u.email')
            ->from(SupportAssignUser::class, 'l')
            ->join('l.user', 'u')
            ->andWhere('l.asset = :a')->setParameter('a', $asset)
            ->orderBy('u.nom', 'ASC')
            ->getQuery()->getArrayResult();

        $users = array_map(function ($r) {
            return [
                'linkId' => (int)$r['linkId'],
                'userId' => (int)$r['userId'],
                'nom'    => trim(($r['prenom'] ?? '') . ' ' . ($r['nom'] ?? '')),
                'email'  => (string)$r['email'],
            ];
        }, $usr);

        return new JsonResponse(['sessions' => $sessions, 'users' => $users]);
    }

    /** Affectation d’un asset → plusieurs sessions */
    #[Route('/supports/library/assign/sessions', name: 'supports_assign_sessions', methods: ['POST'])]
    public function assignSessions(Entite $entite, Request $req, EntityManagerInterface $em): JsonResponse
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $assetId = (int)$req->request->get('assetId');
        $sessionIds = (array)$req->request->all('sessionIds');
        $visible = (bool)$req->request->get('visible', true);

        $asset = $em->getRepository(SupportAsset::class)->find($assetId);
        if (!$asset) return new JsonResponse(['success' => false, 'message' => 'Asset introuvable'], 404);
        if ($asset->getUploadedBy()?->getId() !== $user->getId() && !$this->isEntiteAdmin($entite)) {
            return new JsonResponse(['success' => false], 403);
        }

        $added = 0;
        foreach ($sessionIds as $sid) {
            $session = $em->getRepository(Session::class)->find((int)$sid);
            if (!$session) continue;

            $ownerOk = $session->getFormateur()?->getUtilisateur()?->getId() === $user->getId();
            if (!$ownerOk && !$this->isEntiteAdmin($entite)) continue;

            $exists = $em->getRepository(SupportAssignSession::class)->findOneBy([
                'asset' => $asset,
                'session' => $session
            ]);
            if ($exists) continue;

            $link = (new SupportAssignSession())
                ->setCreateur($user)
                ->setEntite($entite)
                ->setAsset($asset)
                ->setSession($session)
                ->setIsVisibleToTrainee($visible)
                ->setCreatedAt(new \DateTimeImmutable());

            $em->persist($link);
            $added++;
        }
        if ($added > 0) $em->flush();

        return new JsonResponse(['success' => true, 'added' => $added]);
    }

    /** Affectation d’un asset → plusieurs stagiaires */
    #[Route('/supports/library/assign/users', name: 'supports_assign_users', methods: ['POST'])]
    public function assignUsers(Entite $entite, Request $req, EntityManagerInterface $em): JsonResponse
    {
        /** @var Utilisateur $me */
        $me = $this->getUser();

        $assetId = (int)$req->request->get('assetId');
        $userIds = (array)$req->request->all('userIds');
        $visible = (bool)$req->request->get('visible', true);

        $asset = $em->getRepository(SupportAsset::class)->find($assetId);
        if (!$asset) return new JsonResponse(['success' => false, 'message' => 'Asset introuvable'], 404);

        if ($asset->getUploadedBy()?->getId() !== $me->getId() && !$this->isEntiteAdmin($entite)) {
            return new JsonResponse(['success' => false], 403);
        }

        $added = 0;
        foreach ($userIds as $uid) {
            $target = $em->getRepository(Utilisateur::class)->find((int)$uid);
            if (!$target) continue;

            $exists = $em->getRepository(SupportAssignUser::class)->findOneBy([
                'asset' => $asset,
                'user'  => $target
            ]);
            if ($exists) continue;

            $link = (new SupportAssignUser())
                ->setCreateur($me)          // ✅ createur = formateur connecté
                ->setEntite($entite)
                ->setAsset($asset)
                ->setUser($target)          // ✅ user = stagiaire cible
                ->setIsVisibleToTrainee($visible)
                ->setCreatedAt(new \DateTimeImmutable());

            $em->persist($link);
            $added++;
        }

        if ($added > 0) $em->flush();

        return new JsonResponse(['success' => true, 'added' => $added]);
    }


    /** Retirer affectation session */
    #[Route('/supports/library/unassign/session', name: 'supports_unassign_session', methods: ['POST'])]
    public function unassignSession(Entite $entite, Request $req, EntityManagerInterface $em): JsonResponse
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $id = (int)$req->request->get('id');
        $link = $em->getRepository(SupportAssignSession::class)->find($id);
        if (!$link) return new JsonResponse(['success' => false], 404);

        $asset = $link->getAsset();
        if ($asset->getUploadedBy()?->getId() !== $user->getId() && !$this->isEntiteAdmin($entite)) {
            return new JsonResponse(['success' => false], 403);
        }

        $em->remove($link);
        $em->flush();

        return new JsonResponse(['success' => true]);
    }

    /** Retirer affectation stagiaire */
    #[Route('/supports/library/unassign/user', name: 'supports_unassign_user', methods: ['POST'])]
    public function unassignUser(Entite $entite, Request $req, EntityManagerInterface $em): JsonResponse
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $id = (int)$req->request->get('id');
        $link = $em->getRepository(SupportAssignUser::class)->find($id);
        if (!$link) return new JsonResponse(['success' => false], 404);

        $asset = $link->getAsset();
        if ($asset->getUploadedBy()?->getId() !== $user->getId() && !$this->isEntiteAdmin($entite)) {
            return new JsonResponse(['success' => false], 403);
        }

        $em->remove($link);
        $em->flush();

        return new JsonResponse(['success' => true]);
    }



    #[Route('/contrats', name: 'contrats', methods: ['GET'])]
    public function mesContrats(Entite $entite): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        return $this->render('formateur/contrats.html.twig', [
            'entite' => $entite,

        ]);
    }




    #[Route('/contrats/ajax', name: 'contrats_ajax', methods: ['POST'])]
    public function contratsAjax(Entite $entite, Request $request, EntityManagerInterface $em): JsonResponse
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $formateur = $user->getFormateur();
        if (!$formateur) {
            return new JsonResponse([
                'draw' => $request->request->getInt('draw', 1),
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => [],
            ]);
        }

        $draw    = $request->request->getInt('draw', 1);
        $start   = max(0, $request->request->getInt('start', 0));
        $length  = $request->request->getInt('length', 10);
        if ($length <= 0) $length = 10;

        $searchV = (string)($request->request->all('search')['value'] ?? '');
        $order   = $request->request->all('order') ?? [];
        $statusFilter = (string)$request->request->get('statusFilter', 'all');

        // mapping colonnes (DataTables)
        $map = [
            0 => 'c.id',
            1 => 'c.numero',
            2 => 'c.dateCreation',
            3 => 'c.status',
            4 => 'c.signatureAt',
            5 => 'c.id',
        ];

        $qbBase = $em->getRepository(ContratFormateur::class)->createQueryBuilder('c')
            ->andWhere('c.entite = :e')->setParameter('e', $entite)
            ->andWhere('c.formateur = :f')->setParameter('f', $formateur);

        // recordsTotal
        $recordsTotal = (int)(clone $qbBase)
            ->select('COUNT(c.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()->getSingleScalarResult();

        // QB final
        $qb = clone $qbBase;

        // Search
        if ($searchV !== '') {
            $qb->andWhere('
            CAST(c.id AS string) LIKE :s
            OR c.numero LIKE :s
        ')->setParameter('s', '%' . $searchV . '%');
        }

        // Filtre statut (enum)
        $enum = match ($statusFilter) {
            'brouillon' => ContratFormateurStatus::BROUILLON,
            'envoye'    => ContratFormateurStatus::ENVOYE,
            'signe'     => ContratFormateurStatus::SIGNE,
            default     => null,
        };
        if ($enum) {
            $qb->andWhere('c.status = :st')->setParameter('st', $enum);
        }

        // recordsFiltered
        $recordsFiltered = (int)(clone $qb)
            ->select('COUNT(c.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()->getSingleScalarResult();

        // Order
        $colIdx = isset($order[0]['column']) ? (int)$order[0]['column'] : 0;
        $dir    = (isset($order[0]['dir']) && strtolower($order[0]['dir']) === 'asc') ? 'ASC' : 'DESC';
        $orderBy = $map[$colIdx] ?? 'c.id';

        // Data
        $rows = $qb->orderBy($orderBy, $dir)
            ->setFirstResult($start)
            ->setMaxResults($length)
            ->getQuery()->getResult();

        $data = [];
        foreach ($rows as $c) {
            /** @var ContratFormateur $c */
            $data[] = [
                'id'        => $c->getId(),
                'numero'    => $c->getNumero() ?: '-',
                'createdAt' => $c->getDateCreation() ? $c->getDateCreation()->format('d/m/Y H:i') : '-',
                'status'    => $this->renderView('formateur/_contrat_status_badge.html.twig', [
                    'c' => $c,
                ]),
                'signedAt'  => $c->getSignatureAt() ? $c->getSignatureAt()->format('d/m/Y H:i') : '-',
                'actions'   => $this->renderView('formateur/_contrat_actions.html.twig', [
                    'contrat' => $c,
                    'entite'  => $entite,
                ]),
            ];
        }

        return new JsonResponse([
            'draw'            => $draw,
            'recordsTotal'    => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data'            => $data,
        ]);
    }



    #[Route('/contrat/{contrat}/sign', name: 'contrat_sign', methods: ['GET'])]
    public function signForm(
        Entite $entite,
        ContratFormateur $contrat
    ): Response {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $formateur = $user->getFormateur();
        if (!$formateur || $contrat->getFormateur()?->getId() !== $formateur->getId()) {
            throw $this->createAccessDeniedException();
        }

        $hasSavedSignature = $formateur->getSignatureDataUrl() !== null;

        return $this->render('formateur/contrat_sign.html.twig', [
            'entite' => $entite,
            'contrat' => $contrat,
            'hasSavedSignature' => $hasSavedSignature,

        ]);
    }


    #[Route('/contrat/{contrat}/sign', name: 'contrat_sign_post', methods: ['POST'])]
    public function signSubmit(
        Entite $entite,
        ContratFormateur $contrat,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $formateur = $user->getFormateur();

        if (!$formateur || $contrat->getFormateur()?->getId() !== $formateur->getId()) {
            throw $this->createAccessDeniedException();
        }

        // (optionnel mais conseillé) : n’autoriser la signature que si le contrat est ENVOYE
        if (
            $contrat->getStatus() !== ContratFormateurStatus::ENVOYE
            && $contrat->getStatus() !== ContratFormateurStatus::BROUILLON
        ) {
            $this->addFlash('warning', 'Ce contrat n’est pas en attente de signature.');
            return $this->redirectToRoute('app_formateur_contrats', [
                'entite' => $entite->getId(),
            ]);
        }

        $useSaved = (bool) $request->request->get('use_saved_signature');
        $signatureData = $request->request->get('signature_data');

        if ($useSaved) {
            // On veut utiliser la signature enregistrée sur le profil formateur
            if (!$formateur->getSignatureDataUrl()) {
                $this->addFlash('danger', 'Aucune signature enregistrée n’a été trouvée sur votre profil.');
                return $this->redirectToRoute('app_formateur_contrat_sign', [
                    'entite' => $entite->getId(),
                    'contrat' => $contrat->getId(),
                ]);
            }

            $contrat->setSignatureDataUrl($formateur->getSignatureDataUrl());
        } else {
            // Signature dessinée dans le canvas
            if (!$signatureData) {
                $this->addFlash('danger', 'Merci de signer le contrat.');
                return $this->redirectToRoute('app_formateur_contrat_sign', [
                    'entite' => $entite->getId(),
                    'contrat' => $contrat->getId(),
                ]);
            }

            $contrat->setSignatureDataUrl($signatureData);

            // Enregistrer cette signature comme signature par défaut ?
            if ($request->request->getBoolean('save_signature_default')) {
                $formateur->setSignatureDataUrl($signatureData);
            }
        }

        $contrat
            ->setSignatureAt(new \DateTimeImmutable())
            ->setSignatureIp($request->getClientIp())
            ->setSignatureUserAgent((string) $request->headers->get('User-Agent'))
            ->setStatus(ContratFormateurStatus::SIGNE);

        // Génération / mise à jour du PDF signé
        $session      = $contrat->getSession();
        $formation    = $session?->getFormation();
        $inscriptions = $session?->getInscriptions() ?? new \Doctrine\Common\Collections\ArrayCollection();

        // garde uniquement les inscriptions avec un stagiaire
        $stagiaires = $inscriptions->filter(static fn($i) => $i->getStagiaire() !== null);

        // optionnel : exclure le formateur si jamais il est aussi “inscrit”
        $formateurUser = $contrat->getFormateur()?->getUtilisateur();
        if ($formateurUser) {
            $stagiaires = $stagiaires->filter(static fn($i) => $i->getStagiaire()?->getId() !== $formateurUser->getId());
        }


        $filename = sprintf(
            'contrat_formateur_%s.pdf',
            $contrat->getNumero() ?: $contrat->getId()
        );


        $projectDir = $this->getParameter('kernel.project_dir');
        $orgSigPath = $contrat->getSignatureOrganismePath() ?: $entite->getPreferences()?->getSignatureOrganismePath();

        $orgSigDataUri = null;

        if ($orgSigPath) {
            $orgSigPath = '/' . ltrim($orgSigPath, '/'); // "/uploads/..."
            $candidate = $projectDir . '/public' . $orgSigPath;

            if (is_file($candidate)) {
                $mime = mime_content_type($candidate) ?: 'image/png';
                $data = base64_encode(file_get_contents($candidate));
                $orgSigDataUri = 'data:' . $mime . ';base64,' . $data;
            }
        }



        $absolutePath = $this->pdfManager->contratFormateur([
            'entite'        => $entite,
            'contrat'       => $contrat,
            'session'       => $session,
            'formation'     => $formation,
            'stagiaires'    => $stagiaires,
            'orgSigDataUri' => $orgSigDataUri, // ✅
        ], $filename);



        // Chemin web (à adapter si ton dossier est différent)
        $publicPath = 'uploads/pdf/' . $filename;
        $contrat->setPdfPath($publicPath);

        $em->flush();

        $this->addFlash('success', 'Contrat signé avec succès.');

        return $this->redirectToRoute('app_formateur_contrats', [
            'entite' => $entite->getId(),
        ]);
    }



    #[Route('/contrat/{contrat}/regen-pdf', name: 'contrat_regen_pdf', methods: ['GET'])]
    public function regenContratPdf(
        Entite $entite,
        ContratFormateur $contrat,
        EntityManagerInterface $em
    ): Response {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        if ($contrat->getFormateur()?->getUtilisateur()?->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException();
        }

        $session      = $contrat->getSession();
        $formation    = $session?->getFormation();

        $inscriptions = $session?->getInscriptions() ?? new \Doctrine\Common\Collections\ArrayCollection();

        // garde uniquement les inscriptions avec un stagiaire
        $stagiaires = $inscriptions->filter(static fn($i) => $i->getStagiaire() !== null);

        // optionnel : exclure le formateur si jamais il est aussi “inscrit”
        $formateurUser = $contrat->getFormateur()?->getUtilisateur();
        if ($formateurUser) {
            $stagiaires = $stagiaires->filter(static fn($i) => $i->getStagiaire()?->getId() !== $formateurUser->getId());
        }


        $filename = sprintf(
            'contrat_formateur_%s.pdf',
            $contrat->getNumero() ?: $contrat->getId()
        );

        $projectDir = $this->getParameter('kernel.project_dir');
        $orgSigPath = $contrat->getSignatureOrganismePath() ?: $entite->getPreferences()?->getSignatureOrganismePath();

        $orgSigDataUri = null;

        if ($orgSigPath) {
            $orgSigPath = '/' . ltrim($orgSigPath, '/'); // "/uploads/..."
            $candidate = $projectDir . '/public' . $orgSigPath;

            if (is_file($candidate)) {
                $mime = mime_content_type($candidate) ?: 'image/png';
                $data = base64_encode(file_get_contents($candidate));
                $orgSigDataUri = 'data:' . $mime . ';base64,' . $data;
            }
        }

        $this->pdfManager->contratFormateur([
            'entite'       => $entite,
            'contrat'      => $contrat,
            'session'      => $session,
            'formation'    => $formation,
            'stagiaires'    => $stagiaires,
            'orgSigDataUri' => $orgSigDataUri,
        ], $filename);


        $publicPath = 'uploads/pdf/' . $filename;
        $contrat->setPdfPath($publicPath);
        $em->flush();

        $this->addFlash('success', 'PDF du contrat regénéré.');

        return $this->redirectToRoute('app_formateur_contrats', [
            'entite' => $entite->getId(),
        ]);
    }

    private function isEntiteAdmin(Entite $entite): bool
    {
        $ue = $this->utilisateurEntiteManager->getUserEntiteLink($entite);
        return $ue?->isTenantAdmin() ?? false; // TENANT_ADMIN ou TENANT_DIRIGEANT
    }
}
