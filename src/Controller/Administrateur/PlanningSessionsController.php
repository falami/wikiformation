<?php

namespace App\Controller\Administrateur;

use App\Entity\{Inscription, Entite, Utilisateur, Formation, Site, Formateur, Session, SessionJour};
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Security\Permission\TenantPermission;
use App\Enum\PieceType;




#[Route('/administrateur/{entite}/planning/sessions', name: 'app_administrateur_planning_sessions_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::PLANNING_SESSION_MANAGE, subject: 'entite')]
final class PlanningSessionsController extends AbstractController
{
  public function __construct(
    private UtilisateurEntiteManager $utilisateurEntiteManager,
  ) {}

  #[Route('', name: 'index', methods: ['GET'])]
  public function index(Entite $entite, EM $em): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    $formations = $em->getRepository(Formation::class)->createQueryBuilder('f')
      ->andWhere('f.entite = :entite')->setParameter('entite', $entite)
      ->orderBy('f.titre', 'ASC')
      ->getQuery()->getResult();

    // Si tes sites sont liés à Entite, garde ça. Sinon enlève le where.
    $sites = $em->getRepository(Site::class)->createQueryBuilder('s')
      ->leftJoin('s.entite', 'e')
      ->andWhere('e = :entite')->setParameter('entite', $entite)
      ->orderBy('s.nom', 'ASC')
      ->getQuery()->getResult();

    // ✅ Formateurs : tri via Utilisateur (nom/prenom)
    $formateurs = $em->getRepository(Formateur::class)->createQueryBuilder('fo')
      ->innerJoin('fo.utilisateur', 'u')->addSelect('u')
      ->andWhere('fo.entite = :entite')->setParameter('entite', $entite)
      ->orderBy('u.nom', 'ASC')
      ->addOrderBy('u.prenom', 'ASC')
      ->getQuery()->getResult();

    $schedulerKey = (string)($this->getParameter('fullcalendar_scheduler_key') ?? '');

    return $this->render('administrateur/planning/sessions/index.html.twig', [
      'entite' => $entite,


      'formations' => $formations,
      'sites' => $sites,
      'formateurs' => $formateurs,
      'schedulerKey' => $schedulerKey,
    ]);
  }

  #[Route('/data', name: 'data', methods: ['GET'])]
  public function data(Entite $entite, Request $request, EM $em): JsonResponse
  {
    $start = (string)$request->query->get('start', '');
    $end   = (string)$request->query->get('end', '');

    $formationId = $request->query->getInt('formation', 0);
    $siteId      = $request->query->getInt('site', 0);
    $formateurId = $request->query->getInt('formateur', 0);
    $status      = (string)$request->query->get('status', ''); // enum value string

    if (!$start || !$end) {
      return $this->json(['resources' => [], 'events' => []], 200);
    }

    try {
      $dtStart = new \DateTimeImmutable($start);
      $dtEnd   = new \DateTimeImmutable($end);
    } catch (\Throwable) {
      return $this->json(['resources' => [], 'events' => []], 400);
    }

    $qb = $em->createQueryBuilder()
      ->select('s', 'f', 'si', 'fo', 'ufo', 'en', 'j')
      ->from(Session::class, 's')
      ->leftJoin('s.formation', 'f')        // ✅ formation optionnelle
      ->innerJoin('s.site', 'si')
      ->leftJoin('s.formateur', 'fo')
      ->leftJoin('fo.utilisateur', 'ufo')
      ->leftJoin('s.engin', 'en')
      ->innerJoin('s.jours', 'j')
      ->andWhere('s.entite = :entite')->setParameter('entite', $entite)
      ->andWhere('j.dateDebut < :end AND j.dateFin > :start')
      ->setParameter('start', $dtStart)
      ->setParameter('end', $dtEnd)
      ->distinct();


    // Filtres
    if ($formationId > 0) {
      // ⚠️ formationId ne peut filtrer que les sessions qui ont une formation
      $qb->andWhere('f.id = :fid')->setParameter('fid', $formationId);
    }

    if ($siteId > 0)      $qb->andWhere('si.id = :sid')->setParameter('sid', $siteId);
    if ($formateurId > 0) $qb->andWhere('fo.id = :foid')->setParameter('foid', $formateurId);
    if ($status !== '')   $qb->andWhere('s.status = :st')->setParameter('st', $status);

    /** @var Session[] $sessions */
    $sessions = $qb->getQuery()->getResult();

    // COUNT inscriptions groupé (anti N+1)
    $ids = array_values(array_filter(array_map(fn($s) => $s?->getId(), $sessions)));
    $countsBySession = [];
    if ($ids) {
      $rows = $em->createQueryBuilder()
        ->select('IDENTITY(i.session) as sid, COUNT(i.id) as c')
        ->from(Inscription::class, 'i')
        ->andWhere('i.session IN (:ids)')->setParameter('ids', $ids)
        ->groupBy('sid')
        ->getQuery()->getArrayResult();

      foreach ($rows as $r) {
        $countsBySession[(int)$r['sid']] = (int)$r['c'];
      }
    }

    $resources = [];
    $events = [];
    $seenSessions = [];
    $seenEvents = [];

    foreach ($sessions as $session) {
      if (!$session instanceof Session) continue;

      $sid = (int)$session->getId();
      if ($sid <= 0) continue;

      $formation = $session->getFormation();
      $site      = $session->getSite();

      $dateDebut = $session->getDateDebut();
      $dateFin   = $session->getDateFin();

      $nbInscrits = $countsBySession[$sid] ?? 0;

      if (!isset($seenSessions[$sid])) {
        $seenSessions[$sid] = true;

        $code = $session->getCode() ?? ('Session #' . $sid);

        $resources[] = [
          'id' => (string)$sid,
          'title' => (string)$code,
          'formation' => (string)$session->getFormationLabel(),
          'site' => (string)($site?->getNom() ?? '—'),
          'status' => $session->getStatus()?->value ?? '',
          'statusLabel' => method_exists($session->getStatus(), 'label') ? $session->getStatus()->label() : ($session->getStatus()?->value ?? ''),
          'cap' => (int)$session->getCapacite(),
          'inscrits' => (int)$nbInscrits,
          'range' => ($dateDebut && $dateFin)
            ? ($dateDebut->format('d/m') . ' → ' . $dateFin->format('d/m'))
            : '—',
        ];
      }

      foreach ($session->getJours() as $jour) {
        if (!$jour instanceof SessionJour) continue;

        $startJour = $jour->getDateDebut();
        $endJour   = $jour->getDateFin();
        if (!$startJour || !$endJour) continue;

        if ($startJour >= $dtEnd || $endJour <= $dtStart) continue;

        $eventId = 's' . $sid . '-j' . (int)$jour->getId();
        if (isset($seenEvents[$eventId])) continue;
        $seenEvents[$eventId] = true;

        $hour = (int)$startJour->format('H');
        $slot = $hour < 12 ? 'AM' : 'PM';

        $events[] = [
          'id' => $eventId,
          'resourceId' => (string)$sid,
          'formation' => (string)$session->getFormationLabel(),
          'start' => $startJour->format(\DateTimeInterface::ATOM),
          'end' => $endJour->format(\DateTimeInterface::ATOM),
          'classNames' => [$slot === 'AM' ? 'ev-am' : 'ev-pm'],
          'extendedProps' => [
            'sessionId' => $sid,
            'jourId' => (int)$jour->getId(),
            'slot' => $slot,
            'code' => (string)($session->getCode() ?? ''),
            'formation' => (string)($formation?->getTitre() ?? ''),
            'site' => (string)($site?->getNom() ?? ''),
            'status' => $session->getStatus()?->value ?? '',
            'statusLabel' => method_exists($session->getStatus(), 'label') ? $session->getStatus()->label() : ($session->getStatus()?->value ?? ''),
            'cap' => (int)$session->getCapacite(),
            'inscrits' => (int)$nbInscrits,
          ],
        ];
      }
    }

    return $this->json([
      'resources' => $resources,
      'events' => $events,
    ]);
  }

  #[Route('/event-details/{session}/{jour}', name: 'event_details', methods: ['GET'])]
  public function eventDetails(Entite $entite, Session $session, SessionJour $jour, EM $em): JsonResponse
  {
    if ($session->getEntite()?->getId() !== $entite->getId()) {
      return $this->json(['ok' => false, 'error' => 'Accès refusé.'], 403);
    }
    if ($jour->getSession()?->getId() !== $session->getId()) {
      return $this->json(['ok' => false, 'error' => 'Jour invalide.'], 400);
    }

    $inscriptions = $em->getRepository(Inscription::class)->createQueryBuilder('i')
      ->innerJoin('i.stagiaire', 'u')->addSelect('u')
      ->andWhere('i.session = :s')->setParameter('s', $session)
      ->orderBy('u.nom', 'ASC')->addOrderBy('u.prenom', 'ASC')
      ->getQuery()->getResult();

    $stagiaires = [];
    foreach ($inscriptions as $i) {
      /** @var Inscription $i */
      $u = $i->getStagiaire();
      if (!$u) continue;

      $entreprise = $i->getEntreprise()?->getRaisonSociale()
        ?? (method_exists($u, 'getEntreprise') ? $u->getEntreprise()?->getRaisonSociale() : null)
        ?? (method_exists($u, 'getSociete') ? $u->getSociete() : null);

      $stagiaires[] = [
        'id' => (int)$u->getId(),
        'nom' => (string)($u->getNom() ?? ''),
        'prenom' => (string)($u->getPrenom() ?? ''),
        'email' => (string)($u->getEmail() ?? ''),
        'entreprise' => $entreprise ? (string)$entreprise : '—',
        'status' => $i->getStatus()?->value,
        'statusLabel' => $i->getStatus()?->label(),
        'modeFinancement' => $i->getModeFinancement()?->value,
      ];
    }

    $formation = $session->getFormation();
    $site = $session->getSite();
    $formateur = $session->getFormateur();
    $engin = $session->getEngin();

    // ✅ nom/prenom du formateur via Utilisateur
    $ufo = $formateur?->getUtilisateur();

    return $this->json([
      'ok' => true,
      'session' => [
        'id' => (int)$session->getId(),
        'code' => (string)($session->getCode() ?? ''),
        'status' => $session->getStatus()?->value ?? '',
        'statusLabel' => method_exists($session->getStatus(), 'label') ? $session->getStatus()->label() : ($session->getStatus()?->value ?? ''),
        'capacite' => (int)$session->getCapacite(),
        'montantCents' => (int)($session->getTarifEffectifCents() ?? 0),
        'piecesObligatoires' => array_values(array_filter(array_map(
          static function ($p) {
            // si ta propriété contient déjà des PieceType
            if ($p instanceof PieceType) {
              return $p->label();
            }

            // si c'est une string (ex: "cni")
            $p = is_string($p) ? trim($p) : null;
            if (!$p) return null;

            $e = PieceType::tryFrom($p);
            return $e?->label();
          },
          $session->getPiecesObligatoires() ?? []
        ))),


        'formation' => [
          'id' => (int)($formation?->getId() ?? 0),
          'titre' => (string)$session->getFormationLabel(), // ✅ titre OU intitulé libre
        ],

        'site' => [
          'id' => (int)($site?->getId() ?? 0),
          'nom' => (string)($site?->getNom() ?? ''),
        ],
        'formateur' => $formateur ? [
          'id' => (int)$formateur->getId(),
          'nom' => (string)($ufo?->getNom() ?? ''),
          'prenom' => (string)($ufo?->getPrenom() ?? ''),
        ] : null,
        'engin' => $engin ? [
          'id' => (int)$engin->getId(),
          'nom' => (string)($engin->getNom() ?? ''),
        ] : null,
        // ✅ Équipements / logistique (si tes getters existent)
        'equipements' => [
          'equipOrdinateurFormateur' => method_exists($session, 'isEquipOrdinateurFormateur') ? (bool)$session->isEquipOrdinateurFormateur() : null,
          'equipVideoprojecteurEcran' => method_exists($session, 'isEquipVideoprojecteurEcran') ? (bool)$session->isEquipVideoprojecteurEcran() : null,
          'equipInternetStable' => method_exists($session, 'isEquipInternetStable') ? (bool)$session->isEquipInternetStable() : null,
          'equipTableauPaperboard' => method_exists($session, 'isEquipTableauPaperboard') ? (bool)$session->isEquipTableauPaperboard() : null,
          'equipMarqueursSupportsImprimes' => method_exists($session, 'isEquipMarqueursSupportsImprimes') ? (bool)$session->isEquipMarqueursSupportsImprimes() : null,

          'salleAdapteeTailleGroupe' => method_exists($session, 'isSalleAdapteeTailleGroupe') ? (bool)$session->isSalleAdapteeTailleGroupe() : null,
          'salleTablesChaisesErgo' => method_exists($session, 'isSalleTablesChaisesErgo') ? (bool)$session->isSalleTablesChaisesErgo() : null,
          'salleLumiereChauffageClim' => method_exists($session, 'isSalleLumiereChauffageClim') ? (bool)$session->isSalleLumiereChauffageClim() : null,
          'salleEauCafe' => method_exists($session, 'isSalleEauCafe') ? (bool)$session->isSalleEauCafe() : null,
        ],
      ],
      'jour' => [
        'id' => (int)$jour->getId(),
        'start' => $jour->getDateDebut()?->format(\DateTimeInterface::ATOM),
        'end' => $jour->getDateFin()?->format(\DateTimeInterface::ATOM),
        'date' => $jour->getDateDebut()?->format('Y-m-d'),
      ],
      'stats' => [
        'inscrits' => count($stagiaires),
        'placesRestantes' => max(0, $session->getCapacite() - count($stagiaires)),
      ],
      'stagiaires' => $stagiaires,


    ]);
  }
}
