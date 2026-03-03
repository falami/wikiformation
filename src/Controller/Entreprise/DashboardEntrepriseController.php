<?php

declare(strict_types=1);

namespace App\Controller\Entreprise;

use App\Entity\{
  Entite,
  Utilisateur,
  Entreprise,
  Inscription,
  Session,
  EntrepriseDocument,
  Devis,
  Facture,
  ConventionContrat
};
use App\Form\Entreprise\EntrepriseDocumentType;
use App\Security\Voter\EntrepriseAccessVoter;
use App\Service\FileUploader;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use App\Security\Permission\TenantPermission;


#[Route('/entreprise/{entite}', name: 'app_entreprise_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::DASHBOARD_ENTREPRISE_MANAGE, subject: 'entite')]
final class DashboardEntrepriseController extends AbstractController
{
  public function __construct(
    private FileUploader $uploader,
    private UtilisateurEntiteManager $utilisateurEntiteManager,
  ) {}

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

  private function getEntrepriseUserOrFail(): Entreprise
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();
    $entreprise = $user->getEntreprise();
    if (!$entreprise) {
      throw $this->createAccessDeniedException('Aucune entreprise associée à ce compte.');
    }
    $this->denyAccessUnlessGranted(EntrepriseAccessVoter::VIEW_ENTREPRISE, $entreprise);
    return $entreprise;
  }

  #[Route('/dashboard', name: 'dashboard', methods: ['GET', 'POST'])]
  public function dashboard(Entite $entite, EM $em, Request $request): Response
  {

    $entreprise = $this->getEntrepriseUserOrFail();
    /** @var Utilisateur $user */
    $user = $this->getUser();
    // Form upload doc
    $doc = new EntrepriseDocument();
    $form = $this->createForm(EntrepriseDocumentType::class, $doc, [
      'action' => $this->generateUrl('app_entreprise_document_upload', ['entite' => $entite->getId()]),
    ]);
    $form->handleRequest($request);

    return $this->render('entreprise/dashboard.html.twig', [
      'entite' => $entite,
      'entreprise' => $entreprise,
      'formUpload' => $form->createView(),


    ]);
  }

  /** DataTables : salariés + sessions (via inscriptions de l’entreprise) */
  #[Route('/inscriptions/ajax', name: 'inscriptions_ajax', methods: ['POST'])]
  public function inscriptionsAjax(Entite $entite, EM $em, Request $request): JsonResponse
  {
    $entreprise = $this->getEntrepriseUserOrFail();

    $rows = $em->createQueryBuilder()
      ->select('i, s, fo, u')
      ->from(Inscription::class, 'i')
      ->join('i.session', 's')
      ->leftJoin('s.formation', 'fo')
      ->join('i.stagiaire', 'u')
      ->andWhere('i.entreprise = :e')->setParameter('e', $entreprise)
      ->andWhere('i.entite = :entite')->setParameter('entite', $entite)
      ->addOrderBy('u.nom', 'ASC')
      ->getQuery()->getResult();

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
        if ($now < $first) $statusBadge = '<span class="badge bg-info text-dark">À venir</span>';
        elseif ($now >= $first && $now <= $last) $statusBadge = '<span class="badge bg-success">En cours</span>';
        else $statusBadge = '<span class="badge bg-secondary">Terminé</span>';
      }

      $data[] = [
        'salarié' => trim(($u->getPrenom() ?? '') . ' ' . ($u->getNom() ?? '')) ?: ('#' . $u->getId()),
        'formation' => $s->getFormation()?->getTitre() ?? $s->getFormationIntituleLibre() ?? '—',
        'code' => $s->getCode(),
        'dates' => ($first && $last)
          ? ($first->format('d/m/Y H:i') . ' → ' . $last->format('d/m/Y H:i'))
          : '<span class="text-muted">—</span>',
        'statut' => $statusBadge,
        'actions' => sprintf(
          '<div class="d-flex gap-2 justify-content-end">
                       <button class="btn btn-sm btn-outline-secondary js-open-docs" data-session="%d">
                         <i class="bi bi-folder2-open"></i> Docs
                       </button>
                     </div>',
          (int)$s->getId()
        ),
        'firstStartIso' => $first?->format(\DateTimeInterface::ATOM),
        'lastEndIso' => $last?->format(\DateTimeInterface::ATOM),
        'sessionId' => (int)$s->getId(),
        'inscriptionId' => (int)$i->getId(),
      ];
    }

    return new JsonResponse([
      'data' => $data,
      'recordsTotal' => \count($data),
      'recordsFiltered' => \count($data),
      'draw' => (int)($request->attributes->get('draw') ?? 1),
    ]);
  }

  /** FullCalendar feed : toutes les journées de session (entreprise) */
  #[Route('/calendar/feed', name: 'calendar_feed', methods: ['GET'])]
  public function calendarFeed(Entite $entite, EM $em): JsonResponse
  {
    $entreprise = $this->getEntrepriseUserOrFail();

    $sessions = $em->createQueryBuilder()
      ->select('DISTINCT s, j, fo')
      ->from(Session::class, 's')
      ->join('s.jours', 'j')
      ->leftJoin('s.formation', 'fo')
      ->join(Inscription::class, 'i', 'WITH', 'i.session = s')
      ->andWhere('i.entreprise = :e')->setParameter('e', $entreprise)
      ->andWhere('s.entite = :entite')->setParameter('entite', $entite)
      ->addOrderBy('j.dateDebut', 'ASC')
      ->getQuery()->getResult();

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

  /** Docs : liste “tout en un” (devis/factures/conventions + docs entreprise uploadés) */
  #[Route('/documents/feed', name: 'documents_feed', methods: ['GET'])]
  public function documentsFeed(Entite $entite, EM $em, Request $request): JsonResponse
  {
    $entreprise = $this->getEntrepriseUserOrFail();
    $sessionId = $request->query->getInt('session', 0);

    // 1) Docs RH uploadés
    $qb = $em->createQueryBuilder()
      ->select('d')
      ->from(EntrepriseDocument::class, 'd')
      ->andWhere('d.entite = :entite')->setParameter('entite', $entite)
      ->andWhere('d.entreprise = :e')->setParameter('e', $entreprise);

    if ($sessionId > 0) {
      $qb->andWhere('d.session = :sid')->setParameter('sid', $sessionId);
    }
    $docs = $qb->addOrderBy('d.uploadedAt', 'DESC')->getQuery()->getResult();

    $basePath = rtrim($request->getBasePath(), '/');

    $out = [];

    foreach ($docs as $d) {
      /** @var EntrepriseDocument $d */
      $out[] = [
        'kind' => 'UPLOAD',
        'id' => $d->getId(),
        'label' => $d->getType()->label(),
        'name' => $d->getOriginalName(),
        'sessionId' => $d->getSession()?->getId(),
        'uploadedAt' => $d->getUploadedAt()->format('d/m/Y H:i'),
        'signedAt' => $d->getSignedAtEntreprise()?->format('d/m/Y H:i'),
        'url' => $basePath . '/uploads/entreprise/docs/' . $d->getFilename(),
        'signUrl' => $this->generateUrl('app_entreprise_document_sign', ['entite' => $entite->getId(), 'id' => $d->getId()]),
      ];
    }

    // 2) Conventions (déjà dans ton modèle)
    $convQb = $em->createQueryBuilder()
      ->select('c')
      ->from(ConventionContrat::class, 'c')
      ->andWhere('c.entite = :entite')->setParameter('entite', $entite)
      ->andWhere('c.entreprise = :e')->setParameter('e', $entreprise);

    if ($sessionId > 0) $convQb->andWhere('c.session = :sid')->setParameter('sid', $sessionId);

    foreach ($convQb->getQuery()->getResult() as $c) {
      /** @var ConventionContrat $c */
      if (!$c->getPdfPath()) continue;
      $out[] = [
        'kind' => 'CONVENTION',
        'id' => $c->getId(),
        'label' => 'Convention / Contrat',
        'name' => $c->getNumero(),
        'sessionId' => $c->getSession()?->getId(),
        'uploadedAt' => $c->getDateCreation()?->format('d/m/Y') ?? '',
        'signedAt' => $c->getDateSignatureEntreprise()?->format('d/m/Y') ?? null,
        'url' => $basePath . '/uploads/conventions/' . $c->getPdfPath(), // adapte à ton storage réel
        'signUrl' => null, // (tu as déjà signatureDataUrlEntreprise dans ConventionContrat)
      ];
    }

    // 3) Devis
    $devisQb = $em->createQueryBuilder()
      ->select('d')
      ->from(Devis::class, 'd')
      ->andWhere('d.entite = :entite')->setParameter('entite', $entite)
      ->andWhere('d.entrepriseDestinataire = :e')->setParameter('e', $entreprise);
    if ($sessionId > 0) {
      // via inscriptions -> session
      $devisQb->andWhere(':sid MEMBER OF d.inscriptions')
        ->setParameter('sid', $sessionId); // (si tu veux du 100% propre : fais une jointure Inscription)
    }

    foreach ($devisQb->getQuery()->getResult() as $d) {
      /** @var Devis $d */
      if (!$d->getPdfPath()) continue;
      $out[] = [
        'kind' => 'DEVIS',
        'id' => $d->getId(),
        'label' => 'Devis',
        'name' => (string)($d->getNumero() ?? ('Devis #' . $d->getId())),
        'sessionId' => null,
        'uploadedAt' => $d->getDateEmission()->format('d/m/Y'),
        'signedAt' => null,
        'url' => $basePath . '/uploads/devis/' . $d->getPdfPath(), // adapte
        'signUrl' => null,
      ];
    }

    // 4) Factures
    $factQb = $em->createQueryBuilder()
      ->select('f')
      ->from(Facture::class, 'f')
      ->andWhere('f.entite = :entite')->setParameter('entite', $entite)
      ->andWhere('f.entrepriseDestinataire = :e')->setParameter('e', $entreprise);

    foreach ($factQb->getQuery()->getResult() as $f) {
      /** @var Facture $f */
      $out[] = [
        'kind' => 'FACTURE',
        'id' => $f->getId(),
        'label' => 'Facture',
        'name' => $f->getNumero(),
        'sessionId' => null,
        'uploadedAt' => $f->getDateEmission()->format('d/m/Y'),
        'signedAt' => null,
        'url' => $basePath . '/factures/' . $f->getId() . '/pdf', // si tu as une route PDF
        'signUrl' => null,
      ];
    }

    // tri desc date (uploadAt string => pas parfait mais ok)
    return new JsonResponse(['data' => $out]);
  }

  /** Upload doc RH */
  #[Route('/documents/upload', name: 'document_upload', methods: ['POST'])]
  public function upload(Entite $entite, EM $em, Request $request): Response
  {
    $entreprise = $this->getEntrepriseUserOrFail();

    $doc = new EntrepriseDocument();
    $form = $this->createForm(EntrepriseDocumentType::class, $doc);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      /** @var Utilisateur $user */
      $user = $this->getUser();

      $file = $form->get('file')->getData();
      $stored = $this->uploader->upload($file, 'entreprise_docs');
      // => adapte ton FileUploader : retour filename, originalName, mimeType

      $doc->setEntite($entite);
      $doc->setEntreprise($entreprise);
      $doc->setCreateur($user);
      $doc->setFilename($stored['filename']);
      $doc->setOriginalName($stored['originalName'] ?? $file->getClientOriginalName());
      $doc->setMimeType($stored['mimeType'] ?? $file->getClientMimeType());

      $em->persist($doc);
      $em->flush();

      return $this->redirectToRoute('app_entreprise_dashboard', ['entite' => $entite->getId()]);
    }

    return $this->redirectToRoute('app_entreprise_dashboard', ['entite' => $entite->getId()]);
  }

  /** Signature doc RH (AJAX) */
  #[Route('/documents/{id}/sign', name: 'document_sign', methods: ['POST'])]
  public function sign(Entite $entite, EntrepriseDocument $doc, EM $em, Request $request): JsonResponse
  {
    $entreprise = $this->getEntrepriseUserOrFail();

    if ($doc->getEntite()?->getId() !== $entite->getId() || $doc->getEntreprise()?->getId() !== $entreprise->getId()) {
      return new JsonResponse(['success' => false, 'message' => 'Accès refusé'], 403);
    }

    $sig = (string)($request->request->get('signatureData') ?? '');
    if ($sig === '' || !str_starts_with($sig, 'data:image/png;base64,')) {
      return new JsonResponse(['success' => false, 'message' => 'Signature invalide'], 400);
    }

    $doc->setSignatureDataUrlEntreprise($sig);
    $doc->setSignedAtEntreprise(new \DateTimeImmutable());

    $em->flush();

    return new JsonResponse(['success' => true]);
  }
}
