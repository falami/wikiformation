<?php

namespace App\Controller\Administrateur;

use App\Entity\{Entreprise, Entite, Utilisateur};
use App\Form\Administrateur\EntrepriseType;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, RedirectResponse, Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use App\Security\Permission\TenantPermission;
use Symfony\Component\Security\Http\Attribute\IsGranted;



#[Route('/administrateur/{entite}/entreprise')]
#[IsGranted(TenantPermission::ENTREPRISE_MANAGE, subject: 'entite')]
final class EntrepriseController extends AbstractController
{
  public function __construct(
    private UtilisateurEntiteManager $utilisateurEntiteManager
  ) {}

  #[Route('', name: 'app_administrateur_entreprise_index', methods: ['GET'])]
  public function index(Entite $entite): Response
  {


    /** @var Utilisateur $user */
    $user = $this->getUser();

    return $this->render('administrateur/entreprise/index.html.twig', [
      'entite' => $entite,


    ]);
  }

  #[Route('/ajax', name: 'app_administrateur_entreprise_ajax', methods: ['POST'])]
  public function ajax(Entite $entite, Request $request, EntityManagerInterface $em): JsonResponse
  {


    $draw   = $request->request->getInt('draw', 0);
    $start  = max(0, $request->request->getInt('start', 0));
    $length = $request->request->getInt('length', 10);

    // DataTables peut envoyer -1 (= tout). On borne.
    if ($length <= 0 || $length > 500) {
      $length = 10;
    }

    // Search DataTables (global)
    $search  = (array) $request->request->all('search');
    $searchV = trim((string) ($search['value'] ?? ''));

    // Filtres custom (filterbar)
    // lockedFilter: all | 1 | 0
    $lockedFilter = (string) $request->request->get('lockedFilter', 'all');
    $searchName   = trim((string) $request->request->get('searchName', ''));

    // Tri DataTables
    $order = (array) $request->request->all('order');
    $orderDir = strtolower((string)($order[0]['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
    $orderColIdx = (int) ($order[0]['column'] ?? 0);

    $orderMap = [
      0 => 'e.id',
      1 => 'e.raisonSociale',
      2 => 'e.siret',
      3 => 'e.emailFacturation',
      // autres colonnes (counts, locked, actions) : pas de tri DB ici
    ];
    $orderBy = $orderMap[$orderColIdx] ?? 'e.id';

    /**
     * Applique filtres sur un QueryBuilder (alias e)
     * IMPORTANT : paramètres uniques pour éviter conflit.
     */
    $applyFilters = function (\Doctrine\ORM\QueryBuilder $qb, string $eAlias) use ($searchV, $searchName, $lockedFilter): void {

      // 1) Recherche DataTables globale
      if ($searchV !== '') {
        $qb->andWhere("($eAlias.raisonSociale LIKE :dt_q OR $eAlias.siret LIKE :dt_q OR $eAlias.emailFacturation LIKE :dt_q)")
          ->setParameter('dt_q', '%' . $searchV . '%');
      }

      // 2) Recherche custom (barre filtre) — même champs
      if ($searchName !== '') {
        $qb->andWhere("($eAlias.raisonSociale LIKE :fb_q OR $eAlias.siret LIKE :fb_q OR $eAlias.emailFacturation LIKE :fb_q)")
          ->setParameter('fb_q', '%' . $searchName . '%');
      }

      // 3) Locked (selon tes règles)
      // locked = inscriptions>0 OU conventions>0 OU factures>0 OU utilisateurs>0
      // ✅ SIZE() fonctionne en DQL pour une collection
      if ($lockedFilter === '1') {
        $qb->andWhere(
          "(SIZE($eAlias.inscriptions) > 0
                  OR SIZE($eAlias.conventionContrats) > 0
                  OR SIZE($eAlias.factures) > 0
                  OR SIZE($eAlias.utilisateurs) > 0)"
        );
      } elseif ($lockedFilter === '0') {
        $qb->andWhere(
          "(SIZE($eAlias.inscriptions) = 0
                  AND SIZE($eAlias.conventionContrats) = 0
                  AND SIZE($eAlias.factures) = 0
                  AND SIZE($eAlias.utilisateurs) = 0)"
        );
      }
    };

    // -------------------------
    // 1) Query principale (data)
    // -------------------------
    $qb = $em->getRepository(Entreprise::class)->createQueryBuilder('e')
      ->andWhere('e.entite = :entite')
      ->setParameter('entite', $entite);

    $applyFilters($qb, 'e');

    // -------------------------
    // 2) recordsTotal (sans filtres)
    // -------------------------
    $qbTotal = $em->getRepository(Entreprise::class)->createQueryBuilder('e_t')
      ->select('COUNT(e_t.id)')
      ->andWhere('e_t.entite = :entite')
      ->setParameter('entite', $entite);

    $recordsTotal = (int) $qbTotal->getQuery()->getSingleScalarResult();

    // -------------------------
    // 3) recordsFiltered (avec filtres)
    // -------------------------
    $qbFiltered = $em->getRepository(Entreprise::class)->createQueryBuilder('e_f')
      ->select('COUNT(e_f.id)')
      ->andWhere('e_f.entite = :entite')
      ->setParameter('entite', $entite);

    $applyFilters($qbFiltered, 'e_f');

    $recordsFiltered = (int) $qbFiltered->getQuery()->getSingleScalarResult();

    // -------------------------
    // 4) Pagination + tri
    // -------------------------
    /** @var Entreprise[] $rows */
    $rows = $qb
      ->orderBy($orderBy, $orderDir)
      ->addOrderBy('e.id', 'DESC')
      ->setFirstResult($start)
      ->setMaxResults($length)
      ->getQuery()
      ->getResult();

    // -------------------------
    // 5) Formatage des lignes
    // -------------------------
    $data = [];
    foreach ($rows as $e) {
      if (!$e instanceof Entreprise) {
        continue;
      }

      $locked = $this->isLockedEntreprise($e);

      $data[] = [
        'id'              => $e->getId(),
        'raisonSociale'   => $e->getRaisonSociale() ?: '—',
        'siret'           => $e->getSiret() ?: '—',
        'emailFacturation' => $e->getEmailFacturation() ?: '—',
        'inscriptions'    => $e->getInscriptions()->count(),
        'factures'        => $e->getFactures()->count(),
        'locked'          => $locked ? 'Oui' : 'Non',
        'actions'         => $this->renderView('administrateur/entreprise/_actions.html.twig', [
          'entite'     => $entite,
          'entreprise' => $e,
          'locked'     => $locked,
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


  #[Route('/ajouter', name: 'app_administrateur_entreprise_ajouter', methods: ['GET', 'POST'])]
  #[Route('/modifier/{id}', name: 'app_administrateur_entreprise_modifier', methods: ['GET', 'POST'])]
  public function addEdit(Entite $entite, Request $request, EntityManagerInterface $em, ?Entreprise $entreprise = null): Response
  {


    /** @var Utilisateur $user */
    $user = $this->getUser();

    $isEdit = (bool)$entreprise;
    if (!$entreprise) {
      $entreprise = new Entreprise();
      $entreprise->setCreateur($user);
      $entreprise->setEntite($entite);
    }

    $locked = $isEdit ? $this->isLockedEntreprise($entreprise) : false;

    $form = $this->createForm(EntrepriseType::class, $entreprise, [
      'entite' => $entite,
    ]);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {

      $em->persist($entreprise);
      $em->flush();

      $this->addFlash('success', $isEdit ? 'Entreprise modifiée.' : 'Entreprise ajoutée.');
      return $this->redirectToRoute('app_administrateur_entreprise_index', ['entite' => $entite->getId()]);
    }

    return $this->render('administrateur/entreprise/form.html.twig', [
      'entite' => $entite,
      'entreprise' => $entreprise,
      'modeEdition' => $isEdit,
      'locked' => $locked,
      'form' => $form->createView(),
      'googleMapsBrowserKey' => $this->getParameter('GOOGLE_MAPS_BROWSER_KEY'),
    ]);
  }

  #[Route('/supprimer/{id}', name: 'app_administrateur_entreprise_supprimer', methods: ['GET'])]
  public function delete(Entite $entite, EntityManagerInterface $em, Entreprise $entreprise): RedirectResponse
  {


    // option : refuser suppression si verrouillée
    if ($this->isLockedEntreprise($entreprise)) {
      $this->addFlash('warning', 'Entreprise utilisée (inscriptions / factures / conventions). Suppression refusée.');
      return $this->redirectToRoute('app_administrateur_entreprise_index', ['entite' => $entite->getId()]);
    }

    $id = $entreprise->getId();
    $em->remove($entreprise);
    $em->flush();

    $this->addFlash('success', 'Entreprise #' . $id . ' supprimée.');
    return $this->redirectToRoute('app_administrateur_entreprise_index', ['entite' => $entite->getId()]);
  }

  private function isLockedEntreprise(Entreprise $e): bool
  {
    return $e->getInscriptions()->count() > 0
      || $e->getConventionContrats()->count() > 0
      || $e->getFactures()->count() > 0;
  }
}
