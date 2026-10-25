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
use App\Service\FileUploader;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use App\Security\Permission\TenantPermission;
use App\Enum\StatusInscription;


#[Route('/entreprise/{entite}', name: 'app_entreprise_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::DASHBOARD_ENTREPRISE_MANAGE, subject: 'entite')]
final class DashboardEntrepriseController extends AbstractController
{
  public function __construct(
    private FileUploader $uploader,
    private UtilisateurEntiteManager $utilisateurEntiteManager,
    private \App\Service\Entreprise\EntreprisePortalContext $portal,
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

  private function getEntrepriseUserOrFail(Entite $entite): Entreprise
  {
    return $this->portal->resolve($this->getUser(), $entite);
  }

  #[Route('/dashboard', name: 'dashboard', methods: ['GET', 'POST'])]
  public function dashboard(Entite $entite, EM $em, Request $request, \App\Service\Session\CompanySessionFollowUp $followUp): Response
  {

    $entreprise = $this->getEntrepriseUserOrFail($entite);
    /** @var Utilisateur $user */
    $user = $this->getUser();
    // Form upload doc
    $doc = new EntrepriseDocument();
    $form = $this->createForm(EntrepriseDocumentType::class, $doc, [
      'action' => $this->generateUrl('app_entreprise_document_upload', ['entite' => $entite->getId(), 'entreprise' => $entreprise->getId()]),
    ]);
    $form->handleRequest($request);

    $candidates = $em->createQueryBuilder()->select('DISTINCT s')->from(Session::class, 's')
      ->innerJoin('s.inscriptions', 'i')
      ->andWhere('s.entite = :tenant AND i.entite = :tenant AND i.entreprise = :company')
      ->setParameter('tenant', $entite)->setParameter('company', $entreprise)->getQuery()->getResult();
    // Signature requests also concern upcoming sessions and companies without registrations yet.
    $pendingConventions = $em->createQueryBuilder()->select('c', 's')->from(ConventionContrat::class, 'c')
      ->innerJoin('c.session', 's')
      ->andWhere('c.entite = :tenant AND s.entite = :tenant AND c.entreprise = :company')
      ->andWhere("(c.dateSignatureEntreprise IS NULL OR c.signatureDataUrlEntreprise IS NULL OR c.signatureDataUrlEntreprise = '')")
      ->andWhere('s.status != :canceled')
      ->setParameter('tenant', $entite)->setParameter('company', $entreprise)
      ->setParameter('canceled', \App\Enum\StatusSession::CANCELED)
      ->orderBy('c.dateCreation', 'ASC')->getQuery()->getResult();
    $pendingSessions = [];
    $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris'));
    foreach ($candidates as $session) {
      $conventions = $em->getRepository(ConventionContrat::class)->findBy(['session'=>$session,'entite'=>$entite,'entreprise'=>$entreprise]);
      $pending = $followUp->summarize($session, $entreprise, $today, $conventions);
      if ($pending) $pendingSessions[] = $pending;
    }
    usort($pendingSessions, static fn($a,$b) => strcmp($b['lastDate'],$a['lastDate']));

    return $this->render('entreprise/dashboard.html.twig', [
      'pendingSessions' => $pendingSessions,
      'pendingConventions' => $pendingConventions,
      'entite' => $entite,
      'entreprisesDisponibles' => $this->portal->companies($this->getUser(), $entite),
      'entreprise' => $entreprise,
      'formUpload' => $form->createView(),


    ]);
  }

  /** DataTables : salariés + sessions (via inscriptions de l’entreprise) */
  #[Route('/inscriptions/ajax', name: 'inscriptions_ajax', methods: ['POST'])]
  public function inscriptionsAjax(Entite $entite, EM $em, Request $request): JsonResponse
  {
    $entreprise = $this->getEntrepriseUserOrFail($entite);

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
              <button class="btn btn-sm btn-outline-primary js-open-session-detail" data-session="%d">
                <i class="bi bi-eye"></i> Détail
              </button>
              <button class="btn btn-sm btn-outline-secondary js-open-docs" data-session="%d" data-inscription="%d">
                <i class="bi bi-folder2-open"></i> Docs
              </button>
          </div>',
          (int)$s->getId(),
          (int)$s->getId(),
          (int)$i->getId()
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
    $entreprise = $this->getEntrepriseUserOrFail($entite);

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
    $entreprise = $this->getEntrepriseUserOrFail($entite);
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
        'signUrl' => $this->generateUrl('app_entreprise_document_sign', ['entite' => $entite->getId(), 'entreprise' => $entreprise->getId(), 'id' => $d->getId()]),
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
        'label' => 'Convention',
        'name' => $c->getNumero(),
        'sessionId' => $c->getSession()?->getId(),
        'uploadedAt' => $c->getDateCreation()?->format('d/m/Y') ?? '',
        'signedAt' => $c->getDateSignatureEntreprise()?->format('d/m/Y') ?? null,
        'url' => $this->generateUrl('app_entreprise_documents_convention_pdf', ['entite' => $entite->getId(), 'entreprise' => $entreprise->getId(), 'id' => $c->getId()]),
        'reviewUrl' => $this->generateUrl('app_entreprise_convention_review', ['entite'=>$entite->getId(),'entreprise'=>$entreprise->getId(),'id'=>$c->getId()]),
        'signUrl' => null,
      ];
    }

    // 3) Devis
    $devisQb = $em->createQueryBuilder()
      ->select('d')
      ->from(Devis::class, 'd')
      ->andWhere('d.entite = :entite')->setParameter('entite', $entite)
      ->andWhere('d.entrepriseDestinataire = :e')->setParameter('e', $entreprise)
      ->andWhere('d.status != :draft')->setParameter('draft', \App\Enum\DevisStatus::DRAFT);
    if ($sessionId > 0) {
      $devisQb->innerJoin('d.inscriptions', 'di')->distinct()
        ->andWhere('di.session = :sid AND di.entite = :entite')
        ->setParameter('sid', $sessionId);
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
        'url' => $this->generateUrl('app_entreprise_documents_devis_pdf', ['entite' => $entite->getId(), 'entreprise' => $entreprise->getId(), 'id' => $d->getId()]),
        'signUrl' => null,
      ];
    }

    // 4) Factures
    $factQb = $em->createQueryBuilder()
      ->select('f')
      ->from(Facture::class, 'f')
      ->andWhere('f.entite = :entite')->setParameter('entite', $entite)
      ->andWhere('f.entrepriseDestinataire = :e')->setParameter('e', $entreprise);

    if ($sessionId > 0) {
      $factQb->innerJoin('f.inscriptions', 'fi')->distinct()
        ->andWhere('fi.session = :sid AND fi.entite = :entite')
        ->setParameter('sid', $sessionId);
    }

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
        'url' => $this->generateUrl('app_entreprise_documents_facture_pdf', ['entite'=>$entite->getId(), 'entreprise'=>$entreprise->getId(), 'id'=>$f->getId()]),
        'signUrl' => null,
      ];
    }

    $criteria=['entite'=>$entite,'entreprise'=>$entreprise];
    if ($sessionId>0) $criteria['session']=$sessionId;
    foreach ($em->getRepository(Inscription::class)->findBy($criteria,['id'=>'DESC']) as $inscription) {
      $session=$inscription->getSession();$person=$inscription->getStagiaire();
      if (!$session || $session->getEntite()?->getId()!==$entite->getId() || !$person) continue;
      $out[]=['kind'=>'CONVOCATION','id'=>$inscription->getId(),'label'=>'Convocation stagiaire',
        'name'=>$person->getPrenom().' '.$person->getNom().' · '.$session->getCode(),
        'sessionId'=>$session->getId(),'uploadedAt'=>'','signedAt'=>null,'signUrl'=>null,
        'url'=>$this->generateUrl('app_entreprise_documents_convocation_pdf',['entite'=>$entite->getId(),'entreprise'=>$entreprise->getId(),'id'=>$inscription->getId()])];
    }

    // tri desc date (uploadAt string => pas parfait mais ok)
    return new JsonResponse(['data' => $out]);
  }

  /** Upload doc RH */
  #[Route('/documents/upload', name: 'document_upload', methods: ['POST'])]
  public function upload(Entite $entite, EM $em, Request $request): Response
  {
    $entreprise = $this->getEntrepriseUserOrFail($entite);

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

      return $this->redirectToRoute('app_entreprise_dashboard', ['entite' => $entite->getId(), 'entreprise' => $entreprise->getId()]);
    }

    return $this->redirectToRoute('app_entreprise_dashboard', ['entite' => $entite->getId(), 'entreprise' => $entreprise->getId()]);
  }

  /** Signature doc RH (AJAX) */
  #[Route('/documents/{id}/sign', name: 'document_sign', methods: ['POST'])]
  public function sign(Entite $entite, EntrepriseDocument $doc, EM $em, Request $request): JsonResponse
  {
    $entreprise = $this->getEntrepriseUserOrFail($entite);

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

  #[Route('/session/{session}/detail', name: 'session_detail_ajax', methods: ['GET'])]
  public function sessionDetailAjax(Entite $entite, Session $session, EM $em): JsonResponse
  {
      $entreprise = $this->getEntrepriseUserOrFail($entite);

      if ($session->getEntite()?->getId() !== $entite->getId()) {
          return new JsonResponse([
              'success' => false,
              'message' => 'Session introuvable.'
          ], 404);
      }

      // ✅ sécurité : on vérifie que cette entreprise a bien au moins une inscription sur cette session
      $count = (int) $em->createQueryBuilder()
          ->select('COUNT(i.id)')
          ->from(Inscription::class, 'i')
          ->andWhere('i.session = :session')->setParameter('session', $session)
          ->andWhere('i.entreprise = :entreprise')->setParameter('entreprise', $entreprise)
          ->getQuery()
          ->getSingleScalarResult();

      if ($count <= 0) {
          return new JsonResponse([
              'success' => false,
              'message' => 'Accès refusé à cette session.'
          ], 403);
      }

      // ✅ on recharge proprement les inscriptions de l’entreprise pour cette session
      $inscriptions = $em->createQueryBuilder()
          ->select('i, u')
          ->from(Inscription::class, 'i')
          ->join('i.stagiaire', 'u')
          ->andWhere('i.session = :session')->setParameter('session', $session)
          ->andWhere('i.entreprise = :entreprise')->setParameter('entreprise', $entreprise)
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

          $isAbsent = ($status === StatusInscription::ABSENT);
          $isPresent = in_array($status, [
              StatusInscription::CONFIRME,
              StatusInscription::EN_COURS,
              StatusInscription::TERMINE,
          ], true);

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
              'statut' => $status?->label() ?? '-',
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
          /** @var \App\Entity\SessionJour $jour */
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
              $site->getAdresse(),
              $site->getComplement(),
              trim(($site->getCodePostal() ?? '') . ' ' . ($site->getVille() ?? '')),
              $site->getPays(),
          ]);

          $adresse = implode(', ', $adresseParts);

          if ($site->getLatitude() !== null && $site->getLongitude() !== null) {
              $mapQuery = $site->getLatitude() . ',' . $site->getLongitude();
          } elseif ($adresse !== '') {
              $mapQuery = $adresse;
          }
      }

      $sessionMontant = $session->getMontantCents();
      if ($sessionMontant === null && method_exists($session, 'getTarifEffectifCents')) {
          $sessionMontant = $session->getTarifEffectifCents();
      }

      return new JsonResponse([
          'success' => true,
          'session' => [
              'id' => $session->getId(),
              'code' => $session->getCode(),
              'formation' => $session->getFormation()?->getTitre() ?? $session->getFormationIntituleLibre() ?? '—',
              'statut' => method_exists($session->getStatus(), 'label')
                  ? $session->getStatus()->label()
                  : $session->getStatus()->value,
              'capacite' => $session->getCapacite(),
              'montant' => $sessionMontant !== null
                  ? number_format($sessionMontant / 100, 2, ',', ' ') . ' €'
                  : '—',
              'siteNom' => $site?->getNom(),
              'adresse' => $adresse,
              'latitude' => $site?->getLatitude(),
              'longitude' => $site?->getLongitude(),
              'mapQuery' => $mapQuery,
              'formateur' => $formateur
                  ? trim(($formateur->getPrenom() ?? '') . ' ' . ($formateur->getNom() ?? ''))
                  : null,
              'jours' => $jours,
              'participants' => $participants,
              'documentsArchiveUrl' => !empty($inscriptions) ? $this->generateUrl('app_entreprise_documents_inscription_archive', [
                  'entite' => $entite->getId(), 'entreprise' => $entreprise->getId(),
                  'id' => $inscriptions[0]->getId(), 'scope' => 'session',
              ]) : null,
              'participantsCount' => count($participants),
              'presentCount' => $presentCount,
              'absentCount' => $absentCount,
          ],
      ]);
  }
}
