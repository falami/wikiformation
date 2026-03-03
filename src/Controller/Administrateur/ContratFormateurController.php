<?php
// src/Controller/Administrateur/ContratFormateurAdminController.php

namespace App\Controller\Administrateur;

use App\Entity\{ContratFormateur, Entite, Utilisateur, Session, Formateur};
use App\Enum\ContratFormateurStatus;
use App\Form\Administrateur\ContratFormateurType;
use App\Repository\FormateurRepository;
use App\Repository\SessionRepository;
use App\Service\Sequence\SequenceNumberManager;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Service\Sequence\ContratFormateurNumberGenerator;
use App\Security\Permission\TenantPermission;

#[Route('/administrateur/{entite}/formateurs/contrats', name: 'app_administrateur_formateurs_contrats_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::CONTRAT_FORMATEUR_MANAGE, subject: 'entite')]
class ContratFormateurController extends AbstractController
{
  public function __construct(
    private EntityManagerInterface $em,
    private UtilisateurEntiteManager $utilisateurEntiteManager,
    private SequenceNumberManager $sequenceNumberManager,
    private ContratFormateurNumberGenerator $contratNumberGenerator,
  ) {}

  #[Route('', name: 'list', methods: ['GET'])]
  public function list(Entite $entite): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    return $this->render('administrateur/formateur/contrat/list.html.twig', [
      'entite' => $entite,

    ]);
  }

  #[Route('/kpis', name: 'kpis', methods: ['GET'])]
  public function kpis(Entite $entite, Request $request): JsonResponse
  {
    $statusFilter    = (string)$request->query->get('status', 'all');
    $formateurFilter = (string)$request->query->get('formateur', 'all');
    $sessionFilter   = (string)$request->query->get('session', 'all');

    $qb = $this->em->getRepository(ContratFormateur::class)->createQueryBuilder('c')
      ->leftJoin('c.formateur', 'f')
      ->leftJoin('c.session', 'se')
      ->andWhere('c.entite = :entite')
      ->setParameter('entite', $entite);

    if ($statusFilter !== 'all') {
      try {
        $qb->andWhere('c.status = :st')->setParameter('st', ContratFormateurStatus::from($statusFilter));
      } catch (\ValueError $e) {
      }
    }
    if ($formateurFilter !== 'all' && ctype_digit($formateurFilter)) {
      $qb->andWhere('f.id = :fid')->setParameter('fid', (int)$formateurFilter);
    }
    if ($sessionFilter !== 'all' && ctype_digit($sessionFilter)) {
      $qb->andWhere('se.id = :sid')->setParameter('sid', (int)$sessionFilter);
    }

    $total = (int)(clone $qb)
      ->select('COUNT(DISTINCT c.id)')
      ->getQuery()->getSingleScalarResult();

    $pdf = (int)(clone $qb)
      ->select('COUNT(DISTINCT c.id)')
      ->andWhere('c.pdfPath IS NOT NULL')
      ->andWhere('c.pdfPath <> \'\'')
      ->getQuery()->getSingleScalarResult();

    $byStatus = [];
    foreach (ContratFormateurStatus::cases() as $st) {
      $byStatus[$st->value] = (int)(clone $qb)
        ->select('COUNT(DISTINCT c.id)')
        ->andWhere('c.status = :st2')
        ->setParameter('st2', $st)
        ->getQuery()->getSingleScalarResult();
    }

    return new JsonResponse([
      'total' => $total,
      'pdf'   => $pdf,
      'byStatus' => $byStatus,
    ]);
  }

  #[Route('/meta', name: 'meta', methods: ['GET'])]
  public function meta(Entite $entite): JsonResponse
  {
    $statuses = array_map(
      fn(ContratFormateurStatus $s) => [
        'value' => $s->value,
        'name'  => $s->name,
        'label' => method_exists($s, 'label') ? $s->label() : $s->value,
      ],
      ContratFormateurStatus::cases()
    );

    $formateurs = $this->em->createQueryBuilder()
      ->select('f.id AS id, u.prenom AS prenom, u.nom AS nom')
      ->from(Formateur::class, 'f')
      ->innerJoin('f.utilisateur', 'u')
      ->andWhere('f.entite = :entite')->setParameter('entite', $entite)
      ->orderBy('u.nom', 'ASC')->addOrderBy('u.prenom', 'ASC')
      ->getQuery()->getArrayResult();

    $formateurs = array_map(fn($r) => [
      'id' => (int)$r['id'],
      'label' => trim(($r['prenom'] ?? '') . ' ' . ($r['nom'] ?? '')) ?: '—'
    ], $formateurs);

    $sessions = $this->em->createQueryBuilder()
      ->select('s.id AS id, s.code AS code')
      ->from(Session::class, 's')
      ->andWhere('s.entite = :entite')->setParameter('entite', $entite)
      ->orderBy('s.id', 'DESC')
      ->getQuery()->getArrayResult();

    $sessions = array_map(fn($r) => [
      'id' => (int)$r['id'],
      'label' => $r['code'] ? ('Session ' . $r['code']) : ('Session #' . $r['id'])
    ], $sessions);

    return new JsonResponse([
      'statuses'   => $statuses,
      'formateurs' => $formateurs,
      'sessions'   => $sessions,
    ]);
  }

  #[Route('/ajax', name: 'ajax', methods: ['POST'])]
  public function ajax(Entite $entite, Request $request): JsonResponse
  {
    $start   = $request->request->getInt('start', 0);
    $length  = $request->request->getInt('length', 10);

    $search  = $request->request->all('search');
    $searchV = trim((string)($search['value'] ?? ''));

    $statusFilter    = (string)$request->request->get('statusFilter', 'all');
    $formateurFilter = (string)$request->request->get('formateurFilter', 'all');
    $sessionFilter   = (string)$request->request->get('sessionFilter', 'all');

    $order = $request->request->all('order');
    $orderColIdx = isset($order[0]['column']) ? (int)$order[0]['column'] : 3;
    $orderDir = strtolower($order[0]['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

    $orderMap = [
      0 => 'c.numero',
      1 => 'u.nom',
      3 => 'c.dateCreation',
      4 => 'c.signatureAt',
    ];
    $orderBy = $orderMap[$orderColIdx] ?? 'c.dateCreation';

    $qb = $this->em->getRepository(ContratFormateur::class)->createQueryBuilder('c')
      ->leftJoin('c.formateur', 'f')->addSelect('f')
      ->leftJoin('f.utilisateur', 'u')->addSelect('u')
      ->leftJoin('c.session', 'se')->addSelect('se')
      ->andWhere('c.entite = :entite')
      ->setParameter('entite', $entite);

    $recordsTotal = (int)$this->em->getRepository(ContratFormateur::class)->createQueryBuilder('c')
      ->select('COUNT(c.id)')
      ->andWhere('c.entite = :entite')->setParameter('entite', $entite)
      ->getQuery()->getSingleScalarResult();

    if ($statusFilter !== 'all') {
      try {
        $qb->andWhere('c.status = :st')->setParameter('st', ContratFormateurStatus::from($statusFilter));
      } catch (\ValueError $e) {
      }
    }
    if ($formateurFilter !== 'all' && ctype_digit($formateurFilter)) {
      $qb->andWhere('f.id = :fid')->setParameter('fid', (int)$formateurFilter);
    }
    if ($sessionFilter !== 'all' && ctype_digit($sessionFilter)) {
      $qb->andWhere('se.id = :sid')->setParameter('sid', (int)$sessionFilter);
    }

    if ($searchV !== '') {
      $qb->andWhere('
        c.numero LIKE :q
        OR u.nom LIKE :q
        OR u.prenom LIKE :q
        OR u.email LIKE :q
        OR se.code LIKE :q
      ')->setParameter('q', '%' . $searchV . '%');
    }

    $qbCount = (clone $qb);
    $qbCount->resetDQLPart('select');
    $qbCount->resetDQLPart('orderBy');

    $recordsFiltered = (int)$qbCount
      ->select('COUNT(DISTINCT c.id)')
      ->getQuery()
      ->getSingleScalarResult();

    /** @var ContratFormateur[] $rows */
    $rows = $qb
      ->orderBy($orderBy, $orderDir)
      ->addOrderBy('c.id', 'DESC')
      ->setFirstResult($start)
      ->setMaxResults($length)
      ->getQuery()->getResult();

    $data = array_map(function (ContratFormateur $c) use ($entite) {
      $u = $c->getFormateur()?->getUtilisateur();
      $fullname = $u ? trim(($u->getPrenom() ?? '') . ' ' . ($u->getNom() ?? '')) : '—';
      $email = $u?->getEmail();

      $session = $c->getSession();
      $sessionLabel = $session ? ('Session ' . ($session->getCode() ?? '#' . $session->getId())) : '—';

      $status = $c->getStatus()->value;

      $statusHtml = match ($status) {
        'BROUILLON' => '<span class="status-pill status-brouillon">Brouillon</span>',
        'ENVOYE'    => '<span class="status-pill status-envoye">Envoyé</span>',
        'SIGNE'     => '<span class="status-pill status-signe">Signé</span>',
        'RESILIE'   => '<span class="status-pill status-resilie">Résilié</span>',
        'ARCHIVE'   => '<span class="status-pill status-archive">Archivé</span>',
        default     => '<span class="status-pill status-archive">' . htmlspecialchars($status) . '</span>',
      };

      $actions = $this->renderView('administrateur/formateur/contrat/_actions.html.twig', [
        'c' => $c,
        'entite' => $entite,
        'status' => $status,
      ]);

      return [
        'numero'       => '<strong>' . htmlspecialchars((string)$c->getNumero()) . '</strong>',
        'formateur'    => $email ? $fullname . '<div class="small text-muted">' . htmlspecialchars($email) . '</div>' : $fullname,
        'session'      => htmlspecialchars($sessionLabel),
        'dateCreation' => $c->getDateCreation()?->format('d/m/Y H:i') ?? '—',
        'signatureAt'  => $c->getSignatureAt()?->format('d/m/Y H:i') ?? 'Non signé',
        'status'       => $statusHtml,
        'actions'      => $actions,
      ];
    }, $rows);

    return new JsonResponse([
      'draw'            => (int)$request->request->get('draw'),
      'recordsTotal'    => $recordsTotal,
      'recordsFiltered' => $recordsFiltered,
      'data'            => $data,
    ]);
  }

  #[Route('/nouveau', name: 'new', methods: ['GET', 'POST'])]
  public function new(Entite $entite, Request $request): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    $numero = $this->contratNumberGenerator->nextForEntite($entite->getId());

    $contrat = (new ContratFormateur())
      ->setEntite($entite)
      ->setCreateur($user)
      ->setStatus(ContratFormateurStatus::BROUILLON)
      ->setNumero($numero);

    $prefs = $entite->getPreferences();
    if ($prefs) {
      if (!$contrat->getConditionsGenerales()) {
        $contrat->setConditionsGenerales($prefs->getContratFormateurConditionsGeneralesDefault());
      }
      if (!$contrat->getConditionsParticulieres()) {
        $contrat->setConditionsParticulieres($prefs->getContratFormateurConditionsParticulieresDefault());
      }
      if (!$contrat->getClauseEngagement()) {
        $contrat->setClauseEngagement($prefs->getContratFormateurClauseEngagementDefault());
      }
      if (!$contrat->getClauseObjet()) {
        $contrat->setClauseObjet($prefs->getContratFormateurClauseObjetDefault());
      }
      if (!$contrat->getClauseObligations()) {
        $contrat->setClauseObligations($prefs->getContratFormateurClauseObligationsDefault());
      }
      if (!$contrat->getClauseNonConcurrence()) {
        $contrat->setClauseNonConcurrence($prefs->getContratFormateurClauseNonConcurrenceDefault());
      }
      if (!$contrat->getClauseInexecution()) {
        $contrat->setClauseInexecution($prefs->getContratFormateurClauseInexecutionDefault());
      }
      if (!$contrat->getClauseAssurance()) {
        $contrat->setClauseAssurance($prefs->getContratFormateurClauseAssuranceDefault());
      }
      if (!$contrat->getClauseFinContrat()) {
        $contrat->setClauseFinContrat($prefs->getContratFormateurClauseFinContratDefault());
      }
      if (!$contrat->getClauseProprieteIntellectuelle()) {
        $contrat->setClauseProprieteIntellectuelle($prefs->getContratFormateurClauseProprieteIntellectuelleDefault());
      }
    }


    if ($prefs && $prefs->getSignatureOrganismePath() && !$contrat->getSignatureOrganismePath()) {
      $contrat->setSignatureOrganismePath($prefs->getSignatureOrganismePath());
      $contrat->setSignatureOrganismeNom($prefs->getSignatureOrganismeNom());
      $contrat->setSignatureOrganismeFonction($prefs->getSignatureOrganismeFonction());
      $contrat->setSignatureOrganismeAt($prefs->getSignatureOrganismeAt());
      $contrat->setSignatureOrganismeIp($prefs->getSignatureOrganismeIp());
      $contrat->setSignatureOrganismeUserAgent($prefs->getSignatureOrganismeUserAgent());
      $contrat->setSignatureOrganismePar($prefs->getSignatureOrganismePar());
    }


    $form = $this->createForm(ContratFormateurType::class, $contrat, [
      'entite' => $entite,
    ]);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {

      $formateur = $contrat->getFormateur();
      $session   = $contrat->getSession();

      if ($formateur && $formateur->getEntite()?->getId() !== $entite->getId()) {
        $this->addFlash('danger', 'Ce formateur n’appartient pas à cette entité.');
        return $this->redirectToRoute('app_administrateur_formateurs_contrats_new', ['entite' => $entite->getId()]);
      }

      // ✅ Snapshot TVA depuis formateur (si besoin tu peux conditionner si champs contrat vides)
      if ($formateur) {
        $contrat
          ->setAssujettiTva($formateur->isAssujettiTva())
          ->setTauxTva($formateur->getTauxTvaParDefaut())
          ->setNumeroTvaIntra($formateur->getNumeroTvaIntra());
      }

      if ($formateur && $session) {
        $existing = $this->em->getRepository(ContratFormateur::class)->findOneBy([
          'entite'    => $entite,
          'formateur' => $formateur,
          'session'   => $session,
        ]);

        if ($existing) {
          $this->addFlash('warning', sprintf(
            'Un contrat existe déjà pour %s sur la session %s (contrat %s).',
            $formateur->getUtilisateur()?->getEmail() ?? 'ce formateur',
            $session->getCode() ?? ('#' . $session->getId()),
            $existing->getNumero()
          ));

          return $this->redirectToRoute('app_administrateur_formateurs_contrats_edit', [
            'entite' => $entite->getId(),
            'id'     => $existing->getId(),
          ]);
        }
      }

      if (!$contrat->getNumero()) {
        $contrat->setNumero($this->contratNumberGenerator->nextForEntite($entite->getId()));
      }


      $this->em->persist($contrat);

      try {
        $this->em->flush();
      } catch (UniqueConstraintViolationException $e) {
        $this->addFlash('warning', 'Ce contrat existe déjà (doublon session + formateur).');
        return $this->redirectToRoute('app_administrateur_formateurs_contrats_list', ['entite' => $entite->getId()]);
      }

      $this->addFlash('success', 'Contrat formateur créé avec succès.');
      return $this->redirectToRoute('app_administrateur_formateurs_contrats_list', ['entite' => $entite->getId()]);
    }

    return $this->render('administrateur/formateur/contrat/form.html.twig', [
      'entite' => $entite,
      'form'   => $form->createView(),
    ]);
  }

  #[Route('/{id}/modifier', name: 'edit', methods: ['GET', 'POST'])]
  public function edit(Entite $entite, ContratFormateur $contrat, Request $request): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    if ($contrat->getEntite()->getId() !== $entite->getId()) {
      throw $this->createNotFoundException();
    }

    if ($contrat->getStatus() !== ContratFormateurStatus::BROUILLON) {
      $this->addFlash('warning', 'Ce contrat n’est plus modifiable (statut non brouillon).');
      return $this->redirectToRoute('app_administrateur_formateurs_contrats_list', ['entite' => $entite->getId()]);
    }

    $form = $this->createForm(ContratFormateurType::class, $contrat, ['entite' => $entite]);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {

      $formateur = $contrat->getFormateur();
      if ($formateur) {
        $contrat
          ->setAssujettiTva($formateur->isAssujettiTva())
          ->setTauxTva($formateur->getTauxTvaParDefaut())
          ->setNumeroTvaIntra($formateur->getNumeroTvaIntra());
      }

      $this->em->flush();

      $this->addFlash('success', 'Contrat formateur mis à jour avec succès.');
      return $this->redirectToRoute('app_administrateur_formateurs_contrats_list', ['entite' => $entite->getId()]);
    }

    return $this->render('administrateur/formateur/contrat/form.html.twig', [
      'entite' => $entite,
      'form'   => $form->createView(),
      'contrat' => $contrat,
      'is_edit' => true,
    ]);
  }

  #[Route('/{id}/supprimer', name: 'supprimer', methods: ['POST'])]
  public function supprimer(Entite $entite, ContratFormateur $contrat, Request $request): Response
  {
    if ($contrat->getEntite()->getId() !== $entite->getId()) {
      throw $this->createNotFoundException();
    }

    if ($contrat->getStatus() !== ContratFormateurStatus::BROUILLON) {
      $this->addFlash('warning', 'Suppression impossible : le contrat n’est plus en brouillon.');
      return $this->redirectToRoute('app_administrateur_formateurs_contrats_list', ['entite' => $entite->getId()]);
    }

    $token = (string)$request->request->get('_token', '');
    if (!$this->isCsrfTokenValid('delete_contrat_formateur_' . $contrat->getId(), $token)) {
      $this->addFlash('danger', 'Jeton CSRF invalide.');
      return $this->redirectToRoute('app_administrateur_formateurs_contrats_list', ['entite' => $entite->getId()]);
    }

    $this->em->remove($contrat);
    $this->em->flush();

    $this->addFlash('success', 'Contrat supprimé.');
    return $this->redirectToRoute('app_administrateur_formateurs_contrats_list', ['entite' => $entite->getId()]);
  }

  /**
   * ✅ NOUVEL endpoint recommandé : calcul heures × taux horaire (ou jours × taux journalier)
   * Retourne aussi une explication pour l'aide.
   */
  #[Route('/calc-montant', name: 'calc_montant', methods: ['POST'])]
  public function calcMontant(
    Entite $entite,
    Request $request,
    FormateurRepository $formateurRepo,
    SessionRepository $sessionRepo
  ): JsonResponse {
    $formateurId = (int)$request->request->get('formateurId', 0);
    $sessionId   = (int)$request->request->get('sessionId', 0);

    if (!$formateurId || !$sessionId) {
      return new JsonResponse(['success' => false, 'message' => 'Formateur ou session manquant.'], 400);
    }

    $formateur = $formateurRepo->find($formateurId);
    $session   = $sessionRepo->find($sessionId);

    if (!$formateur || !$session) {
      return new JsonResponse(['success' => false, 'message' => 'Formateur ou session introuvable.'], 404);
    }

    if (
      $formateur->getEntite()?->getId() !== $entite->getId()
      || $session->getEntite()?->getId() !== $entite->getId()
    ) {
      return new JsonResponse(['success' => false, 'message' => 'Incohérence d’entité.'], 403);
    }

    // Mode rémunération : HEURE / JOUR / null
    $mode = $formateur->getModeRemuneration();

    $result = [
      'success'           => true,
      'mode'              => $mode,
      'nbJours'           => 0,
      'heures'            => 0.0,
      'tauxCents'         => null,
      'tauxEuros'         => null,
      'montantPrevuCents' => 0,
      'montantPrevuEuros' => null,
      'explication'       => 'Impossible de calculer (mode/taux/heures manquants).',
    ];

    if ($mode === 'JOUR') {
      $nbJours = $session->getNombreJoursPourFormateur($formateur);
      $taux    = $formateur->getTauxJournalierCents();

      $result['nbJours']   = $nbJours;
      $result['tauxCents'] = $taux;

      if ($taux !== null && $nbJours > 0) {
        $montantCents = (int)($taux * $nbJours);
        $result['montantPrevuCents'] = $montantCents;
        $result['montantPrevuEuros'] = number_format($montantCents / 100, 2, ',', ' ');
        $result['tauxEuros']         = number_format($taux / 100, 2, ',', ' ');
        $result['explication']       = sprintf('%d jour(s) × %s € = %s €', $nbJours, $result['tauxEuros'], $result['montantPrevuEuros']);
      }

      return new JsonResponse($result);
    }

    // défaut: HEURE
    if ($mode === 'HEURE') {
      $heures = $session->getNombreHeuresPourFormateur($formateur);
      $taux   = $formateur->getTauxHoraireCents();

      $result['heures']    = $heures;
      $result['tauxCents'] = $taux;

      if ($taux !== null && $heures > 0) {
        $montantCents = (int) round($taux * $heures);
        $result['montantPrevuCents'] = $montantCents;
        $result['montantPrevuEuros'] = number_format($montantCents / 100, 2, ',', ' ');
        $result['tauxEuros']         = number_format($taux / 100, 2, ',', ' ');
        $result['explication']       = sprintf('%.2f h × %s € = %s €', $heures, $result['tauxEuros'], $result['montantPrevuEuros']);
      }

      return new JsonResponse($result);
    }

    return new JsonResponse($result);
  }

  /**
   * ✅ Compat : ton template appelle encore suggest_montant.
   * On le garde, mais il délègue au même calcul (HEURE/JOUR).
   */
  #[Route('/suggest-montant', name: 'suggest_montant', methods: ['POST'])]
  public function suggestMontant(
    Entite $entite,
    Request $request,
    FormateurRepository $formateurRepo,
    SessionRepository $sessionRepo
  ): JsonResponse {
    // délégation
    return $this->calcMontant($entite, $request, $formateurRepo, $sessionRepo);
  }
}
