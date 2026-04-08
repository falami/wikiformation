<?php

declare(strict_types=1);

namespace App\Controller\OrganismeFormation;

use App\Entity\{
    Entite,
    Utilisateur,
    Entreprise,
    Session,
    Inscription,
    SessionPiece,
    Formation
};
use App\Form\Administrateur\SessionPieceUploadType;
use App\Security\Permission\TenantPermission;
use App\Service\FileUploader;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/of/{entite}', name: 'app_of_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::DASHBOARD_OF_MANAGE, subject: 'entite')]
final class DashboardOrganismeFormationController extends AbstractController
{
    public function __construct(
        private FileUploader $uploader,
        private UtilisateurEntiteManager $utilisateurEntiteManager,
    ) {
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

    private function getOrganismeFormationUserOrFail(): Entreprise
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $of = $user->getEntreprise();
        if (!$of) {
            throw $this->createAccessDeniedException('Aucun organisme de formation associé à ce compte.');
        }

        return $of;
    }

    #[Route('/dashboard', name: 'dashboard', methods: ['GET', 'POST'])]
    public function dashboard(Entite $entite, EM $em, Request $request): Response
    {
        $of = $this->getOrganismeFormationUserOrFail();

        $piece = new SessionPiece();

        $formUpload = $this->createForm(SessionPieceUploadType::class, $piece, [
            'action' => $this->generateUrl('app_of_documents_upload', [
                'entite' => $entite->getId(),
            ]),
        ]);

        $formUpload->handleRequest($request);

        return $this->render('of/dashboard.html.twig', [
            'entite' => $entite,
            'organismeFormation' => $of,
            'formUpload' => $formUpload->createView(),
        ]);
    }

    #[Route('/inscriptions/ajax', name: 'inscriptions_ajax', methods: ['POST'])]
    public function inscriptionsAjax(Entite $entite, EM $em, Request $request): JsonResponse
    {
        $of = $this->getOrganismeFormationUserOrFail();

        $rows = $em->createQueryBuilder()
            ->select('i, s, fo, u')
            ->from(Inscription::class, 'i')
            ->join('i.session', 's')
            ->leftJoin('s.formation', 'fo')
            ->join('i.stagiaire', 'u')
            ->andWhere('s.entite = :entite')->setParameter('entite', $entite)
            ->andWhere('s.organismeFormation = :of')->setParameter('of', $of)
            ->addOrderBy('u.nom', 'ASC')
            ->getQuery()
            ->getResult();

        $data = [];
        foreach ($rows as $i) {
            /** @var Inscription $i */
            $s = $i->getSession();
            $u = $i->getStagiaire();

            $first = $this->firstStart($s);
            $last  = $this->lastEnd($s);

            $statusBadge = '<span class="badge bg-secondary-subtle text-secondary">—</span>';
            if ($first && $last) {
                $now = new \DateTimeImmutable();
                if ($now < $first) {
                    $statusBadge = '<span class="badge bg-info text-dark">À venir</span>';
                } elseif ($now >= $first && $now <= $last) {
                    $statusBadge = '<span class="badge bg-success">En cours</span>';
                } else {
                    $statusBadge = '<span class="badge bg-secondary">Terminée</span>';
                }
            }

            $data[] = [
                'stagiaire' => trim(($u->getPrenom() ?? '') . ' ' . ($u->getNom() ?? '')) ?: ('#' . $u->getId()),
                'formation' => $s->getFormation()?->getTitre() ?? $s->getFormationIntituleLibre() ?? '—',
                'code' => $s->getCode(),
                'dates' => ($first && $last)
                    ? ($first->format('d/m/Y H:i') . ' → ' . $last->format('d/m/Y H:i'))
                    : '<span class="text-muted">—</span>',
                'statut' => $statusBadge,
                'actions' => sprintf(
                    '<div class="d-flex gap-2 justify-content-end">
                        <button class="btn btn-sm btn-outline-primary js-open-session-detail" data-session="%d">
                            <i class="bi bi-eye"></i> Détail
                        </button>
                        <button class="btn btn-sm btn-outline-secondary js-open-docs" data-session="%d">
                            <i class="bi bi-folder2-open"></i> Docs
                        </button>
                    </div>',
                    (int) $s->getId(),
                    (int) $s->getId()
                ),
                'firstStartIso' => $first?->format(\DateTimeInterface::ATOM),
                'lastEndIso' => $last?->format(\DateTimeInterface::ATOM),
                'sessionId' => (int) $s->getId(),
                'inscriptionId' => (int) $i->getId(),
            ];
        }

        return new JsonResponse([
            'data' => $data,
            'recordsTotal' => count($data),
            'recordsFiltered' => count($data),
            'draw' => (int) ($request->request->get('draw') ?? 1),
        ]);
    }

    #[Route('/calendar/feed', name: 'calendar_feed', methods: ['GET'])]
    public function calendarFeed(Entite $entite, EM $em): JsonResponse
    {
        $of = $this->getOrganismeFormationUserOrFail();

        $sessions = $em->createQueryBuilder()
            ->select('DISTINCT s, j, fo')
            ->from(Session::class, 's')
            ->join('s.jours', 'j')
            ->leftJoin('s.formation', 'fo')
            ->andWhere('s.entite = :entite')->setParameter('entite', $entite)
            ->andWhere('s.organismeFormation = :of')->setParameter('of', $of)
            ->addOrderBy('j.dateDebut', 'ASC')
            ->getQuery()
            ->getResult();

        $events = [];
        foreach ($sessions as $s) {
            /** @var Session $s */
            foreach ($s->getJours() as $j) {
                $events[] = [
                    'id' => $s->getId(),
                    'title' => trim(($s->getFormation()?->getTitre() ?? $s->getFormationIntituleLibre() ?? 'Session') . ' - ' . $s->getCode()),
                    'start' => $j->getDateDebut()->format('c'),
                    'end' => $j->getDateFin()->format('c'),
                    'extendedProps' => [
                        'sessionId' => $s->getId(),
                        'jourId' => $j->getId(),
                    ],
                ];
            }
        }

        return new JsonResponse($events);
    }

    #[Route('/session/{session}/detail', name: 'session_detail_ajax', methods: ['GET'])]
    public function sessionDetailAjax(Entite $entite, Session $session, EM $em): JsonResponse
    {
        $of = $this->getOrganismeFormationUserOrFail();

        if ($session->getEntite()?->getId() !== $entite->getId()) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Session introuvable.'
            ], 404);
        }

        if ($session->getOrganismeFormation()?->getId() !== $of->getId()) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Accès refusé à cette session.'
            ], 403);
        }

        $inscriptions = $em->createQueryBuilder()
            ->select('i, u')
            ->from(Inscription::class, 'i')
            ->join('i.stagiaire', 'u')
            ->andWhere('i.session = :session')->setParameter('session', $session)
            ->orderBy('u.nom', 'ASC')
            ->addOrderBy('u.prenom', 'ASC')
            ->getQuery()
            ->getResult();

        $participants = [];
        $presentCount = 0;
        $absentCount  = 0;

        foreach ($inscriptions as $inscription) {
            /** @var Inscription $inscription */
            $stagiaire = $inscription->getStagiaire();
            $status = $inscription->getStatus();

            $statusValue = method_exists($status, 'value') ? $status->value : (string) $status;
            $isAbsent = in_array($statusValue, ['ABSENT', 'absent'], true);
            $isPresent = in_array($statusValue, ['CONFIRME', 'EN_COURS', 'TERMINE', 'confirme', 'en_cours', 'termine'], true);

            if ($isPresent) {
                $presentCount++;
            }
            if ($isAbsent) {
                $absentCount++;
            }

            $participants[] = [
                'id' => $stagiaire?->getId(),
                'nom' => trim(($stagiaire?->getPrenom() ?? '') . ' ' . ($stagiaire?->getNom() ?? '')),
                'email' => $stagiaire?->getEmail(),
                'telephone' => $stagiaire?->getTelephone(),
                'statut' => method_exists($status, 'label') ? $status->label() : $statusValue,
                'present' => $isPresent,
                'absent' => $isAbsent,
                'montantDu' => $inscription->getMontantDuCents() !== null
                    ? number_format($inscription->getMontantDuCents() / 100, 2, ',', ' ') . ' €'
                    : null,
                'montantRegle' => $inscription->getMontantRegleCents() !== null
                    ? number_format($inscription->getMontantRegleCents() / 100, 2, ',', ' ') . ' €'
                    : null,
                'assiduite' => $inscription->getTauxAssiduite(),
                'reussi' => $inscription->isReussi(),
            ];
        }

        $jours = [];
        foreach ($session->getJours() as $jour) {
            $jourFormateur = $jour->getFormateur()?->getUtilisateur();

            $jours[] = [
                'id' => $jour->getId(),
                'dateDebutIso' => $jour->getDateDebut()?->format(\DateTimeInterface::ATOM),
                'dateFinIso'   => $jour->getDateFin()?->format(\DateTimeInterface::ATOM),
                'dateLabel'    => $jour->getDateDebut()?->format('d/m/Y'),
                'heureDebut'   => $jour->getDateDebut()?->format('H:i'),
                'heureFin'     => $jour->getDateFin()?->format('H:i'),
                'formateur'    => $jourFormateur
                    ? trim(($jourFormateur->getPrenom() ?? '') . ' ' . ($jourFormateur->getNom() ?? ''))
                    : null,
            ];
        }

        usort($jours, static fn(array $a, array $b) => strcmp($a['dateDebutIso'] ?? '', $b['dateDebutIso'] ?? ''));

        $site = $session->getSite();
        $formateur = $session->getFormateur()?->getUtilisateur();

        $adresse = null;
        $mapQuery = null;

        if ($site) {
            $adresseParts = array_filter([
                method_exists($site, 'getAdresse') ? $site->getAdresse() : null,
                method_exists($site, 'getComplement') ? $site->getComplement() : null,
                trim(((method_exists($site, 'getCodePostal') ? $site->getCodePostal() : '') ?? '') . ' ' . ((method_exists($site, 'getVille') ? $site->getVille() : '') ?? '')),
                method_exists($site, 'getPays') ? $site->getPays() : null,
            ]);

            $adresse = implode(', ', $adresseParts);

            if (method_exists($site, 'getLatitude') && method_exists($site, 'getLongitude') && $site->getLatitude() !== null && $site->getLongitude() !== null) {
                $mapQuery = $site->getLatitude() . ',' . $site->getLongitude();
            } elseif ($adresse !== '') {
                $mapQuery = $adresse;
            }
        }

        $sessionMontant = $session->getMontantCents();

        return new JsonResponse([
            'success' => true,
            'session' => [
                'id' => $session->getId(),
                'code' => $session->getCode(),
                'formation' => $session->getFormation()?->getTitre() ?? $session->getFormationIntituleLibre() ?? '—',
                'statut' => method_exists($session->getStatus(), 'label')
                    ? $session->getStatus()->label()
                    : (method_exists($session->getStatus(), 'value') ? $session->getStatus()->value : '—'),
                'capacite' => $session->getCapacite(),
                'montant' => $sessionMontant !== null
                    ? number_format($sessionMontant / 100, 2, ',', ' ') . ' €'
                    : '—',
                'siteNom' => method_exists($site, 'getNom') ? $site?->getNom() : null,
                'adresse' => $adresse,
                'latitude' => method_exists($site, 'getLatitude') ? $site?->getLatitude() : null,
                'longitude' => method_exists($site, 'getLongitude') ? $site?->getLongitude() : null,
                'mapQuery' => $mapQuery,
                'formateur' => $formateur
                    ? trim(($formateur->getPrenom() ?? '') . ' ' . ($formateur->getNom() ?? ''))
                    : null,
                'jours' => $jours,
                'participants' => $participants,
                'participantsCount' => count($participants),
                'presentCount' => $presentCount,
                'absentCount' => $absentCount,
            ],
        ]);
    }

    #[Route('/formations', name: 'formations', methods: ['GET'])]
    #[IsGranted(TenantPermission::OF_FORMATIONS_MANAGE, subject: 'entite')]
    public function formations(Entite $entite): Response
    {
        $of = $this->getOrganismeFormationUserOrFail();

        return $this->render('of/formations.html.twig', [
            'entite' => $entite,
            'organismeFormation' => $of,
        ]);
    }

    #[Route('/formations/ajax', name: 'formations_ajax', methods: ['POST'])]
    #[IsGranted(TenantPermission::OF_FORMATIONS_MANAGE, subject: 'entite')]
    public function formationsAjax(Entite $entite, EM $em, Request $request): JsonResponse
    {
        $of = $this->getOrganismeFormationUserOrFail();

        $formations = $em->createQueryBuilder()
            ->select('f, s')
            ->from(Formation::class, 'f')
            ->leftJoin('f.sessions', 's')
            ->andWhere('f.entite = :entite')->setParameter('entite', $entite)
            ->getQuery()
            ->getResult();

        $data = [];

        foreach ($formations as $formation) {
            /** @var Formation $formation */
            $sessionsOf = array_filter(
                $formation->getSessions()->toArray(),
                static fn(Session $s) => $s->getOrganismeFormation()?->getId() === $of->getId()
            );

            $inscriptionsCount = 0;
            $nextDate = null;

            foreach ($sessionsOf as $session) {
                $inscriptionsCount += $session->getInscriptions()->count();

                foreach ($session->getJours() as $jour) {
                    $d = $jour->getDateDebut();
                    if ($d && ($nextDate === null || $d < $nextDate)) {
                        $nextDate = $d;
                    }
                }
            }

            $data[] = [
                'titre' => $formation->getTitre(),
                'slug' => method_exists($formation, 'getSlug') ? ($formation->getSlug() ?: '—') : '—',
                'sessions' => count($sessionsOf),
                'inscriptions' => $inscriptionsCount,
                'prochaine' => $nextDate?->format('d/m/Y H:i') ?? '—',
                'actions' => '<div class="d-flex gap-2 justify-content-end"><button class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></button></div>',
            ];
        }

        return new JsonResponse([
            'data' => $data,
            'recordsTotal' => count($data),
            'recordsFiltered' => count($data),
            'draw' => (int) ($request->request->get('draw') ?? 1),
        ]);
    }
}