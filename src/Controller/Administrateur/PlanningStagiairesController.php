<?php

namespace App\Controller\Administrateur;

use App\Entity\{Emargement, Entite, Utilisateur, Formation, Entreprise, Inscription, SessionJour};
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Security\Permission\TenantPermission;



#[Route('/administrateur/{entite}/planning/stagiaires', name: 'app_administrateur_planning_stagiaires_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::PLANNING_STAGIAIRES_MANAGE, subject: 'entite')]
final class PlanningStagiairesController extends AbstractController
{
  public function __construct(
    private UtilisateurEntiteManager $utilisateurEntiteManager,
  ) {}

  #[Route('/event-details/{inscription}/{jour}', name: 'event_details', methods: ['GET'])]
  public function eventDetails(Entite $entite, Inscription $inscription, SessionJour $jour, EM $em): JsonResponse
  {
    // Sécurité : l’inscription doit appartenir à l’entité
    $session = $inscription->getSession();
    $stagiaire = $inscription->getStagiaire();

    if (!$session || !$stagiaire || $session->getEntite()?->getId() !== $entite->getId()) {
      return $this->json(['ok' => false, 'error' => 'Accès refusé.'], 403);
    }

    // Le jour doit appartenir à la session
    if ($jour->getSession()?->getId() !== $session->getId()) {
      return $this->json(['ok' => false, 'error' => 'Jour invalide.'], 400);
    }

    // Date du jour (pour matcher Emargement.dateJour)
    $dateJour = $jour->getDateDebut();
    $dateKey = $dateJour ? \DateTimeImmutable::createFromFormat('Y-m-d', $dateJour->format('Y-m-d')) : null;

    // Récupérer les émargements du stagiaire pour ce jour (AM/PM)
    $emargements = [];
    if ($dateKey) {
      $rows = $em->getRepository(Emargement::class)->createQueryBuilder('e')
        ->andWhere('e.session = :s')->setParameter('s', $session)
        ->andWhere('e.utilisateur = :u')->setParameter('u', $stagiaire)
        ->andWhere('e.dateJour = :d')->setParameter('d', $dateKey, \Doctrine\DBAL\Types\Types::DATE_IMMUTABLE)
        ->orderBy('e.periode', 'ASC')
        ->getQuery()->getResult();

      /** @var Emargement $e */
      foreach ($rows as $e) {
        $emargements[] = [
          'id' => (int)$e->getId(),
          'periode' => $e->getPeriode()->value, // 'AM' / 'PM' selon ton enum
          'role' => (string)($e->getRole() ?? ''),
          'signedAt' => $e->getSignedAt()?->format(\DateTimeInterface::ATOM),
          'signaturePath' => (string)($e->getSignaturePath() ?? ''),
          'ip' => (string)($e->getIp() ?? ''),
          'userAgent' => (string)($e->getUserAgent() ?? ''),
        ];
      }
    }

    $formation = $session->getFormation();
    $site = $session->getSite();

    $entreprise = $inscription->getEntreprise()?->getRaisonSociale()
      ?? $stagiaire->getEntreprise()?->getRaisonSociale()
      ?? $stagiaire->getSociete()
      ?? null;

    // ⚠️ On évite d’appeler des getters qui n’existent pas chez toi :
    $getIf = fn(object $o, string $m) => method_exists($o, $m) ? $o->$m() : null;

    $formationLabel = (string)($session->getFormationLabel() ?? '');
    $formationLabel = trim($formationLabel);

    return $this->json([
      'ok' => true,
      'inscription' => [
        'id' => (int)$inscription->getId(),
        'status' => $inscription->getStatus()?->value,
        'statusLabel' => $inscription->getStatus()?->label(), // ✅ si tu veux afficher “Préinscrit”
        'modeFinancement' => $inscription->getModeFinancement()?->value,

        'reussi' => (bool)$inscription->isReussi(),
        'tauxAssiduite' => $inscription->getTauxAssiduite(),
        'entreprise' => $entreprise,
      ],
      'stagiaire' => [
        'id' => (int)$stagiaire->getId(),
        'nom' => (string)($getIf($stagiaire, 'getNom') ?? ''),
        'prenom' => (string)($getIf($stagiaire, 'getPrenom') ?? ''),
        'email' => (string)($getIf($stagiaire, 'getEmail') ?? ''),
        'telephone' => (string)($getIf($stagiaire, 'getTelephone') ?? $getIf($stagiaire, 'getPhone') ?? ''),
      ],
      'session' => [
        'id' => (int)$session->getId(),
        'code' => (string)($getIf($session, 'getCode') ?? ''),
        'formation' => [
          'id' => (int)($formation?->getId() ?? 0),
          'titre' => (string)($formation?->getTitre() ?? ''),
        ],
        'formationLabel' => $formationLabel, // ✅ AJOUT ICI
        'site' => (string)($site?->getNom() ?? ''),
      ],
      'jour' => [
        'id' => (int)$jour->getId(),
        'start' => $jour->getDateDebut()?->format(\DateTimeInterface::ATOM),
        'end' => $jour->getDateFin()?->format(\DateTimeInterface::ATOM),
        'date' => $jour->getDateDebut()?->format('Y-m-d'),
      ],
      'emargements' => $emargements,
    ]);
  }


  #[Route('', name: 'index', methods: ['GET'])]
  public function index(Entite $entite, EM $em): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    // Listes filtres (entité courante)
    $formations = $em->getRepository(Formation::class)->createQueryBuilder('f')
      ->andWhere('f.entite = :entite')->setParameter('entite', $entite)
      ->orderBy('f.titre', 'ASC')
      ->getQuery()->getResult();

    $entreprises = $em->getRepository(Entreprise::class)->createQueryBuilder('e')
      ->andWhere('e.entite = :entite')->setParameter('entite', $entite)
      ->orderBy('e.raisonSociale', 'ASC')
      ->getQuery()->getResult();

    // Optionnel : clé scheduler (si non définie, on renvoie '')
    $schedulerKey = (string)($this->getParameter('fullcalendar_scheduler_key') ?? '');

    return $this->render('administrateur/planning/stagiaires/index.html.twig', [
      'entite' => $entite,


      'formations' => $formations,
      'entreprises' => $entreprises,
      'schedulerKey' => $schedulerKey,
    ]);
  }

  #[Route('/data', name: 'data', methods: ['GET'])]
  public function data(Entite $entite, Request $request, EM $em): JsonResponse
  {
    $start = (string)$request->query->get('start', '');
    $end   = (string)$request->query->get('end', '');

    $formationId  = $request->query->getInt('formation', 0);
    $entrepriseId = $request->query->getInt('entreprise', 0);

    if (!$start || !$end) {
      return $this->json(['resources' => [], 'events' => []], 200);
    }

    try {
      $dtStart = new \DateTimeImmutable($start);
      $dtEnd   = new \DateTimeImmutable($end);
    } catch (\Throwable) {
      return $this->json(['resources' => [], 'events' => []], 400);
    }

    // ✅ Fetch-join pour avoir session/formation/stagiaire/jours chargés
    $qb = $em->createQueryBuilder()
      ->select('i', 's', 'f', 'u', 'e', 'ue', 'j')
      ->from(Inscription::class, 'i')
      ->innerJoin('i.session', 's')
      ->leftJoin('s.formation', 'f')
      ->innerJoin('i.stagiaire', 'u')
      ->leftJoin('i.entreprise', 'e')        // entreprise sur l'inscription
      ->leftJoin('u.entreprise', 'ue')       // ✅ entreprise sur le stagiaire (Utilisateur::entreprise)
      ->innerJoin('s.jours', 'j')
      ->andWhere('s.entite = :entite')->setParameter('entite', $entite)
      ->andWhere('j.dateDebut < :end AND j.dateFin > :start')
      ->setParameter('start', $dtStart)
      ->setParameter('end', $dtEnd)
      ->distinct()
      ->orderBy('u.nom', 'ASC')
      ->addOrderBy('u.prenom', 'ASC')
      ->addOrderBy('j.dateDebut', 'ASC');

    if ($formationId > 0) {
      $qb->andWhere('f.id = :fid')->setParameter('fid', $formationId);
    }

    if ($entrepriseId > 0) {
      // ✅ filtre sur entreprise inscription OU entreprise stagiaire
      $qb->andWhere('(e.id = :eid OR ue.id = :eid)')
        ->setParameter('eid', $entrepriseId);
    }


    /** @var Inscription[] $inscriptions */
    $inscriptions = $qb->getQuery()->getResult();

    $resources = [];
    $events = [];
    $seenUsers = [];
    $seenEvents = [];

    foreach ($inscriptions as $inscription) {
      if (!$inscription instanceof Inscription) continue;

      $session   = $inscription->getSession();
      $stagiaire = $inscription->getStagiaire();
      if (!$session || !$stagiaire) continue;

      // ⚠️ ton entite sur Session est nullable : si tu oublies de la remplir en base => ça filtre tout
      // mais comme tu dis que ça marchait avant, on laisse le filtre.
      $formationLabel = $session->getFormationLabel(); // ✅ OF => intitulé libre, sinon titre formation


      $uid = (int)$stagiaire->getId();
      if ($uid <= 0) continue;

      // -------- Resource (1 fois par stagiaire)
      if (!isset($seenUsers[$uid])) {
        $seenUsers[$uid] = true;

        $rsTitle = trim(($stagiaire->getNom() ?? '') . ' ' . ($stagiaire->getPrenom() ?? ''));
        if ($rsTitle === '') $rsTitle = 'Stagiaire #' . $uid;

        $soc = $stagiaire->getEntreprise()?->getRaisonSociale()
          ?? $inscription->getEntreprise()?->getRaisonSociale()
          ?? $stagiaire->getSociete();


        $resources[] = [
          'id'      => (string)$uid,
          'title'   => $rsTitle,
          'societe' => $soc ? (string)$soc : '—',
        ];
      }

      // -------- Events (1 par SessionJour)
      foreach ($session->getJours() as $jour) {
        if (!$jour instanceof SessionJour) continue;

        $startJour = $jour->getDateDebut();
        $endJour   = $jour->getDateFin();
        if (!$startJour || !$endJour) continue;

        // sécurité : on refiltre côté PHP (même si le WHERE SQL le fait déjà)
        if ($startJour >= $dtEnd || $endJour <= $dtStart) {
          continue;
        }

        $hour = (int)$startJour->format('H');
        $slot = $hour < 12 ? 'AM' : 'PM';

        $eventId = 'insc-' . $inscription->getId() . '-j' . $jour->getId();
        if (isset($seenEvents[$eventId])) continue;
        $seenEvents[$eventId] = true;

        $formationLabel = $session->getFormationLabel();

        $evTitle = trim(
          ($formationLabel !== '' ? $formationLabel : 'Formation') . ' — ' .
            ($session->getCode() ?? ('Session #' . $session->getId()))
        );



        $socEv = $stagiaire->getEntreprise()?->getRaisonSociale()
          ?? $inscription->getEntreprise()?->getRaisonSociale()
          ?? $stagiaire->getSociete()
          ?? '';


        $formationLabel = trim((string)($session->getFormationLabel() ?? ''));


        $events[] = [
          'id'         => $eventId,
          'resourceId' => (string)$uid,
          'title'      => $evTitle,
          'start'      => $startJour->format(\DateTimeInterface::ATOM),
          'end'        => $endJour->format(\DateTimeInterface::ATOM),
          'classNames' => [$slot === 'AM' ? 'ev-am' : 'ev-pm'],
          'extendedProps' => [
            'slot'           => $slot,
            'formation'      => $formationLabel,     // garde si tu veux
            'formationLabel' => $formationLabel,     // ✅ AJOUT IMPORTANT
            'sessionCode'    => (string)($session->getCode() ?? ''),
            'site'           => (string)($session->getSite()?->getNom() ?? ''),
            'entreprise'     => (string)$socEv,
            'inscriptionId'  => (int)$inscription->getId(),
            'sessionId'      => (int)$session->getId(),
            'stagiaireId'    => (int)$stagiaire->getId(),
            'jourId'         => (int)$jour->getId(),
            'dateJour'       => $startJour->format('Y-m-d'),
          ],

        ];
      }
    }

    return $this->json([
      'resources' => $resources,
      'events'    => $events,
    ]);
  }
}
