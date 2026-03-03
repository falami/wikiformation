<?php

namespace App\Controller\Administrateur;

use App\Entity\{Inscription, Entite, Utilisateur, SessionJour, Session, Formateur, Site, Formation};
use App\Enum\StatusSession; // ⚠️ adapte si ton enum s'appelle autrement
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Security\Permission\TenantPermission;


#[Route('/administrateur/{entite}/planning/formateurs', name: 'app_administrateur_planning_formateurs_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::PLANNING_FORMATEUR_MANAGE, subject: 'entite')]
final class PlanningFormateursController extends AbstractController
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

    $sites = $em->getRepository(Site::class)->createQueryBuilder('s')
      ->leftJoin('s.entite', 'e')
      ->andWhere('e = :entite')->setParameter('entite', $entite)
      ->orderBy('s.nom', 'ASC')
      ->getQuery()->getResult();

    $schedulerKey = (string)($this->getParameter('fullcalendar_scheduler_key') ?? '');

    $statusSessions = array_map(static function ($st) {
      return [
        'value' => $st->value,
        'label' => method_exists($st, 'label') ? $st->label() : $st->value,
      ];
    }, StatusSession::cases());

    return $this->render('administrateur/planning/formateurs/index.html.twig', [
      'entite' => $entite,
      'utilisateurEntite' => $this->utilisateurEntiteManager->getRepository()
        ->findOneBy(['entite' => $entite, 'utilisateur' => $user]),
      'formations' => $formations,
      'sites' => $sites,
      'schedulerKey' => $schedulerKey,
      'statusSessions' => $statusSessions,
    ]);
  }

  #[Route('/data', name: 'data', methods: ['GET'])]
  public function data(Entite $entite, Request $request, EM $em): JsonResponse
  {
    $start = (string)$request->query->get('start', '');
    $end   = (string)$request->query->get('end', '');

    $formationId = $request->query->getInt('formation', 0);
    $siteId      = $request->query->getInt('site', 0);
    $statusRaw   = (string)$request->query->get('status', '');

    if (!$start || !$end) {
      return $this->json(['resources' => [], 'events' => []], 200);
    }

    try {
      $dtStart = new \DateTimeImmutable($start);
      $dtEnd   = new \DateTimeImmutable($end);
    } catch (\Throwable) {
      return $this->json(['resources' => [], 'events' => []], 400);
    }

    // Filtre statut enum (robuste)
    $statusEnum = null;
    if ($statusRaw !== '') {
      try {
        $statusEnum = StatusSession::from($statusRaw);
      } catch (\ValueError) {
        $statusEnum = null; // ignore
      }
    }

    // 1) Récupérer les jours (évite doublons)
    $qb = $em->createQueryBuilder()
      ->select('j', 's', 'f', 'si', 'en', 'sf', 'usf', 'jf', 'ujf')
      ->from(SessionJour::class, 'j')
      ->innerJoin('j.session', 's')
      ->leftJoin('s.formation', 'f')
      ->innerJoin('s.site', 'si')
      ->leftJoin('s.engin', 'en')
      ->leftJoin('s.formateur', 'sf')
      ->leftJoin('sf.utilisateur', 'usf')
      ->leftJoin('j.formateur', 'jf')
      ->leftJoin('jf.utilisateur', 'ujf')
      ->andWhere('s.entite = :entite')->setParameter('entite', $entite)
      ->andWhere('j.dateDebut < :end AND j.dateFin > :start')
      ->setParameter('start', $dtStart)
      ->setParameter('end', $dtEnd)
      ->orderBy('j.dateDebut', 'ASC');

    if ($formationId > 0) $qb->andWhere('f.id = :fid')->setParameter('fid', $formationId);
    if ($siteId > 0)      $qb->andWhere('si.id = :sid')->setParameter('sid', $siteId);
    if ($statusEnum)      $qb->andWhere('s.status = :st')->setParameter('st', $statusEnum);

    /** @var SessionJour[] $jours */
    $jours = $qb->getQuery()->getResult();

    // 2) COUNT inscriptions groupé par session (anti N+1)
    $sessionIds = [];
    foreach ($jours as $j) {
      $sid = $j->getSession()?->getId();
      if ($sid) $sessionIds[$sid] = true;
    }
    $sessionIds = array_keys($sessionIds);

    $countsBySession = [];
    if ($sessionIds) {
      $rows = $em->createQueryBuilder()
        ->select('IDENTITY(i.session) as sid, COUNT(i.id) as c')
        ->from(Inscription::class, 'i')
        ->andWhere('i.session IN (:ids)')->setParameter('ids', $sessionIds)
        ->groupBy('sid')
        ->getQuery()->getArrayResult();

      foreach ($rows as $r) {
        $countsBySession[(int)$r['sid']] = (int)$r['c'];
      }
    }

    // 3) Construire EVENTS + collecter les formateurs réellement utilisés (pour filtrer resources)
    $events = [];
    $seen = [];
    $usedFormateurIds = []; // <-- clé importante

    foreach ($jours as $jour) {
      if (!$jour instanceof SessionJour) continue;
      $session = $jour->getSession();
      if (!$session instanceof Session) continue;

      $startJour = $jour->getDateDebut();
      $endJour   = $jour->getDateFin();
      if (!$startJour || !$endJour) continue;

      $effectiveFo = $jour->getFormateur() ?? $session->getFormateur();
      if (!$effectiveFo) continue;

      $foId = (int)$effectiveFo->getId();
      $sid  = (int)$session->getId();
      $jid  = (int)$jour->getId();

      $eventId = 'f' . $foId . '-s' . $sid . '-j' . $jid;
      if (isset($seen[$eventId])) continue;
      $seen[$eventId] = true;

      $usedFormateurIds[$foId] = true;

      $formation = $session->getFormation();
      $site      = $session->getSite();
      $nbInscrits = $countsBySession[$sid] ?? 0;

      $hour = (int)$startJour->format('H');
      $slot = $hour < 12 ? 'AM' : 'PM';

      $titleFormation = $session->getFormationLabel();
      $events[] = [
        'id' => $eventId,
        'resourceId' => (string)$foId,
        'title' => (string)($titleFormation ?: 'Session'),
        'start' => $startJour->format(\DateTimeInterface::ATOM),
        'end' => $endJour->format(\DateTimeInterface::ATOM),
        'classNames' => [$slot === 'AM' ? 'ev-am' : 'ev-pm'],
        'extendedProps' => [
          'formateurId' => $foId,
          'sessionId' => $sid,
          'jourId' => $jid,
          'slot' => $slot,
          'code' => (string)($session->getCode() ?? ''),
          'formation' => (string)($titleFormation ?: ''),
          'site' => (string)($site?->getNom() ?? ''),
          'status' => $session->getStatus()?->value ?? '',
          'statusLabel' => method_exists($session->getStatus(), 'label')
            ? $session->getStatus()->label()
            : ($session->getStatus()?->value ?? ''),
          'cap' => (int)$session->getCapacite(),
          'inscrits' => (int)$nbInscrits,
          'isOverrideDay' => $jour->getFormateur() ? true : false,
        ],
      ];
    }

    // 4) Resources :
    // - si un filtre est actif, n’afficher que les formateurs qui ont au moins 1 event
    // - sinon : tu peux choisir d’afficher tout le monde OU seulement ceux avec events.
    $filterActive = ($formationId > 0) || ($siteId > 0) || ($statusRaw !== '');

    $resources = [];
    if ($filterActive && empty($usedFormateurIds)) {
      // filtre actif mais aucun event => aucune ressource
      return $this->json(['resources' => [], 'events' => []], 200);
    }

    $qbFo = $em->getRepository(Formateur::class)->createQueryBuilder('fo')
      ->innerJoin('fo.utilisateur', 'u')->addSelect('u')
      ->andWhere('fo.entite = :entite')->setParameter('entite', $entite)
      ->orderBy('u.nom', 'ASC')->addOrderBy('u.prenom', 'ASC');

    if ($filterActive) {
      $qbFo->andWhere('fo.id IN (:ids)')->setParameter('ids', array_keys($usedFormateurIds));
    }

    $formateurs = $qbFo->getQuery()->getResult();

    foreach ($formateurs as $fo) {
      if (!$fo instanceof Formateur) continue;
      $u = $fo->getUtilisateur();
      if (!$u) continue;

      $resources[] = [
        'id' => (string)$fo->getId(),
        'title' => trim((string)($u->getNom() ?? '') . ' ' . (string)($u->getPrenom() ?? '')),
        'email' => (string)($u->getEmail() ?? ''),
        'qualifs' => (string)($fo->getQualificationEngins()?->count() ?? 0),
        'sites' => (string)($fo->getSitePreferes()?->count() ?? 0),
      ];
    }

    return $this->json([
      'resources' => $resources,
      'events' => $events,
    ]);
  }


  #[Route('/event-details/{formateur}/{session}/{jour}', name: 'event_details', methods: ['GET'])]
  public function eventDetails(Entite $entite, Formateur $formateur, Session $session, SessionJour $jour, EM $em): JsonResponse
  {
    if ($session->getEntite()?->getId() !== $entite->getId()) {
      return $this->json(['ok' => false, 'error' => 'Accès refusé.'], 403);
    }
    if ($jour->getSession()?->getId() !== $session->getId()) {
      return $this->json(['ok' => false, 'error' => 'Jour invalide.'], 400);
    }

    // formateur effectif attendu pour CE créneau
    $effectiveFo = $jour->getFormateur() ?? $session->getFormateur();
    if (!$effectiveFo || $effectiveFo->getId() !== $formateur->getId()) {
      return $this->json(['ok' => false, 'error' => 'Créneau non attribué à ce formateur.'], 400);
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
    $engin = $session->getEngin();

    $ufo = $formateur->getUtilisateur();

    $start = $jour->getDateDebut();
    $end   = $jour->getDateFin();
    $dureeHeures = 0.0;
    if ($start && $end) {
      $dureeHeures = round(max(0, $end->getTimestamp() - $start->getTimestamp()) / 3600, 2);
    }

    return $this->json([
      'ok' => true,
      'formateur' => [
        'id' => (int)$formateur->getId(),
        'nom' => (string)($ufo?->getNom() ?? ''),
        'prenom' => (string)($ufo?->getPrenom() ?? ''),
        'email' => (string)($ufo?->getEmail() ?? ''),
      ],
      'dureeHeures' => $dureeHeures,
      'session' => [
        'id' => (int)$session->getId(),
        'code' => (string)($session->getCode() ?? ''),
        'status' => $session->getStatus()?->value ?? '',
        'statusLabel' => method_exists($session->getStatus(), 'label') ? $session->getStatus()->label() : ($session->getStatus()?->value ?? ''),
        'capacite' => (int)$session->getCapacite(),
        'montantCents' => (int)($session->getTarifEffectifCents() ?? 0),
        'piecesObligatoires' => $session->getPiecesObligatoires(),
        'formation' => [
          'id' => (int)($formation?->getId() ?? 0),
          'titre' => (string)$session->getFormationLabel(), // ✅
        ],

        'site' => [
          'id' => (int)($site?->getId() ?? 0),
          'nom' => (string)($site?->getNom() ?? ''),
        ],
        'engin' => $engin ? [
          'id' => (int)$engin->getId(),
          'nom' => (string)($engin->getNom() ?? ''),
        ] : null,
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
