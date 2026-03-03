<?php

namespace App\Controller\Administrateur;

use App\Entity\{Devis, Entite, Utilisateur, Facture, Entreprise, LigneFacture, LigneDevis};
use App\Enum\DevisStatus;
use App\Enum\FactureStatus;
use App\Service\Pdf\PdfManager;
use App\Service\Sequence\DevisNumberGenerator;
use App\Form\Administrateur\DevisType;
use App\Form\Administrateur\EntrepriseModalType;
use App\Service\Sequence\FactureNumberGenerator;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\HttpFoundation\{Request, Response};
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Entity\EmailLog;
use App\Entity\ProspectInteraction;
use App\Enum\InteractionChannel;
use App\Service\Email\MailerManager;
use App\Security\Permission\TenantPermission;



#[Route('/administrateur/{entite}/devis', name: 'app_administrateur_devis_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::DEVIS_MANAGE, subject: 'entite')]
class DevisController extends AbstractController
{

  public function __construct(
    private UtilisateurEntiteManager $utilisateurEntiteManager,
    private MailerManager $mailer,
    private ?PdfManager $pdf = null,
  ) {}


  #[Route('', name: 'index', methods: ['GET'])]
  public function devis(Entite $entite): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();
    return $this->render('administrateur/devis/index.html.twig', [
      'entite' => $entite,


    ]);
  }

  #[Route('/nouveau', name: 'new', methods: ['GET', 'POST'])]
  public function devisNew(
    Entite $entite,
    Request $req,
    EM $em,
    DevisNumberGenerator $gen
  ): Response {

    /** @var Utilisateur $user */
    $user = $this->getUser();

    $d = new Devis();
    $d->setEntite($entite);
    $d->setCreateur($user);
    $d->setDateEmission(new \DateTimeImmutable());
    $d->setDevise('EUR');

    $form = $this->createForm(DevisType::class, $d, [
      'entite' => $entite,
    ])->handleRequest($req);


    if ($form->isSubmitted() && $form->isValid()) {
      // 🔐 Exclusivité serveur : Prospect / Destinataire / Entreprise
      // ✅ Règle serveur : Prospect exclusif, entreprise + personne autorisées
      $this->normalizeDestinataires($d);



      if (!$d->getNumero()) {
        $d->setNumero($gen->nextForEntite($entite->getId(), (int) $d->getDateEmission()->format('Y')));
      }


      // recalcul HT/TVA/TTC depuis lignes (comme tu voulais le faire sur Facture)
      $this->recalcDevis($d);


      foreach ($d->getLignes() as $ligne) {
        if (!$ligne->getCreateur()) {
          $ligne->setCreateur($user);
        }
        if (!$ligne->getEntite()) {
          $ligne->setEntite($entite);
        }
        // (optionnel) sécurité : rattacher la facture si jamais
        if ($ligne->getDevis() !== $d) {
          $ligne->setDevis($d);
        }
      }

      $em->persist($d);
      $em->flush();

      $this->addFlash('success', 'Devis créé.');
      return $this->redirectToRoute('app_administrateur_devis_index', ['entite' => $entite->getId()]);
    }

    return $this->render('administrateur/devis/form.html.twig', [
      'form' => $form,
      'title' => 'Nouveau devis',
      'entite' => $entite,
    ]);
  }

  private function recalcDevis(Devis $d): void
  {
    // 🔒 Sécurité minimale : empêcher valeurs négatives
    $d->setMontantHtCents(max(0, (int) $d->getMontantHtCents()));
    $d->setMontantTvaCents(max(0, (int) $d->getMontantTvaCents()));
    $d->setMontantTtcCents(max(
      $d->getMontantHtCents() + $d->getMontantTvaCents(),
      (int) $d->getMontantTtcCents()
    ));
  }


  #[Route('/{id}/to-facture', name: 'to_facture', methods: ['POST'])]
  public function devisToFacture(
    Entite $entite,
    Devis $devis,
    Request $request,
    EM $em,
    FactureNumberGenerator $factureGen,
  ): RedirectResponse {


    // 1) sécurité : le devis doit appartenir à l'entité
    if ($devis->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createAccessDeniedException('Devis non autorisé pour cette entité.');
    }

    // 2) CSRF
    if (!$this->isCsrfTokenValid('devis_to_facture_' . $devis->getId(), (string) $request->request->get('_token'))) {
      throw $this->createAccessDeniedException('CSRF invalide.');
    }

    // 3) déjà transformé ?
    if ($devis->getFactureCreee()) {
      $this->addFlash('warning', 'Ce devis a déjà été transformé en facture.');
      return $this->redirectToRoute('app_administrateur_devis_index', ['entite' => $entite->getId()]);
    }

    // 4) statut facturable ?
    if (!$devis->getStatus()->canBeInvoiced()) {
      $this->addFlash('warning', 'Ce devis ne peut pas être transformé en facture (statut : ' . $devis->getStatus()->label() . ').');
      return $this->redirectToRoute('app_administrateur_devis_index', ['entite' => $entite->getId()]);
    }
    /** @var Utilisateur $user */
    $user = $this->getUser();

    // 5) recalcul devis côté serveur (sécurité)
    $this->recalcDevis($devis);

    // 6) créer la facture
    $f = new Facture();
    $f->setEntite($entite);
    $f->setCreateur($user);
    $f->setDateEmission(new \DateTimeImmutable());
    $f->setNumero($factureGen->nextForEntite($entite->getId()));
    $f->setStatus(FactureStatus::DUE);

    // ----- NOUVELLES DONNÉES / ALIGNEMENT -----
    $f->setDevise($devis->getDevise());

    // Destinataire : personne OU entreprise
    // (tu as ajouté entrepriseDestinataire, donc on copie les deux)
    $f->setDestinataire($devis->getDestinataire());
    if (method_exists($f, 'setEntrepriseDestinataire')) {
      $f->setEntrepriseDestinataire($devis->getEntrepriseDestinataire());
    }

    foreach ($devis->getInscriptions() as $inscription) {
      if (method_exists($f, 'addInscription')) {
        $f->addInscription($inscription);
      }
    }

    // Remise globale (si tes entités la portent aussi côté Devis)
    if (method_exists($devis, 'getRemiseGlobalePourcent') && method_exists($f, 'setRemiseGlobalePourcent')) {
      $f->setRemiseGlobalePourcent($devis->getRemiseGlobalePourcent());
    }
    if (method_exists($devis, 'getRemiseGlobaleMontantCents') && method_exists($f, 'setRemiseGlobaleMontantCents')) {
      $f->setRemiseGlobaleMontantCents($devis->getRemiseGlobaleMontantCents());
    }

    // Montants
    $f->setMontantHtCents($devis->getMontantHtCents());
    $f->setMontantTvaCents($devis->getMontantTvaCents());
    $f->setMontantTtcCents($devis->getMontantTtcCents());

    // 7) copier les lignes (via addLigne => setFacture auto)
    foreach ($devis->getLignes() as $ld) {
      $lf = new LigneFacture();
      $lf->setCreateur($user);
      $lf->setEntite($entite);
      $lf->setLabel($ld->getLabel());
      $lf->setQte($ld->getQte());
      $lf->setPuHtCents($ld->getPuHtCents());
      $lf->setTva($ld->getTva());

      // Remise par ligne (si tes lignes de devis ont ces champs)
      if (method_exists($ld, 'getRemisePourcent') && method_exists($lf, 'setRemisePourcent')) {
        $lf->setRemisePourcent($ld->getRemisePourcent());
      }
      if (method_exists($ld, 'getRemiseMontantCents') && method_exists($lf, 'setRemiseMontantCents')) {
        $lf->setRemiseMontantCents($ld->getRemiseMontantCents());
      }

      $f->addLigne($lf);
    }

    // (optionnel) si Facture est liée à Formation dans ton modèle
    if (method_exists($devis, 'getFormation') && method_exists($f, 'setFormation')) {
      $f->setFormation($devis->getFormation());
    }

    // 8) lier devis -> facture + statut
    $devis->setFactureCreee($f);
    $devis->setStatus(DevisStatus::INVOICED);

    $em->persist($f);
    $em->flush();

    $this->addFlash('success', 'Devis transformé en facture.');

    return $this->redirectToRoute('app_administrateur_facture_edit', [
      'entite' => $entite->getId(),
      'id' => $f->getId(),
    ]);
  }


  #[Route('/ajax', name: 'ajax', methods: ['POST'])]
  public function devisAjax(Entite $entite, Request $request, EM $em): JsonResponse
  {
    $start   = max(0, $request->request->getInt('start', 0));
    $length  = $request->request->getInt('length', 10);
    if ($length <= 0) {
      $length = 10;
    }

    $searchV = (string)($request->request->all('search')['value'] ?? '');
    $order   = $request->request->all('order') ?? [];
    $statusFilter = (string)$request->request->get('statusFilter', 'all');

    $map = [
      0 => 'd.id',
      1 => 'd.numero',
      2 => 'u.email',
      3 => 'd.montantTtcCents',
      4 => 'd.status',
      5 => 'd.dateEmission',
    ];

    $qb = $em->getRepository(Devis::class)->createQueryBuilder('d')
      ->leftJoin('d.destinataire', 'u')->addSelect('u')
      ->leftJoin('d.factureCreee', 'f')->addSelect('f')
      ->andWhere('d.entite = :entite')
      ->setParameter('entite', $entite);

    // ✅ recordsTotal (sans search/filtre)
    $recordsTotal = (int)(clone $qb)
      ->select('COUNT(DISTINCT d.id)')
      ->resetDQLPart('orderBy')
      ->getQuery()->getSingleScalarResult();

    // ✅ Search
    if ($searchV !== '') {
      $qb->andWhere('
            d.numero LIKE :s
            OR u.email LIKE :s
            OR u.nom LIKE :s
            OR u.prenom LIKE :s
        ')->setParameter('s', '%' . $searchV . '%');
    }

    // ✅ Filtre custom statut
    switch ($statusFilter) {
      case 'draft':
        $qb->andWhere('d.status = :st')->setParameter('st', DevisStatus::DRAFT);
        break;
      case 'sent':
        $qb->andWhere('d.status = :st')->setParameter('st', DevisStatus::SENT);
        break;
      case 'accepted':
        $qb->andWhere('d.status = :st')->setParameter('st', DevisStatus::ACCEPTED);
        break;
      case 'invoiced':
        $qb->andWhere('d.status = :st')->setParameter('st', DevisStatus::INVOICED);
        break;
      case 'canceled':
        $qb->andWhere('d.status = :st')->setParameter('st', DevisStatus::CANCELED);
        break;
      default:
        // all
        break;
    }

    // ✅ recordsFiltered (avec search + filtre)
    $recordsFiltered = (int)(clone $qb)
      ->select('COUNT(DISTINCT d.id)')
      ->resetDQLPart('orderBy')
      ->getQuery()->getSingleScalarResult();

    // ✅ Tri DataTables
    $orderColIdx = isset($order[0]['column']) ? (int)$order[0]['column'] : 0;
    $orderDir    = (isset($order[0]['dir']) && strtolower($order[0]['dir']) === 'asc') ? 'ASC' : 'DESC';
    $orderBy     = $map[$orderColIdx] ?? 'd.id';

    $rows = $qb->orderBy($orderBy, $orderDir)
      ->setFirstResult($start)
      ->setMaxResults($length)
      ->getQuery()->getResult();

    $data = array_map(function (Devis $d) use ($entite) {
      $label = '—';

      if ($d->getProspect()) {
        $p = $d->getProspect();
        $label = trim(($p->getPrenom() ?? '') . ' ' . ($p->getNom() ?? '')) ?: ($p->getEmail() ?? 'Prospect');
      } else {
        $parts = [];

        if ($d->getEntrepriseDestinataire()) {
          $parts[] = $d->getEntrepriseDestinataire()->getRaisonSociale();
        }
        if ($d->getDestinataire()) {
          $u = $d->getDestinataire();
          $parts[] = trim(($u->getPrenom() ?? '') . ' ' . ($u->getNom() ?? '')) ?: ($u->getEmail() ?? 'Client');
        }

        $label = $parts ? implode(' — ', $parts) : '—';
      }



      return [
        'numero' => $d->getNumero() ?: '—',
        'dest' => $label,
        'ttc'    => number_format(($d->getMontantTtcCents() ?? 0) / 100, 2, ',', ' ') . ' €',

        // si tu utilises déjà un badge twig
        'status' => $this->renderView('administrateur/devis/_status_badge.html.twig', [
          'd' => $d
        ]),

        'actions' => $this->renderView('administrateur/devis/_actions.html.twig', [
          'd' => $d,
          'entite' => $entite
        ]),
      ];
    }, $rows);

    return new JsonResponse([
      'draw'            => $request->request->getInt('draw', 1),
      'recordsTotal'    => $recordsTotal,
      'recordsFiltered' => $recordsFiltered,
      'data'            => $data,
    ]);
  }


  #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
  public function devisEdit(
    Entite $entite,
    Devis $id,
    Request $req,
    EM $em,
    DevisNumberGenerator $gen
  ): Response {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    if ($id->getEntite()->getId() !== $entite->getId()) {
      throw $this->createAccessDeniedException('Devis non autorisé pour cette entité.');
    }

    $form = $this->createForm(DevisType::class, $id, [
      'entite' => $entite,
    ])->handleRequest($req);

    if ($form->isSubmitted() && $form->isValid()) {

      // ✅ Règle serveur : Prospect exclusif, entreprise + personne autorisées
      $this->normalizeDestinataires($id);


      // ✅ Filet de sécurité : si pas de numéro, on le génère
      if (!$id->getNumero()) {
        $year = (int) $id->getDateEmission()->format('Y');
        $id->setNumero($gen->nextForEntite($entite->getId(), $year));
      }

      $this->recalcDevis($id);


      foreach ($id->getLignes() as $ligne) {
        if (!$ligne->getCreateur()) {
          $ligne->setCreateur($user);
        }
        if (!$ligne->getEntite()) {
          $ligne->setEntite($entite);
        }
        // (optionnel) sécurité : rattacher la facture si jamais
        if ($ligne->getDevis() !== $id) {
          $ligne->setDevis($id);
        }
      }

      $em->flush();

      $this->addFlash('success', 'Devis mis à jour.');
      return $this->redirectToRoute('app_administrateur_devis_index', ['entite' => $entite->getId()]);
    }

    return $this->render('administrateur/devis/form.html.twig', [
      'form' => $form,
      'title' => 'Éditer devis',
      'entite' => $entite,
    ]);
  }


  #[Route('/kpis', name: 'kpis', methods: ['GET'])]
  public function devisKpis(Entite $entite, EM $em, Request $request): JsonResponse
  {
    $statusFilter = (string)$request->query->get('statusFilter', 'all');
    $since = (new \DateTimeImmutable())->sub(new \DateInterval('P30D'));

    // petit helper pour appliquer le même filtre à plusieurs QB
    $applyStatus = function (\Doctrine\ORM\QueryBuilder $qb, string $alias) use ($statusFilter) {
      return match ($statusFilter) {
        'draft'    => $qb->andWhere("$alias.status = :st")->setParameter('st', DevisStatus::DRAFT),
        'sent'     => $qb->andWhere("$alias.status = :st")->setParameter('st', DevisStatus::SENT),
        'accepted' => $qb->andWhere("$alias.status = :st")->setParameter('st', DevisStatus::ACCEPTED),
        'invoiced' => $qb->andWhere("$alias.status = :st")->setParameter('st', DevisStatus::INVOICED),
        'canceled' => $qb->andWhere("$alias.status = :st")->setParameter('st', DevisStatus::CANCELED),
        default    => $qb, // all
      };
    };

    // Nb devis (global, filtré)
    $qbCount = $em->createQueryBuilder()
      ->select('COUNT(d.id)')
      ->from(Devis::class, 'd')
      ->andWhere('d.entite = :e')->setParameter('e', $entite);

    $applyStatus($qbCount, 'd');
    $count = (int)$qbCount->getQuery()->getSingleScalarResult();

    // Total TTC (global, filtré)
    $qbTtc = $em->createQueryBuilder()
      ->select('COALESCE(SUM(d.montantTtcCents),0)')
      ->from(Devis::class, 'd')
      ->andWhere('d.entite = :e')->setParameter('e', $entite);

    $applyStatus($qbTtc, 'd');
    $ttc = (int)$qbTtc->getQuery()->getSingleScalarResult();

    // 30 derniers jours (filtré)
    $qbLast30 = $em->createQueryBuilder()
      ->select('COUNT(d2.id) AS cnt, COALESCE(SUM(d2.montantTtcCents),0) AS sumCents')
      ->from(Devis::class, 'd2')
      ->andWhere('d2.entite = :e')->setParameter('e', $entite)
      ->andWhere('d2.dateEmission >= :since')->setParameter('since', $since);

    $applyStatus($qbLast30, 'd2');
    $last30 = $qbLast30->getQuery()->getSingleResult();

    return new JsonResponse([
      'count' => $count,
      'ttcCents' => $ttc,
      'last30Count' => (int)($last30['cnt'] ?? 0),
      'last30TtcCents' => (int)($last30['sumCents'] ?? 0),
    ]);
  }

  private function renderDevisPdfHtml(Entite $entite, Devis $devis): string
  {
    return $this->renderView('pdf/devis.html.twig', [
      'entite' => $entite,
      'devis'  => $devis,
    ]);
  }

  #[Route('/{id}/pdf', name: 'pdf', methods: ['GET'])]
  public function devisPdf(Entite $entite, Devis $id): Response
  {
    if (!$this->pdf) {
      throw $this->createNotFoundException('Service PDF non disponible.');
    }

    if ($id->getEntite()->getId() !== $entite->getId()) {
      throw $this->createAccessDeniedException('Devis non autorisé pour cette entité.');
    }

    $html = $this->renderDevisPdfHtml($entite, $id);
    $fileName = sprintf('DEVIS-%s', $id->getNumero() ?: $id->getId());

    return $this->pdf->createPortrait($html, $fileName);
  }

  #[Route('/entreprises/modal', name: 'entreprise_modal', methods: ['GET'])]
  public function entrepriseModal(Entite $entite, Request $request, EM $em): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();
    // ✅ Form “léger” en AJAX (pas besoin de persist ici)
    $entreprise = new Entreprise();
    $entreprise->setCreateur($user);
    $entreprise->setEntite($entite);

    // si Entreprise est liée à Entite : set ici
    // $entreprise->setEntite($entite);

    // ⚠️ Si tu n’as pas EntrepriseModalType, je te donne plus bas un mini form alternatif
    $form = $this->createForm(EntrepriseModalType::class, $entreprise, [
      'entite' => $entite, // optionnel si ton form filtre des trucs
    ]);

    return $this->render('administrateur/devis/_entreprise_modal.html.twig', [
      'entite' => $entite,
      'formEntreprise' => $form->createView(),
    ]);
  }

  #[Route('/entreprises/modal', name: 'entreprise_modal_submit', methods: ['POST'])]
  public function entrepriseModalSubmit(Entite $entite, Request $request, EM $em): JsonResponse
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();
    $entreprise = new Entreprise();
    $entreprise->setCreateur($user);
    $entreprise->setEntite($entite);

    // si Entreprise est liée à Entite : set ici
    // $entreprise->setEntite($entite);

    $form = $this->createForm(EntrepriseModalType::class, $entreprise, [
      'entite' => $entite,
    ]);
    $form->handleRequest($request);

    if (!$form->isSubmitted()) {
      return new JsonResponse(['ok' => false, 'message' => 'Form non soumis'], 400);
    }

    if (!$form->isValid()) {
      // ✅ on renvoie le HTML du modal avec les erreurs
      $html = $this->renderView('administrateur/devis/_entreprise_modal.html.twig', [
        'entite' => $entite,
        'formEntreprise' => $form->createView(),
      ]);

      return new JsonResponse([
        'ok' => false,
        'html' => $html,
      ], 422);
    }

    $em->persist($entreprise);
    $em->flush();

    return new JsonResponse([
      'ok' => true,
      'id' => $entreprise->getId(),
      'text' => $entreprise->getRaisonSociale(),
    ]);
  }


  #[Route('/entreprises/create-ajax', name: 'entreprise_create_ajax', methods: ['POST'])]
  public function entrepriseCreateAjax(Entite $entite, Request $request, EM $em): JsonResponse
  {
    $name = trim((string)$request->request->get('name', ''));
    if ($name === '') {
      return new JsonResponse(['error' => 'Nom manquant'], 400);
    }

    /** @var Utilisateur $user */
    $user = $this->getUser();
    $e = new Entreprise();
    $e->setRaisonSociale($name);
    $e->setCreateur($user);
    $e->setEntite($entite);

    // si Entreprise est liée à Entite, fais-le ici
    // $e->setEntite($entite);

    $em->persist($e);
    $em->flush();

    return new JsonResponse([
      'id' => $e->getId(),
      'text' => $e->getRaisonSociale(),
    ]);
  }


  #[Route('/{id}/duplicate', name: 'duplicate', methods: ['POST'])]
  public function devisDuplicate(
    Entite $entite,
    Devis $devis,
    Request $request,
    EM $em,
    DevisNumberGenerator $gen
  ): RedirectResponse {

    // sécurité : le devis doit appartenir à l'entité
    if ($devis->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createAccessDeniedException('Devis non autorisé pour cette entité.');
    }

    // CSRF
    if (!$this->isCsrfTokenValid('devis_duplicate_' . $devis->getId(), (string) $request->request->get('_token'))) {
      throw $this->createAccessDeniedException('CSRF invalide.');
    }
    /** @var Utilisateur $user */
    $user = $this->getUser();

    // clone "métier" (on ne clone PAS l'ID / relations ORM directement)
    $copy = new Devis();
    $copy->setEntite($entite);
    $copy->setCreateur($user);
    $copy->setDateEmission(new \DateTimeImmutable());
    $copy->setDevise($devis->getDevise() ?: 'EUR');

    // destinataire : prospect / user / entreprise
    if (method_exists($copy, 'setProspect')) {
      $copy->setProspect($devis->getProspect());
    }
    $copy->setDestinataire($devis->getDestinataire());
    if (method_exists($copy, 'setEntrepriseDestinataire')) {
      $copy->setEntrepriseDestinataire($devis->getEntrepriseDestinataire());
    }
    $this->normalizeDestinataires($copy);


    // autres champs éventuels
    if (method_exists($devis, 'getFormation') && method_exists($copy, 'setFormation')) {
      $copy->setFormation($devis->getFormation());
    }
    if (method_exists($devis, 'getRemiseGlobalePourcent') && method_exists($copy, 'setRemiseGlobalePourcent')) {
      $copy->setRemiseGlobalePourcent($devis->getRemiseGlobalePourcent());
    }
    if (method_exists($devis, 'getRemiseGlobaleMontantCents') && method_exists($copy, 'setRemiseGlobaleMontantCents')) {
      $copy->setRemiseGlobaleMontantCents($devis->getRemiseGlobaleMontantCents());
    }

    // statut + numéro
    $copy->setStatus(DevisStatus::DRAFT);
    $copy->setNumero($gen->nextForEntite($entite->getId()));

    // ne pas garder une facture liée
    if (method_exists($copy, 'setFactureCreee')) {
      $copy->setFactureCreee(null);
    }

    // copier inscriptions si tu en as côté devis
    foreach ($devis->getInscriptions() as $inscription) {
      if (method_exists($copy, 'addInscription')) {
        $copy->addInscription($inscription);
      }
    }

    // copier les lignes (⚠️ adapte la classe si tes lignes de devis ne sont pas LigneFacture)
    foreach ($devis->getLignes() as $ld) {
      $lc = new LigneDevis();
      $lc->setCreateur($user);
      $lc->setEntite($entite);
      $lc->setLabel($ld->getLabel());
      $lc->setQte($ld->getQte());
      $lc->setPuHtCents($ld->getPuHtCents());
      $lc->setTva($ld->getTva());

      if (method_exists($ld, 'getRemisePourcent') && method_exists($lc, 'setRemisePourcent')) {
        $lc->setRemisePourcent($ld->getRemisePourcent());
      }
      if (method_exists($ld, 'getRemiseMontantCents') && method_exists($lc, 'setRemiseMontantCents')) {
        $lc->setRemiseMontantCents($ld->getRemiseMontantCents());
      }

      // si ton Devis a bien addLigne() qui fait le setDevis() derrière
      $copy->addLigne($lc);
    }

    // recalcul montants (comme tu fais ailleurs)
    $this->recalcDevis($copy);

    $em->persist($copy);
    $em->flush();

    $this->addFlash('success', 'Devis dupliqué.');

    // au choix : rediriger vers edit du nouveau devis
    return $this->redirectToRoute('app_administrateur_devis_edit', [
      'entite' => $entite->getId(),
      'id'     => $copy->getId(),
    ]);
  }


  #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
  public function devisDelete(
    Entite $entite,
    Devis $devis,
    Request $request,
    EM $em
  ): RedirectResponse {

    // sécurité : le devis doit appartenir à l'entité
    if ($devis->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createAccessDeniedException('Devis non autorisé pour cette entité.');
    }

    // CSRF
    if (!$this->isCsrfTokenValid('devis_delete_' . $devis->getId(), (string) $request->request->get('_token'))) {
      throw $this->createAccessDeniedException('CSRF invalide.');
    }

    // garde-fous : pas de suppression si déjà facturé
    if ($devis->getFactureCreee() || $devis->getStatus() === DevisStatus::INVOICED) {
      $this->addFlash('warning', 'Impossible de supprimer un devis déjà facturé.');
      return $this->redirectToRoute('app_administrateur_devis_index', ['entite' => $entite->getId()]);
    }

    $em->remove($devis);
    $em->flush();

    $this->addFlash('success', 'Devis supprimé.');
    return $this->redirectToRoute('app_administrateur_devis_index', ['entite' => $entite->getId()]);
  }




  #[Route('/{id}/send', name: 'send', methods: ['POST'])]
  public function sendDevis(
    Entite $entite,
    Devis $devis,
    Request $request,
    EM $em,
  ): RedirectResponse {
    // Sécurité entité
    if ($devis->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createAccessDeniedException('Devis non autorisé pour cette entité.');
    }

    // CSRF
    if (!$this->isCsrfTokenValid('devis_send_' . $devis->getId(), (string) $request->request->get('_token'))) {
      throw $this->createAccessDeniedException('CSRF invalide.');
    }

    // ---- Déterminer le destinataire email ----
    $to = $this->resolveDevisRecipientEmail($devis);
    if (!$to) {
      $this->addFlash('danger', 'Impossible d’envoyer : aucun email destinataire (prospect / client / entreprise).');
      return $this->redirectToRoute('app_administrateur_devis_edit', [
        'entite' => $entite->getId(),
        'id' => $devis->getId(),
      ]);
    }
    /** @var Utilisateur $user */
    $user = $this->getUser();

    // Sujet
    $subject = sprintf('Devis %s', $devis->getNumero() ?: ('#' . $devis->getId()));

    // HTML email (template)
    $htmlBody = $this->renderView('emails/devis_send.html.twig', [
      'entite' => $entite,
      'devis' => $devis,
      'subject' => $subject,
      // optionnel si tu veux afficher un message custom
      // 'message' => '<p>...</p>',
    ]);

    $textBody = trim(strip_tags($htmlBody));

    // ---- Générer PDF devis ----
    // ⚠️ Ici on génère le PDF comme "string" (contenu binaire) pour l’attacher en PJ
    // Si ton PdfManager ne renvoie pas le PDF en string, tu peux utiliser la variante "sendHtmlWithFile" (voir plus bas).
    $pdfBinary = $this->buildDevisPdfBinary($entite, $devis);

    $filename = sprintf('DEVIS-%s.pdf', $devis->getNumero() ?: $devis->getId());

    // ---- Envoi email via TON service ----
    try {
      $this->mailer->sendHtmlWithAttachment(
        $entite,
        $to,
        $subject,
        $htmlBody,
        $textBody,
        $pdfBinary,
        $filename,
        'application/pdf'
      );

      $status = 'SENT';
      $error = null;
    } catch (\Throwable $e) {
      $status = 'FAILED';
      $error = $e->getMessage();
    }


    // ---- MAJ statut devis ----
    // (si tu veux : uniquement si le devis était en DRAFT)
    if ($devis->getStatus() === DevisStatus::DRAFT) {
      $devis->setStatus(DevisStatus::SENT);
    }

    // ---- Créer EmailLog ----
    $emailLog = new EmailLog();
    $emailLog->setCreateur($user);
    $emailLog->setEntite($entite);
    $emailLog->setProspect($devis->getProspect()); // null si pas un prospect
    $emailLog->setToEmail($to);
    $emailLog->setSubject($subject);
    $emailLog->setBodyHtmlSnapshot($htmlBody);
    $emailLog->setSentAt(new \DateTimeImmutable());
    $emailLog->setStatus($status);
    $emailLog->setErrorMessage($error);


    // ✅ ton champ ajouté : lien vers le devis (ex: ManyToOne devis)
    // adapte le nom exact: setDevis / setQuote / setDevisEnvoye etc.
    if (method_exists($emailLog, 'setDevis')) {
      $emailLog->setDevis($devis);
    }

    $em->persist($emailLog);

    // ---- ProspectInteraction (si devis lié à un prospect) ----
    if ($devis->getProspect()) {
      $interaction = new ProspectInteraction();
      $interaction->setCreateur($user);
      $interaction->setEntite($entite);
      $interaction->setProspect($devis->getProspect());
      $interaction->setActor($this->getUser());
      $interaction->setChannel(InteractionChannel::QUOTE);
      $interaction->setTitle('Email envoyé : Devis ' . ($devis->getNumero() ?: ('#' . $devis->getId())));
      $interaction->setContent('Devis envoyé à ' . $to);
      $interaction->setOccurredAt(new \DateTimeImmutable());
      $interaction->setEmailLog($emailLog);
      $interaction->setDevis($devis);

      $em->persist($interaction);

      // (optionnel) touche le prospect
      if (method_exists($devis->getProspect(), 'touch')) {
        $devis->getProspect()->touch();
      }
    }

    $em->flush();

    $this->addFlash('success', 'Devis envoyé par email.');

    return $this->redirectToRoute('app_administrateur_devis_edit', [
      'entite' => $entite->getId(),
      'id' => $devis->getId(),
    ]);
  }

  private function resolveDevisRecipientEmail(Devis $d): ?string
  {
    // 1) Prospect
    if ($d->getProspect() && $d->getProspect()->getEmail()) {
      return (string) $d->getProspect()->getEmail();
    }

    // 2) Entreprise destinataire
    if ($d->getEntrepriseDestinataire()) {
      $e = $d->getEntrepriseDestinataire();

      // adapte si ton Entreprise a un autre champ (emailContact, etc.)
      if (method_exists($e, 'getEmail') && $e->getEmail()) {
        return (string) $e->getEmail();
      }
    }

    // 3) Utilisateur destinataire
    if ($d->getDestinataire() && $d->getDestinataire()->getEmail()) {
      return (string) $d->getDestinataire()->getEmail();
    }

    return null;
  }

  /**
   * Retourne le PDF en binaire (string) pour l'attacher en PJ.
   * ✅ Compatible avec TON PdfManager (createPortrait renvoie Response avec body = PDF).
   */
  private function buildDevisPdfBinary(Entite $entite, Devis $devis): string
  {
    if (!$this->pdf) {
      throw $this->createNotFoundException('Service PDF non disponible.');
    }

    // HTML de ton template PDF
    $html = $this->renderDevisPdfHtml($entite, $devis);

    // PdfManager -> Response (body = PDF)
    $resp = $this->pdf->createPortrait($html, 'DEVIS-' . ($devis->getNumero() ?: $devis->getId()));

    // Response->getContent() = binaire PDF
    $content = $resp->getContent();

    if (!is_string($content) || $content === '') {
      throw new \RuntimeException('Impossible de générer le PDF du devis (contenu vide).');
    }

    return $content;
  }


  /**
   * Règles serveur:
   * - Prospect EXCLUSIF (si prospect => on vide entreprise + personne)
   * - Sinon: entreprise + personne autorisées ensemble
   */
  private function normalizeDestinataires(Devis $d): void
  {
    if ($d->getProspect()) {
      $d->setDestinataire(null);
      $d->setEntrepriseDestinataire(null);
    }
  }
}
