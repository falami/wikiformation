<?php

namespace App\Controller\Administrateur;

use App\Entity\{ProspectInteraction, Entite, Utilisateur, Devis, Prospect, Entreprise};
use App\Enum\DevisStatus;
use App\Enum\ProspectStatus;
use App\Form\Administrateur\ProspectType;
use App\Form\Administrateur\ProspectInteractionType;
use App\Repository\ProspectRepository;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\HttpFoundation\RedirectResponse;
use App\Service\Prospect\ProspectConverter;
use App\Security\Permission\TenantPermission;
use App\Service\Entreprise\EntrepriseResolver;


#[Route('/administrateur/{entite}/prospection', name: 'app_administrateur_prospect_')]
#[IsGranted(TenantPermission::PROSPECT_MANAGE, subject: 'entite')]
final class ProspectController extends AbstractController
{



  public function __construct(
    private EM $em,
    private ProspectRepository $prospects,
    private ProspectConverter $prospectConverter,
    private EntrepriseResolver $entrepriseResolver,
  ) {}

  #[Route('', name: 'index', methods: ['GET'])]
  public function index(Entite $entite): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();
    return $this->render('administrateur/prospects/index.html.twig', [
      'entite' => $entite,

    ]);
  }

  #[Route('/kpis', name: 'kpis', methods: ['GET'])]
  public function kpis(Entite $entite, Request $request): JsonResponse
  {
    $status = $request->query->get('status', 'all');

    $k = $this->prospects->kpis($entite, $status);

    return $this->json($k);
  }

  #[Route('/ajax', name: 'ajax', methods: ['POST'])]
  public function ajax(Entite $entite, Request $request): JsonResponse
  {

    $dt = $request->request->all();
    $filters = [
      'status' => $dt['statusFilter'] ?? 'all',
      'source' => $dt['sourceFilter'] ?? 'all',
      'active' => $dt['activeFilter'] ?? 'all',
      'next'   => $dt['nextFilter'] ?? 'all',
    ];

    [$rows, $total, $filtered, $draw] = $this->prospects->datatable($entite, $dt, $filters);

    $data = array_map(function (Prospect $p) use ($entite) {
      $statusBadge = $this->renderView('administrateur/prospects/_status_badge.html.twig', ['p' => $p]);
      $next = $p->getNextActionAt()?->format('d/m/Y H:i') ?? '—';
      $name = sprintf('%s %s', strtoupper($p->getNom()), $p->getPrenom());
      $contact = $this->renderView('administrateur/prospects/_contact_cell.html.twig', ['p' => $p]);

      $score = $this->renderView('administrateur/prospects/_score_chip.html.twig', ['p' => $p]);

      $actions = $this->renderView('administrateur/prospects/_actions.html.twig', [
        'entite' => $entite,
        'p' => $p
      ]);

      return [
        'id' => $p->getId(),
        'updatedAt' => $p->getUpdatedAt()->format('d/m/Y'),
        'name' => $name,
        'contact' => $contact,
        'status' => $statusBadge,
        'score' => $score,
        'nextAction' => $next,
        'actions' => $actions,
      ];
    }, $rows);

    return $this->json([
      'draw' => $draw,
      'recordsTotal' => $total,
      'recordsFiltered' => $filtered,
      'data' => $data,
    ]);
  }

  #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
  public function new(Entite $entite, Request $request): Response
  {

    /** @var Utilisateur $user */
    $user = $this->getUser();
    $p = new Prospect();
    $p->setCreateur($user);
    $p->setEntite($entite);

    $p->setScore(50);
    $form = $this->createForm(ProspectType::class, $p, [
      'entite' => $entite,
    ]);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      $p->touch();
      $this->em->persist($p);
      $this->em->flush();

      $this->addFlash('success', 'Prospect créé.');
      return $this->redirectToRoute('app_administrateur_prospect_index', ['entite' => $entite->getId()]);
    }

    return $this->render('administrateur/prospects/form.html.twig', [
      'entite' => $entite,
      'prospect' => $p,
      'form' => $form->createView(),
      'modeEdition' => false,
      'title' => 'Nouveau prospect',
      'googleMapsBrowserKey' => $this->getParameter('GOOGLE_MAPS_BROWSER_KEY'),
    ]);
  }


  #[Route('/{prospect}', name: 'show', methods: ['GET', 'POST'])]
  public function show(Entite $entite, Prospect $prospect, Request $request): Response
  {
    if ($prospect->getEntite()?->getId() !== $entite->getId()) throw $this->createNotFoundException();

    /** @var Utilisateur $user */
    $user = $this->getUser();
    $interaction = new ProspectInteraction();
    $interaction->setCreateur($user);
    $interaction->setEntite($entite);
    $interaction->setProspect($prospect);
    $interaction->setActor($this->getUser());

    $form = $this->createForm(ProspectInteractionType::class, $interaction, [
      'entite' => $entite,
      'current_user' => $this->getUser(), // 👈 ici
    ]);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      $prospect->touch();
      $this->em->persist($interaction);
      $this->em->flush();
      $this->addFlash('success', 'Interaction ajoutée.');
      return $this->redirectToRoute('app_administrateur_prospect_show', ['entite' => $entite->getId(), 'prospect' => $prospect->getId()]);
    }

    return $this->render('administrateur/prospects/show.html.twig', [
      'entite' => $entite,
      'p' => $prospect,
      'interactionForm' => $form,

    ]);
  }

  #[Route('/{id}/toggle', name: 'toggle', methods: ['POST'])]
  public function toggle(Entite $entite, Prospect $p): JsonResponse
  {
    if ($p->getEntite()?->getId() !== $entite->getId()) return $this->json(['ok' => false], 404);

    if ($p->getStatus() === ProspectStatus::CONVERTED) {
      return $this->json(['ok' => false, 'error' => 'Prospect converti : non réactivable.'], 400);
    }

    $p->setIsActive(!$p->isActive());
    $p->touch();
    $this->em->flush();

    return $this->json(['ok' => true, 'isActive' => $p->isActive()]);
  }


  /**
   * Création d’un devis DRAFT pré-rempli + rattachement au prospect
   * (lignes à ajouter ensuite dans ton UI devis existant).
   */
  #[Route('/{id}/devis/create', name: 'devis_create', methods: ['POST'])]
  public function createDevis(Entite $entite, Prospect $p): JsonResponse
  {
    if ($p->getEntite()?->getId() !== $entite->getId()) return $this->json(['ok' => false], 404);

    /** @var Utilisateur $user */
    $user = $this->getUser();
    $devis = new Devis();
    $devis->setCreateur($user);
    $devis->setEntite($entite);
    $devis->setProspect($p);
    $devis->setDestinataire(null); // pas d'user obligatoire
    $devis->setDateEmission(new \DateTimeImmutable());
    $devis->setStatus(DevisStatus::DRAFT);

    // 👉 ici tu mets ta logique de numérotation (DevisSequence / SequenceNumberManager)
    // $devis->setNumero($this->sequence->nextDevisNumber($entite));

    $this->em->persist($devis);
    $this->em->flush();

    return $this->json([
      'ok' => true,
      'devisId' => $devis->getId(),
      'redirect' => $this->generateUrl('app_administrateur_devis_edit', ['entite' => $entite->getId(), 'id' => $devis->getId()]),
    ]);
  }


  #[Route('/{prospect}/edit', name: 'edit', methods: ['GET', 'POST'])]
  public function edit(Entite $entite, Prospect $prospect, Request $request): Response
  {
    if ($prospect->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException();
    }

    /** @var Utilisateur $user */
    $user = $this->getUser();

    $form = $this->createForm(ProspectType::class, $prospect, [
      'entite' => $entite,
    ]);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      $prospect->touch();
      $this->em->flush();

      $this->addFlash('success', 'Prospect mis à jour.');
      return $this->redirectToRoute('app_administrateur_prospect_show', [
        'entite' => $entite->getId(),
        'prospect' => $prospect->getId(),
      ]);
    }

    return $this->render('administrateur/prospects/form.html.twig', [
      'entite' => $entite,
      'prospect' => $prospect,
      'form' => $form->createView(),
      'modeEdition' => true,
      'title' => 'Modifier prospect',
      'googleMapsBrowserKey' => $this->getParameter('GOOGLE_MAPS_BROWSER_KEY'),
    ]);
  }


  #[Route('/{id}/convert', name: 'convert', methods: ['POST'])]
  public function convert(Entite $entite, Prospect $p, Request $request): Response
  {
    if ($p->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException();
    }
    /** @var Utilisateur $user */
    $user = $this->getUser();

    $token = (string) $request->request->get('_token', '');
    if (!$this->isCsrfTokenValid('convert_prospect_' . $p->getId(), $token)) {
      $this->addFlash('danger', 'Jeton CSRF invalide.');
      return $this->redirectToRoute('app_administrateur_prospect_show', ['entite' => $entite->getId(), 'prospect' => $p->getId()]);
    }

    try {
      $res = $this->prospectConverter->convert($entite, $p, $user);

      if ($res->entreprise) {
        $this->addFlash('success', 'Prospect converti : entreprise créée/associée. Prospect désactivé, historique conservé.');
      } else {
        $this->addFlash('success', 'Prospect converti : client créé/associé. Prospect désactivé, historique conservé.');
      }
    } catch (\Throwable $e) {
      $this->addFlash('danger', 'Conversion impossible : ' . $e->getMessage());
    }

    return $this->redirectToRoute('app_administrateur_prospect_show', [
      'entite' => $entite->getId(),
      'prospect' => $p->getId(),
    ]);
  }

  #[Route('/{prospect}/devis/ajax', name: 'devis_ajax', methods: ['POST'])]
  public function devisAjax(Entite $entite, Prospect $prospect, Request $request): JsonResponse
  {
    if ($prospect->getEntite()?->getId() !== $entite->getId()) {
      return $this->json(['error' => 'Not found'], 404);
    }

    $dt = $request->request->all();
    $draw   = (int)($dt['draw'] ?? 1);
    $start  = (int)($dt['start'] ?? 0);
    $length = (int)($dt['length'] ?? 10);
    $search = trim((string)($dt['search']['value'] ?? ''));

    $orderColIdx = (int)($dt['order'][0]['column'] ?? 0);
    $orderDir    = strtolower((string)($dt['order'][0]['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

    $colMap = [
      0 => 'd.id',
      1 => 'd.numero',
      2 => 'd.dateEmission',
      3 => 'd.montantTtcCents',
      4 => 'd.status',
    ];
    $orderBy = $colMap[$orderColIdx] ?? 'd.id';

    // Base query : devis du prospect, entité ok, et "envoyés" = != DRAFT
    $base = $this->em->createQueryBuilder()
      ->from(Devis::class, 'd')
      ->andWhere('d.entite = :entite')
      ->andWhere('d.prospect = :prospect')
      ->setParameter('entite', $entite)
      ->setParameter('prospect', $prospect);

    // Total
    $recordsTotal = (int) (clone $base)
      ->select('COUNT(d.id)')
      ->getQuery()
      ->getSingleScalarResult();

    // Search
    $qb = clone $base;
    $qb->select('d');

    if ($search !== '') {
      // numéro + id (simple)
      $qb->andWhere('LOWER(COALESCE(d.numero, \'\')) LIKE :q OR CAST(d.id AS string) LIKE :q')
        ->setParameter('q', '%' . mb_strtolower($search) . '%');
    }

    // Filtered
    $recordsFiltered = (int) (clone $qb)
      ->select('COUNT(d.id)')
      ->getQuery()
      ->getSingleScalarResult();

    // Rows
    /** @var Devis[] $rows */
    $rows = $qb
      ->orderBy($orderBy, $orderDir)
      ->setFirstResult($start)
      ->setMaxResults($length)
      ->getQuery()
      ->getResult();

    $data = array_map(function (Devis $d) use ($entite, $prospect) {
      $statusHtml = $this->renderView('administrateur/devis/_status_badge.html.twig', ['d' => $d]);

      $actions = $this->renderView('administrateur/prospects/_devis_actions_row.html.twig', [
        'entite' => $entite,
        'p' => $prospect,
        'd' => $d,
      ]);

      return [
        'id' => $d->getId(),
        'numero' => $d->getNumero() ?? ('Devis #' . $d->getId()),
        'dateEmission' => $d->getDateEmission()?->format('d/m/Y') ?? '—',
        'ttc' => number_format(($d->getMontantTtcCents() ?? 0) / 100, 2, ',', ' ') . ' €',
        'status' => $statusHtml,
        'actions' => $actions,
      ];
    }, $rows);

    return $this->json([
      'draw' => $draw,
      'recordsTotal' => $recordsTotal,
      'recordsFiltered' => $recordsFiltered,
      'data' => $data,
    ]);
  }


  #[Route('/{prospect}/interaction/{interaction}/edit', name: 'interaction_edit', methods: ['GET', 'POST'])]
  public function editInteraction(
    Entite $entite,
    Prospect $prospect,
    ProspectInteraction $interaction,
    Request $request
  ): Response {
    // ✅ Sécurité entité + cohérence interaction -> prospect
    if ($prospect->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException();
    }
    if ($interaction->getProspect()?->getId() !== $prospect->getId()) {
      throw $this->createNotFoundException();
    }

    /** @var Utilisateur $user */
    $user = $this->getUser();

    $form = $this->createForm(ProspectInteractionType::class, $interaction, [
      'entite' => $entite,
      'current_user' => $this->getUser(), // 👈 ici
    ]);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      // Optionnel: si tu veux forcer l'acteur à l'admin actuel
      // $interaction->setActor($user);

      $prospect->touch();
      $this->em->flush();

      $this->addFlash('success', 'Interaction mise à jour.');
      return $this->redirectToRoute('app_administrateur_prospect_show', [
        'entite' => $entite->getId(),
        'prospect' => $prospect->getId(),
      ]);
    }

    return $this->render('administrateur/prospects/interaction_edit.html.twig', [
      'entite' => $entite,
      'p' => $prospect,
      'interaction' => $interaction,
      'form' => $form->createView(),

    ]);
  }

  #[Route('/{prospect}/interaction/{interaction}/delete', name: 'interaction_delete', methods: ['POST'])]
  public function deleteInteraction(
    Entite $entite,
    Prospect $prospect,
    ProspectInteraction $interaction,
    Request $request
  ): RedirectResponse {
    if ($prospect->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException();
    }
    if ($interaction->getProspect()?->getId() !== $prospect->getId()) {
      throw $this->createNotFoundException();
    }

    $token = (string) $request->request->get('_token', '');
    if (!$this->isCsrfTokenValid('delete_interaction_' . $interaction->getId(), $token)) {
      $this->addFlash('danger', 'Jeton CSRF invalide.');
      return $this->redirectToRoute('app_administrateur_prospect_show', [
        'entite' => $entite->getId(),
        'prospect' => $prospect->getId(),
      ]);
    }

    $prospect->touch();
    $this->em->remove($interaction);
    $this->em->flush();

    $this->addFlash('success', 'Interaction supprimée.');
    return $this->redirectToRoute('app_administrateur_prospect_show', [
      'entite' => $entite->getId(),
      'prospect' => $prospect->getId(),
    ]);
  }



  #[Route('/entreprise/new', name: 'entreprise_new', methods: ['POST'])]
  public function newEntrepriseAjax(
      Entite $entite,
      Request $request,
      EM $em
  ): JsonResponse {
      if (!$request->isXmlHttpRequest()) {
          return new JsonResponse(['success' => false, 'message' => 'Requête invalide.'], 400);
      }

      $token = (string) $request->request->get('_token', '');
      if (!$this->isCsrfTokenValid('new_entreprise_ajax', $token)) {
          return new JsonResponse(['success' => false, 'message' => 'Jeton CSRF invalide.'], 419);
      }

      $raison = trim((string) $request->request->get('raisonSociale', ''));
      if ($raison === '') {
          return new JsonResponse(['success' => false, 'message' => 'Raison sociale obligatoire.'], 422);
      }

      $e = new Entreprise();
      $e->setEntite($entite);
      $e->setCreateur($this->getUser());
      $e->setRaisonSociale($raison);

      $siret = trim((string) $request->request->get('siret', ''));
      if ($siret !== '') {
          $e->setSiret($siret);
      }

      $mail = trim((string) $request->request->get('emailFacturation', ''));
      if ($mail !== '') {
          $e->setEmailFacturation($mail);
      }

      $tva = trim((string) $request->request->get('numeroTVA', ''));
      if ($tva !== '') {
          $e->setNumeroTVA($tva);
      }

      $adresse = trim((string) $request->request->get('adresse', ''));
      $cp = trim((string) $request->request->get('codePostal', ''));
      $ville = trim((string) $request->request->get('ville', ''));
      $pays = trim((string) $request->request->get('pays', ''));

      if ($adresse !== '') $e->setAdresse($adresse);
      if ($cp !== '') $e->setCodePostal($cp);
      if ($ville !== '') $e->setVille($ville);
      if ($pays !== '') $e->setPays($pays);

      $em->persist($e);

      try {
          $em->flush();
      } catch (\Throwable $ex) {
          return new JsonResponse([
              'success' => false,
              'message' => 'Impossible de créer l’entreprise (doublon ou contrainte).'
          ], 409);
      }

      return new JsonResponse([
          'success' => true,
          'id' => $e->getId(),
          'label' => (string) $e->getRaisonSociale(),
      ]);
  }


  #[Route('/entreprise/resolve', name: 'entreprise_resolve', methods: ['POST'])]
  public function resolveEntrepriseAjax(Entite $entite, Request $request): JsonResponse
  {
    if (!$request->isXmlHttpRequest()) {
      return $this->json(['success' => false, 'message' => 'Requête invalide.'], 400);
    }

    $name  = trim((string)$request->request->get('raisonSociale'));
    $cp    = trim((string)$request->request->get('codePostal'));
    $ville = trim((string)$request->request->get('ville'));

    $res = $this->entrepriseResolver->resolve($name, $cp ?: null, $ville ?: null);

    return $this->json($res, $res['success'] ? 200 : 422);
  }
}
