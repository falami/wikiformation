<?php

declare(strict_types=1);

namespace App\Controller\Administrateur;

use App\Entity\{Devis, Entite, Facture, Prospect, Entreprise, Inscription, Utilisateur, UtilisateurEntite, ProspectInteraction};
use App\Enum\{DevisStatus, FactureStatus, StatusSession, StatusInscription};
use Doctrine\ORM\QueryBuilder;
use App\Service\Email\MailerManager;
use Symfony\Component\String\ByteString;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\EmailTemplateRepository;
use App\Form\Administrateur\EntrepriseType;
use Symfony\Component\Routing\Attribute\Route;
use App\Form\Administrateur\UtilisateurType;
use App\Repository\ProspectInteractionRepository;
use App\Form\Administrateur\ProspectInteractionType;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use App\Service\Billing\BillingGuard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\HttpFoundation\{JsonResponse, RedirectResponse, Request, Response};
use App\Security\Permission\TenantPermission;
use App\Exception\BillingQuotaExceededException;
use App\Service\Billing\EntitlementService;
use App\Entity\Billing\Plan;


#[Route('/administrateur/{entite}/utilisateur', name: 'app_administrateur_utilisateur_')]
#[IsGranted(TenantPermission::UTILISATEUR_MANAGE, subject: 'entite')]
final class UtilisateurController extends AbstractController
{
  public function __construct(
      private readonly UtilisateurEntiteManager $utilisateurEntiteManager,
      private readonly MailerManager $mailerManager,
      private readonly EntityManagerInterface $em,
      private readonly ProspectInteractionRepository $interactionRepo,
      private readonly EmailTemplateRepository $emailTemplateRepository,
      private readonly UserPasswordHasherInterface $passwordHasher,
      private readonly BillingGuard $billingGuard,
      private readonly EntitlementService $entitlementService,
  ) {}

  /* ===================== LIST ===================== */

  #[Route('', name: 'index', methods: ['GET'])]
  public function index(Entite $entite): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    return $this->render('administrateur/utilisateur/index.html.twig', [
      'entite' => $entite,


    ]);
  }

  #[Route('/ajax', name: 'ajax', methods: ['POST'])]
  public function ajax(Entite $entite, Request $request): JsonResponse
  {
    $draw   = $request->request->getInt('draw', 0);
    $start  = max(0, $request->request->getInt('start', 0));
    $length = $request->request->getInt('length', 10);

    // DataTables peut envoyer -1 (= tout). On borne pour éviter les abus.
    if ($length <= 0 || $length > 500) {
      $length = 10;
    }

    // DataTables search (global)
    $search  = (array) $request->request->all('search');
    $searchV = trim((string) ($search['value'] ?? ''));

    // Filtres custom
    $rolesFilter    = (string) $request->request->get('rolesFilter', 'all');     // ex: TENANT_FORMATEUR
    $verifiedFilter = (string) $request->request->get('verifiedFilter', 'all');  // '1' | '0' | 'all'
    $lockedFilter   = (string) $request->request->get('lockedFilter', 'all');    // '1' | '0' | 'all'
    $searchName     = trim((string) $request->request->get('searchName', ''));

    // Tri DataTables
    $order      = (array) $request->request->all('order');
    $orderDir   = strtolower((string) ($order[0]['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
    $orderColIdx = (int) ($order[0]['column'] ?? 0);

    // Mapping des colonnes DataTables -> champs Doctrine (uniquement des champs sûrs)
    $orderMap = [
      0 => 'u.id',
      1 => 'u.nom',
      2 => 'u.prenom',
      3 => 'u.email',
    ];
    $orderBy = $orderMap[$orderColIdx] ?? 'u.id';

    $applyFilters = function (QueryBuilder $qb, string $uAlias, string $ueAlias) use (
      $rolesFilter,
      $verifiedFilter,
      $lockedFilter,
      $searchName,
      $searchV
    ): void {
      if ($searchV !== '') {
        $qb->andWhere("($uAlias.nom LIKE :dt_q OR $uAlias.prenom LIKE :dt_q OR $uAlias.email LIKE :dt_q)")
          ->setParameter('dt_q', '%' . $searchV . '%');
      }

      if ($searchName !== '') {
        $qb->andWhere("($uAlias.nom LIKE :fb_q OR $uAlias.prenom LIKE :fb_q OR $uAlias.email LIKE :fb_q)")
          ->setParameter('fb_q', '%' . $searchName . '%');
      }

      if ($verifiedFilter === '1' || $verifiedFilter === '0') {
        $qb->andWhere("$uAlias.isVerified = :fb_verified")
          ->setParameter('fb_verified', $verifiedFilter === '1');
      }

      // Locked: verified OU inscriptions > 0
      if ($lockedFilter === '1') {
        $qb->andWhere("($uAlias.isVerified = true OR SIZE($uAlias.inscriptions) > 0)");
      } elseif ($lockedFilter === '0') {
        $qb->andWhere("($uAlias.isVerified = false AND SIZE($uAlias.inscriptions) = 0)");
      }

      if ($rolesFilter !== '' && $rolesFilter !== 'all') {
        // rolesFilter doit valoir ex: "TENANT_FORMATEUR"
        $qb->andWhere("JSON_CONTAINS($ueAlias.roles, :roleJson) = 1")
          ->setParameter('roleJson', json_encode($rolesFilter));
      }
    };

    // 1) Query principale (data)
    $qb = $this->em->createQueryBuilder()
      ->select('u', 'ue') // fetch join => ue sera hydraté dans u.utilisateurEntites
      ->from(Utilisateur::class, 'u')
      ->innerJoin('u.utilisateurEntites', 'ue', 'WITH', 'ue.entite = :entite')
      ->setParameter('entite', $entite);

    $applyFilters($qb, 'u', 'ue');

    // 2) recordsTotal (sans filtres)
    $qbTotal = $this->em->createQueryBuilder()
      ->select('COUNT(DISTINCT u_t.id)')
      ->from(Utilisateur::class, 'u_t')
      ->innerJoin('u_t.utilisateurEntites', 'ue_t', 'WITH', 'ue_t.entite = :entite')
      ->setParameter('entite', $entite);

    $recordsTotal = (int) $qbTotal->getQuery()->getSingleScalarResult();

    // 3) recordsFiltered (avec filtres)
    $qbFiltered = $this->em->createQueryBuilder()
      ->select('COUNT(DISTINCT u_f.id)')
      ->from(Utilisateur::class, 'u_f')
      ->innerJoin('u_f.utilisateurEntites', 'ue_f', 'WITH', 'ue_f.entite = :entite')
      ->setParameter('entite', $entite);

    $applyFilters($qbFiltered, 'u_f', 'ue_f');
    $recordsFiltered = (int) $qbFiltered->getQuery()->getSingleScalarResult();

    // 4) Pagination + tri
    $rows = $qb
      ->orderBy($orderBy, $orderDir)
      ->addOrderBy('u.id', 'DESC')
      ->setFirstResult($start)
      ->setMaxResults($length)
      ->getQuery()
      ->getResult();

    // 5) Formatage

    $data = [];
    foreach ($rows as $row) {

      // Doctrine renvoie souvent directement l'entité racine (Utilisateur)
      $u = $row instanceof Utilisateur ? $row : ($row[0] ?? null);
      if (!$u instanceof Utilisateur) {
        continue;
      }

      // ✅ retrouve l'UE pour CETTE entité (même si $row n'est pas un tableau)
      $ue = null;
      foreach ($u->getUtilisateurEntites() as $ueItem) {
        if ($ueItem->getEntite()?->getId() === $entite->getId()) {
          $ue = $ueItem;
          break;
        }
      }

      $locked = $this->isLockedUtilisateur($u);

      // ✅ renvoyer des CODES pour que le JS fasse les badges
      $rolesCodes = $ue ? $ue->getRoles() : [UtilisateurEntite::TENANT_STAGIAIRE];

      $data[] = [
        'id'           => $u->getId(),
        'nom'          => $u->getNom() ?: '—',
        'prenom'       => $u->getPrenom() ?: '—',
        'email'        => $u->getEmail() ?: '—',
        'roles'        => implode(', ', $rolesCodes),  // <-- IMPORTANT
        'inscriptions' => $u->getInscriptions()->count(),
        'verified'     => $u->isVerified() ? 'Oui' : 'Non',
        'locked'       => $locked ? 'Oui' : 'Non',
        'actions'      => $this->renderView('administrateur/utilisateur/_actions.html.twig', [
          'entite'      => $entite,
          'utilisateur' => $u,
          'locked'      => $locked,
          'ue'          => $ue,
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

  /* ===================== ADD / EDIT ===================== */

  #[Route('/ajouter', name: 'ajouter', methods: ['GET', 'POST'])]
  #[Route('/modifier/{id}', name: 'modifier', methods: ['GET', 'POST'])]
  public function addEdit(Entite $entite, Request $request, ?Utilisateur $utilisateur = null): Response
  {


    /** @var Utilisateur $user */
    $user = $this->getUser();

    $isEdit = (bool) $utilisateur;

    if (!$utilisateur) {
      $utilisateur = new Utilisateur();
      $utilisateur->setEntite($entite);
      $utilisateur->setIsVerified(false);
      $utilisateur->setCreateur($user);
    } else {
      $this->assertUtilisateurInEntite($entite, $utilisateur);
    }

    $locked = $isEdit ? $this->isLockedUtilisateur($utilisateur) : false;

    // snapshots si locked
    $origEmail    = $utilisateur->getEmail();
    $origNom      = $utilisateur->getNom();
    $origPrenom   = $utilisateur->getPrenom();
    $origSociete  = $utilisateur->getSociete();
    $origVerified = (bool) $utilisateur->isVerified();


    $ue = $this->em->getRepository(UtilisateurEntite::class)->findOneBy([
      'entite' => $entite,
      'utilisateur' => $utilisateur,
    ]);


    $canSetHighRoles = $this->em->getRepository(UtilisateurEntite::class)->canSetHighRoles($this->getUser(), $entite);

    $origTenantRoles = $ue?->getRoles() ?? [UtilisateurEntite::TENANT_STAGIAIRE];

    $form = $this->createForm(UtilisateurType::class, $utilisateur, [
      'entite' => $entite,
      'ueRoles' => $ue?->getRoles() ?? [UtilisateurEntite::TENANT_STAGIAIRE],
      'can_set_high_roles' => $canSetHighRoles,
    ]);






    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      try {
          if ($locked) {
              $utilisateur->setEmail((string) $origEmail);
              $utilisateur->setNom((string) $origNom);
              $utilisateur->setPrenom((string) $origPrenom);
              $utilisateur->setSociete($origSociete);
              $utilisateur->setIsVerified($origVerified);
          }

          $ue = $this->em->getRepository(UtilisateurEntite::class)->findOneBy([
              'entite' => $entite,
              'utilisateur' => $utilisateur,
          ]);

          $isNewUtilisateurEntite = !$ue;
          if (!$ue) {
              $ue = new UtilisateurEntite();
              $ue->setCreateur($user);
              $ue->setUtilisateur($utilisateur);
              $ue->setEntite($entite);
              $this->em->persist($ue);
          }

          $currentRoles = $ue->getRoles();
          $currentStatus = $ue->getStatus();

          $rolesFromForm = (array) ($form->has('ueRoles') ? $form->get('ueRoles')->getData() : []);
          if (!$canSetHighRoles) {
              $rolesFromForm = array_values(array_filter(
                  $rolesFromForm,
                  fn($r) => !$this->isStaffRole((string) $r)
              ));
          }

          if ($locked) {
              $staffOrig    = array_values(array_filter($origTenantRoles, fn($r) => $this->isStaffRole((string) $r)));
              $safeNonStaff = array_values(array_filter($rolesFromForm, fn($r) => !$this->isStaffRole((string) $r)));

              $rolesFinal = array_values(array_unique(array_merge($safeNonStaff, $staffOrig)));
          } else {
              $rolesFinal = $rolesFromForm;
          }

          $rolesFinal = $rolesFinal ?: [UtilisateurEntite::TENANT_STAGIAIRE];

          // si tu as un champ status plus tard dans le form, remplace ici
          $futureStatus = UtilisateurEntite::STATUS_ACTIVE;

          $this->billingGuard->assertCanTransitionUtilisateurEntite(
              entite: $entite,
              currentRoles: $isNewUtilisateurEntite ? [] : $currentRoles,
              currentStatus: $isNewUtilisateurEntite ? UtilisateurEntite::STATUS_INVITED : $currentStatus,
              futureRoles: $rolesFinal,
              futureStatus: $futureStatus,
              excludeUtilisateurEntiteId: $ue->getId(),
          );

          $ue->setRoles($rolesFinal);
          $ue->ensureCouleur();

          if ($form->has('photo')) {
            /** @var UploadedFile|null $photoFile */
            $photoFile = $form->get('photo')->getData();
            $removePhoto = $form->has('removePhoto') && $form->get('removePhoto')->getData() === '1';

            $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/photos/utilisateur';
            $oldPhoto = $utilisateur->getPhoto();

            if ($removePhoto && $oldPhoto) {
                $oldPath = $uploadDir . '/' . $oldPhoto;
                if (is_file($oldPath)) {
                    @unlink($oldPath);
                }
                $utilisateur->setPhoto(null);
            }

            if ($photoFile instanceof UploadedFile) {
                $newName = bin2hex(random_bytes(16)) . '.' . ($photoFile->guessExtension() ?: 'jpg');

                $photoFile->move($uploadDir, $newName);

                if ($oldPhoto) {
                    $oldPath = $uploadDir . '/' . $oldPhoto;
                    if (is_file($oldPath)) {
                        @unlink($oldPath);
                    }
                }

                $utilisateur->setPhoto($newName);
            }
        }

          $hasTenantEntreprise = $ue->hasRole(UtilisateurEntite::TENANT_ENTREPRISE);

          if ($hasTenantEntreprise && $form->has('entrepriseData')) {
              /** @var Entreprise|null $edata */
              $edata = $form->get('entrepriseData')->getData();

              $raison = $edata?->getRaisonSociale();
              $hasAnyValue = $edata && (
                  $raison
                  || $edata->getSiret()
                  || $edata->getEmailFacturation()
                  || $edata->getEmail()
                  || $edata->getAdresse()
                  || $edata->getCodePostal()
              );

              if ($hasAnyValue) {
                  $entreprise = $utilisateur->getEntreprise();

                  if (!$entreprise && $raison) {
                      $entreprise = $this->em->getRepository(Entreprise::class)->findOneBy([
                          'entite' => $entite,
                          'raisonSociale' => $raison,
                      ]);
                  }

                  if (!$entreprise) {
                      $entreprise = new Entreprise();
                      $entreprise->setCreateur($user);
                      $entreprise->setEntite($entite);
                      $this->em->persist($entreprise);
                  }

                  $entreprise->setRaisonSociale($edata->getRaisonSociale());
                  $entreprise->setSiret($edata->getSiret());
                  $entreprise->setEmailFacturation($edata->getEmailFacturation());
                  $entreprise->setEmail($edata->getEmail());
                  $entreprise->setNumeroTVA($edata->getNumeroTVA());

                  $entreprise->setAdresse($edata->getAdresse());
                  $entreprise->setComplement($edata->getComplement());
                  $entreprise->setCodePostal($edata->getCodePostal());
                  $entreprise->setVille($edata->getVille());
                  $entreprise->setDepartement($edata->getDepartement());
                  $entreprise->setRegion($edata->getRegion());
                  $entreprise->setPays($edata->getPays());

                  $utilisateur->setEntreprise($entreprise);

                  if ($form->has('entreprise') && $form->get('entreprise')->getData() instanceof Entreprise) {
                      /** @var Entreprise $selected */
                      $selected = $form->get('entreprise')->getData();

                      if ($selected->getEntite()?->getId() === $entite->getId()) {
                          $utilisateur->setEntreprise($selected);
                      }
                  }
              }
          }

          if (!$isEdit) {
              $plainPassword = ByteString::fromRandom(20)->toString();
              $hashedPassword = $this->passwordHasher->hashPassword($utilisateur, $plainPassword);
              $utilisateur->setPassword($hashedPassword);
              $this->initResetToken($utilisateur);
          }

          $this->em->persist($utilisateur);
          $this->em->flush();

          $this->addFlash('success', $isEdit ? 'Utilisateur modifié.' : 'Utilisateur créé (reset mot de passe à envoyer).');

          return $this->redirectToRoute('app_administrateur_utilisateur_index', [
              'entite' => $entite->getId(),
          ]);
      } catch (BillingQuotaExceededException $e) {
          $this->addBillingLimitFlash($entite, $e);

          return $this->redirectToRoute('app_administrateur_utilisateur_index', [
              'entite' => $entite->getId(),
          ]);
      }
    }

    // Form modal "création entreprise" (si tu l’utilises encore)
    $entrepriseModal = new Entreprise();
    $entrepriseModal->setCreateur($user);
    $entrepriseModal->setEntite($entite);

    $entrepriseForm = $this->createForm(EntrepriseType::class, $entrepriseModal, [
      'action' => $this->generateUrl('app_administrateur_utilisateur_create_entreprise_ajax', ['entite' => $entite->getId()]),
      'method' => 'POST',
      'entite' => $entite,
    ]);


    $ue = $this->em->getRepository(UtilisateurEntite::class)
      ->findOneBy(['entite' => $entite, 'utilisateur' => $utilisateur]);

    return $this->render('administrateur/utilisateur/form.html.twig', [
      'entite'            => $entite,
      'utilisateur'       => $utilisateur,
      'modeEdition'       => $isEdit,
      'locked'            => $locked,
      'form'              => $form->createView(),
      'entrepriseForm'    => $entrepriseForm->createView(),

      'ueRoles'           => $ue?->getRoles() ?? [UtilisateurEntite::TENANT_STAGIAIRE],
      'ueCouleur'         => $ue?->getCouleur(),
    ]);
  }



  #[Route('/entreprise/{id}/json', name: 'entreprise_json', methods: ['GET'])]
  public function entrepriseJson(Entite $entite, Entreprise $entreprise): JsonResponse
  {
    // sécurité : entreprise appartient à l'entite
    if ($entreprise->getEntite()?->getId() !== $entite->getId()) {
      return $this->json(['ok' => false], 404);
    }

    return $this->json([
      'ok' => true,
      'data' => [
        'raisonSociale' => $entreprise->getRaisonSociale(),
        'siret' => $entreprise->getSiret(),
        'emailFacturation' => $entreprise->getEmailFacturation(),
        'email' => $entreprise->getEmail(),
        'numeroTVA' => $entreprise->getNumeroTVA(),
        'adresse' => $entreprise->getAdresse(),
        'complement' => $entreprise->getComplement(),
        'codePostal' => $entreprise->getCodePostal(),
        'ville' => $entreprise->getVille(),
        'departement' => $entreprise->getDepartement(),
        'region' => $entreprise->getRegion(),
        'pays' => $entreprise->getPays(),
        'representantId' => $entreprise->getRepresentant()?->getId(),
      ],
    ]);
  }



  /* ===================== RESET PASSWORD ===================== */

  #[Route('/reset-password/{id}', name: 'reset_password', methods: ['POST'])]
  public function resetPassword(Entite $entite, Utilisateur $utilisateur, Request $request): RedirectResponse
  {
    $this->assertUtilisateurInEntite($entite, $utilisateur);

    if (!$this->isCsrfTokenValid('reset_password_' . $utilisateur->getId(), (string) $request->request->get('_token'))) {
      $this->addFlash('danger', 'Token CSRF invalide.');
      return $this->redirectToRoute('app_administrateur_utilisateur_index', ['entite' => $entite->getId()]);
    }

    $this->initResetToken($utilisateur);
    $this->em->flush();

    // adapte si besoin: nom exact de ta méthode
    $this->mailerManager->sendResetPassword($utilisateur, $entite);

    $this->addFlash('success', 'Email de réinitialisation envoyé.');
    return $this->redirectToRoute('app_administrateur_utilisateur_index', ['entite' => $entite->getId()]);
  }



  /* ===================== SEND ACCOUNT VALIDATION EMAIL ===================== */

  #[Route('/send-activation/{id}', name: 'send_activation', methods: ['POST'])]
  public function sendActivationEmail(Entite $entite, Utilisateur $utilisateur, Request $request): RedirectResponse
  {
    $this->assertUtilisateurInEntite($entite, $utilisateur);

    if (!$this->isCsrfTokenValid('send_activation_' . $utilisateur->getId(), (string) $request->request->get('_token'))) {
      $this->addFlash('danger', 'Token CSRF invalide.');
      return $this->redirectToRoute('app_administrateur_utilisateur_index', ['entite' => $entite->getId()]);
    }

    // ✅ On (re)génère un token + expiration plus longue (activation)
    $this->initActivationToken($utilisateur);
    $this->em->flush();

    $this->mailerManager->sendAccountCreatedValidation($utilisateur, $entite);

    $this->addFlash('success', 'Email d’activation envoyé.');
    return $this->redirectToRoute('app_administrateur_utilisateur_index', ['entite' => $entite->getId()]);
  }

  /** Token d’activation : plus long que reset */
  private function initActivationToken(Utilisateur $u): void
  {
    $u->setResetToken(ByteString::fromRandom(32)->toString());
    $u->setResetTokenExpiresAt(new \DateTimeImmutable('+48 hours')); // ✅ tu ajustes
  }


  /* ===================== DELETE USER ===================== */

  // ⚠️ J’ai corrigé en POST + CSRF (le GET delete, c’est dangereux)
  #[Route('/supprimer/{id}', name: 'supprimer', methods: ['POST'])]
  public function delete(Entite $entite, Utilisateur $utilisateur, Request $request): RedirectResponse
  {
    $this->assertUtilisateurInEntite($entite, $utilisateur);

    if (!$this->isCsrfTokenValid('delete_user_' . $utilisateur->getId(), (string) $request->request->get('_token'))) {
      $this->addFlash('danger', 'Token CSRF invalide.');
      return $this->redirectToRoute('app_administrateur_utilisateur_index', ['entite' => $entite->getId()]);
    }

    if ($this->isLockedUtilisateur($utilisateur)) {
      $this->addFlash('warning', 'Utilisateur identifié : suppression bloquée.');
      return $this->redirectToRoute('app_administrateur_utilisateur_index', ['entite' => $entite->getId()]);
    }

    $id = $utilisateur->getId();
    $this->em->remove($utilisateur);
    $this->em->flush();

    $this->addFlash('success', 'Utilisateur #' . $id . ' supprimé.');
    return $this->redirectToRoute('app_administrateur_utilisateur_index', ['entite' => $entite->getId()]);
  }

  /* ===================== ENTREPRISE AJAX ===================== */

  #[Route('/entreprise/create-ajax', name: 'create_entreprise_ajax', methods: ['POST'])]
  public function createEntrepriseAjax(Entite $entite, Request $request): JsonResponse
  {
      /** @var Utilisateur $user */
      $user = $this->getUser();
      $entreprise = new Entreprise();
      $entreprise->setCreateur($user);
      $entreprise->setEntite($entite);

      $form = $this->createForm(EntrepriseType::class, $entreprise, [
          'csrf_protection' => true,
          'entite' => $entite,
      ]);
      $form->handleRequest($request);

      if (!$form->isSubmitted()) {
          return $this->json(['ok' => false, 'message' => 'Form non soumis'], 400);
      }

      if (!$form->isValid()) {
          $errors = [];
          foreach ($form->getErrors(true) as $e) {
              $errors[] = $e->getMessage();
          }

          return $this->json(['ok' => false, 'errors' => $errors], 422);
      }

      try {
          $this->billingGuard->assertCanCreateEntreprise($entite);

          $this->em->persist($entreprise);
          $this->em->flush();

          return $this->json([
              'ok'    => true,
              'id'    => $entreprise->getId(),
              'label' => (string) ($entreprise->getRaisonSociale() ?? 'Entreprise'),
          ]);
      } catch (BillingQuotaExceededException $e) {
          return $this->json([
              'ok' => false,
              'limitReached' => true,
              'message' => $e->getMessage(),
              'redirect' => $this->generateUrl('app_administrateur_billing', [
                  'entite' => $entite->getId(),
              ]),
          ], 409);
      }
  }

  /* ===================== SHOW / INTERACTIONS ===================== */

  #[Route('/{id}', name: 'show', methods: ['GET'])]
  public function show(Entite $entite, Utilisateur $utilisateur): Response
  {
    $this->assertUtilisateurInEntite($entite, $utilisateur);

    /** @var Utilisateur $user */
    $user = $this->getUser();

    $linkedProspect = $this->em->getRepository(Prospect::class)->findOneBy(
      ['entite' => $entite, 'linkedUser' => $utilisateur],
      ['updatedAt' => 'DESC']
    );

    $interactions = $this->interactionRepo->findForUtilisateurInEntite($entite, $utilisateur, 500);

    $interaction = new ProspectInteraction();
    $interaction->setCreateur($user);
    $interaction->setEntite($entite);
    $interaction->setActor($user);

    if ($linkedProspect) {
      $interaction->setProspect($linkedProspect);
    } else {
      $interaction->setUtilisateur($utilisateur);
    }

    $form = $this->createForm(ProspectInteractionType::class, $interaction, [
      'entite' => $entite,
      'current_user' => $this->getUser(), // 👈 ici
    ]);

    $tpls = $this->emailTemplateRepository->findActiveForProspects($entite);


    // ===================== KPI =====================

    // Devis (même logique que devisAjax)
    $kpiDevisQb = $this->em->getRepository(Devis::class)->createQueryBuilder('d')
      ->select('COUNT(DISTINCT d.id) as cnt', 'COALESCE(SUM(d.montantTtcCents), 0) as sumCents')
      ->andWhere('d.entite = :entite')->setParameter('entite', $entite)
      ->leftJoin('d.inscriptions', 'i')
      ->leftJoin('i.stagiaire', 's')
      ->andWhere('(d.destinataire = :u OR s = :u)')->setParameter('u', $utilisateur);

    $kpiDevis = $kpiDevisQb->getQuery()->getSingleResult();

    // Factures (même logique que facturesAjax)
    $kpiFactQb = $this->em->getRepository(Facture::class)->createQueryBuilder('f')
      ->select('COUNT(DISTINCT f.id) as cnt', 'COALESCE(SUM(f.montantTtcCents), 0) as sumCents')
      ->andWhere('f.entite = :entite')->setParameter('entite', $entite)
      ->leftJoin('f.inscriptions', 'i2')
      ->leftJoin('i2.stagiaire', 's2')
      ->andWhere('(f.destinataire = :u OR s2 = :u)')->setParameter('u', $utilisateur);

    $kpiFact = $kpiFactQb->getQuery()->getSingleResult();

    // Inscriptions (rapide)
    $kpiInsQb = $this->em->getRepository(Inscription::class)->createQueryBuilder('ins')
      ->select('COUNT(ins.id) as cnt')
      ->join('ins.session', 'sess')
      ->andWhere('ins.stagiaire = :u')->setParameter('u', $utilisateur)
      ->andWhere('sess.entite = :entite')->setParameter('entite', $entite);

    $kpiIns = $kpiInsQb->getQuery()->getSingleResult();

    // Interactions : tu as déjà $interactions (liste), sinon tu peux faire un COUNT aussi.
    // Là on réutilise directement :
    $kpiInteractionsCount = \is_countable($interactions) ? \count($interactions) : 0;

    // Pack KPI final
    $kpis = [
      'interactions' => (int) $kpiInteractionsCount,
      'devisCount'   => (int) ($kpiDevis['cnt'] ?? 0),
      'devisSum'     => (int) ($kpiDevis['sumCents'] ?? 0),
      'factCount'    => (int) ($kpiFact['cnt'] ?? 0),
      'factSum'      => (int) ($kpiFact['sumCents'] ?? 0),
      'inscriptions' => (int) ($kpiIns['cnt'] ?? 0),
    ];


    return $this->render('administrateur/utilisateur/show.html.twig', [
      'entite'            => $entite,
      'u'                 => $utilisateur,
      'linkedProspect'    => $linkedProspect,
      'interactions'      => $interactions,
      'emailTemplates' => $tpls,
      'interactionForm'   => $form->createView(),
      'kpis' => $kpis,


    ]);
  }

  #[Route('/{id}/interaction/create', name: 'interaction_create', methods: ['POST'])]
  public function createInteraction(Entite $entite, Utilisateur $utilisateur, Request $request): RedirectResponse
  {
    $this->assertUtilisateurInEntite($entite, $utilisateur);

    /** @var Utilisateur $user */
    $user = $this->getUser();

    $linkedProspect = $this->em->getRepository(Prospect::class)->findOneBy(
      ['entite' => $entite, 'linkedUser' => $utilisateur],
      ['updatedAt' => 'DESC']
    );

    $interaction = new ProspectInteraction();
    $interaction->setCreateur($user);
    $interaction->setEntite($entite);
    $interaction->setActor($user);

    if ($linkedProspect) {
      $interaction->setProspect($linkedProspect);
    } else {
      $interaction->setUtilisateur($utilisateur);
    }

    $form = $this->createForm(ProspectInteractionType::class, $interaction, [
      'entite' => $entite,
      'current_user' => $this->getUser(), // 👈 ici
    ]);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      if ($linkedProspect && method_exists($linkedProspect, 'touch')) {
        $linkedProspect->touch();
      }
      $this->em->persist($interaction);
      $this->em->flush();

      $this->addFlash('success', 'Interaction ajoutée.');
    } else {
      $this->addFlash('danger', 'Formulaire invalide.');
    }

    // ✅ correction: pas de param "utilisateurEntite" dans l’URL
    return $this->redirectToRoute('app_administrateur_utilisateur_show', [
      'entite' => $entite->getId(),
      'id'     => $utilisateur->getId(),
    ]);
  }

  #[Route('/{id}/interaction/{interaction}/edit', name: 'interaction_edit', methods: ['GET', 'POST'])]
  public function editInteraction(
    Entite $entite,
    Utilisateur $utilisateur,
    ProspectInteraction $interaction,
    Request $request
  ): Response {
    $this->assertUtilisateurInEntite($entite, $utilisateur);

    /** @var Utilisateur $user */
    $user = $this->getUser();

    $p = $interaction->getProspect();
    $u = $interaction->getUtilisateur();

    $ok = false;
    if ($p) {
      $ok = ($p->getEntite()?->getId() === $entite->getId())
        && ($p->getLinkedUser()?->getId() === $utilisateur->getId());
    } elseif ($u) {
      $ok = ($u->getId() === $utilisateur->getId());
    }

    if (!$ok) {
      throw $this->createNotFoundException();
    }

    $form = $this->createForm(ProspectInteractionType::class, $interaction, [
      'entite' => $entite,
      'current_user' => $this->getUser(), // 👈 ici
    ]);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      if ($p && method_exists($p, 'touch')) {
        $p->touch();
      }
      $this->em->flush();

      $this->addFlash('success', 'Interaction mise à jour.');

      return $this->redirectToRoute('app_administrateur_utilisateur_show', [
        'entite' => $entite->getId(),
        'id'     => $utilisateur->getId(),
      ]);
    }

    return $this->render('administrateur/utilisateur/interaction_edit.html.twig', [
      'entite'            => $entite,
      'u'                 => $utilisateur,
      'interaction'       => $interaction,
      'form'              => $form->createView(),


    ]);
  }

  #[Route('/{id}/interaction/{interaction}/delete', name: 'interaction_delete', methods: ['POST'])]
  public function deleteInteraction(
    Entite $entite,
    Utilisateur $utilisateur,
    ProspectInteraction $interaction,
    Request $request
  ): RedirectResponse {
    $this->assertUtilisateurInEntite($entite, $utilisateur);

    $p = $interaction->getProspect();
    $u = $interaction->getUtilisateur();

    $ok = false;
    if ($p) {
      $ok = ($p->getEntite()?->getId() === $entite->getId())
        && ($p->getLinkedUser()?->getId() === $utilisateur->getId());
    } elseif ($u) {
      $ok = ($u->getId() === $utilisateur->getId());
    }

    if (!$ok) {
      throw $this->createNotFoundException();
    }

    $token = (string) $request->request->get('_token', '');
    if (!$this->isCsrfTokenValid('delete_interaction_' . $interaction->getId(), $token)) {
      $this->addFlash('danger', 'Jeton CSRF invalide.');
      return $this->redirectToRoute('app_administrateur_utilisateur_show', [
        'entite' => $entite->getId(),
        'id'     => $utilisateur->getId(),
      ]);
    }

    if ($p && method_exists($p, 'touch')) {
      $p->touch();
    }

    $this->em->remove($interaction);
    $this->em->flush();

    $this->addFlash('success', 'Interaction supprimée.');

    return $this->redirectToRoute('app_administrateur_utilisateur_show', [
      'entite' => $entite->getId(),
      'id'     => $utilisateur->getId(),
    ]);
  }

  /* ===================== BADGES UI ===================== */

  private function devisStatusBadge(DevisStatus $s): string
  {
    $class = match ($s) {
      DevisStatus::DRAFT    => 'bg-secondary',
      DevisStatus::SENT     => 'bg-info text-dark',
      DevisStatus::ACCEPTED => 'bg-success',
      DevisStatus::INVOICED => 'bg-primary',
      DevisStatus::CANCELED => 'bg-danger',
    };

    return sprintf('<span class="badge %s">%s</span>', $class, htmlspecialchars($s->label(), ENT_QUOTES));
  }

  private function factureStatusBadge(FactureStatus $s): string
  {
    $class = match ($s) {
      FactureStatus::DUE      => 'bg-warning text-dark',
      FactureStatus::PAID     => 'bg-success',
      FactureStatus::CANCELED => 'bg-danger',
    };

    return sprintf('<span class="badge %s">%s</span>', $class, htmlspecialchars($s->label(), ENT_QUOTES));
  }

  private function inscriptionStatusBadge(StatusInscription $s): string
  {
    $class = match ($s) {
      StatusInscription::PREINSCRIT => 'bg-secondary',
      StatusInscription::CONFIRME   => 'bg-primary',
      StatusInscription::EN_COURS   => 'bg-info text-dark',
      StatusInscription::TERMINE    => 'bg-success',
      StatusInscription::ANNULE     => 'bg-danger',
      StatusInscription::ABSENT     => 'bg-dark',
    };

    return sprintf('<span class="badge %s">%s</span>', $class, htmlspecialchars($s->label(), ENT_QUOTES));
  }

  private function sessionStatusBadge(StatusSession $s): string
  {
    $class = match ($s) {
      StatusSession::DRAFT     => 'bg-secondary',
      StatusSession::PUBLISHED => 'bg-primary',
      StatusSession::FULL      => 'bg-warning text-dark',
      StatusSession::CANCELED  => 'bg-danger',
      StatusSession::DONE      => 'bg-success',
    };

    return sprintf('<span class="badge %s">%s</span>', $class, htmlspecialchars($s->label(), ENT_QUOTES));
  }

  /* ===================== AJAX TABLES (SHOW) ===================== */

  #[Route('/{id}/devis/ajax', name: 'devis_ajax', methods: ['POST'])]
  public function devisAjax(Entite $entite, Utilisateur $utilisateur): JsonResponse
  {
    $this->assertUtilisateurInEntite($entite, $utilisateur);

    $qb = $this->em->getRepository(Devis::class)->createQueryBuilder('d')
      ->andWhere('d.entite = :entite')
      ->setParameter('entite', $entite)
      ->leftJoin('d.inscriptions', 'i')
      ->leftJoin('i.stagiaire', 's')
      ->andWhere('(d.destinataire = :u OR s = :u)')
      ->setParameter('u', $utilisateur)
      ->orderBy('d.id', 'DESC')
      ->distinct();

    $items = $qb->getQuery()->getResult();

    $data = array_map(function (Devis $d) use ($entite) {
      return [
        'id'      => $d->getId(),
        'numero'  => $d->getNumero() ?? '—',
        'date'    => $d->getDateEmission()?->format('d/m/Y') ?? '—',
        'ttc'     => number_format(($d->getMontantTtcCents() ?? 0) / 100, 2, ',', ' ') . ' €',
        'status'  => $this->devisStatusBadge($d->getStatus()),
        'actions' => $this->renderView('administrateur/utilisateur/_dt_actions_devis.html.twig', [
          'entite' => $entite,
          'devis'  => $d,
        ]),
      ];
    }, $items);

    return new JsonResponse(['data' => $data]);
  }



  #[Route('/{id}/factures/ajax', name: 'factures_ajax', methods: ['POST'])]
  public function facturesAjax(Entite $entite, Utilisateur $utilisateur): JsonResponse
  {
    $this->assertUtilisateurInEntite($entite, $utilisateur);

    $qb = $this->em->getRepository(Facture::class)->createQueryBuilder('f')
      ->andWhere('f.entite = :e')
      ->setParameter('e', $entite)
      ->leftJoin('f.inscriptions', 'i')
      ->leftJoin('i.stagiaire', 's')
      ->andWhere('(f.destinataire = :u OR s = :u)')
      ->setParameter('u', $utilisateur)
      ->orderBy('f.id', 'DESC')
      ->distinct();

    $factures = $qb->getQuery()->getResult();

    $data = array_map(function (Facture $f) use ($entite) {
      return [
        'id'      => $f->getId(),
        'numero'  => $f->getNumero() ?: '—',
        'date'    => $f->getDateEmission()?->format('d/m/Y') ?? '—',
        'ttc'     => number_format(($f->getMontantTtcCents() ?? 0) / 100, 2, ',', ' ') . ' €',
        'status'  => $this->factureStatusBadge($f->getStatus()),
        'actions' => $this->renderView('administrateur/utilisateur/_dt_actions_facture.html.twig', [
          'entite'  => $entite,
          'facture' => $f,
        ]),
      ];
    }, $factures);

    return new JsonResponse(['data' => $data]);
  }



  #[Route('/{id}/inscriptions/ajax', name: 'inscriptions_ajax', methods: ['POST'])]
  public function inscriptionsAjax(Entite $entite, Utilisateur $utilisateur): JsonResponse
  {
    $this->assertUtilisateurInEntite($entite, $utilisateur);

    $inscriptions = $this->em->getRepository(Inscription::class)->createQueryBuilder('i')
      ->join('i.session', 's')
      ->andWhere('i.stagiaire = :u')
      ->andWhere('s.entite = :e')
      ->setParameter('u', $utilisateur)
      ->setParameter('e', $entite)
      ->orderBy('s.id', 'DESC')
      ->getQuery()
      ->getResult();

    $data = array_map(function (Inscription $i) use ($entite) {
      $s = $i->getSession();

      return [
        'id'        => $i->getId(),
        'session'   => $s?->getLabel() ?? '—',
        'dates'     => trim(
          ($s?->getDateDebut()?->format('d/m/Y') ?? '') . ' → ' . ($s?->getDateFin()?->format('d/m/Y') ?? '')
        ),
        'statut'    => $this->inscriptionStatusBadge($i->getStatus()),
        'sessionSt' => $s ? $this->sessionStatusBadge($s->getStatus()) : '—',
        'actions'   => sprintf(
          '<a class="btn btn-outline-primary btn-sm" href="%s"><i class="bi bi-calendar-event"></i></a>',
          $s?->getId()
            ? $this->generateUrl('app_administrateur_session_show', ['entite' => $entite->getId(), 'id' => $s->getId()])
            : '#'
        ),
      ];
    }, $inscriptions);

    return new JsonResponse(['data' => $data]);
  }

  /* ===================== HELPERS ===================== */

  private function assertUtilisateurInEntite(Entite $entite, Utilisateur $utilisateur): void
  {
    $ue = $this->em->getRepository(UtilisateurEntite::class)->findOneBy([
      'entite' => $entite,
      'utilisateur' => $utilisateur,
    ]);

    if (!$ue) {
      throw $this->createNotFoundException('Utilisateur introuvable pour cette entité.');
    }
  }

  private function isLockedUtilisateur(Utilisateur $u): bool
  {
    return $u->getInscriptions()->count() > 0 || $u->isVerified();
  }

  private function initResetToken(Utilisateur $u): void
  {
    $u->setResetToken(ByteString::fromRandom(32)->toString());
    $u->setResetTokenExpiresAt(new \DateTimeImmutable('+2 hours'));
  }

  private function isStaffRole(string $r): bool
  {
    return \in_array($r, [
      UtilisateurEntite::TENANT_ADMIN,
      UtilisateurEntite::TENANT_DIRIGEANT,
      UtilisateurEntite::TENANT_COMMERCIAL,
    ], true);
  }




  private function addBillingLimitFlash(Entite $entite, BillingQuotaExceededException $e): void
  {
      $sub = $this->entitlementService->getLatestSubscription($entite);
      $currentPlan = $sub?->getPlan();
      $nextPlan = $this->entitlementService->getNextUpgradePlan($entite);

      $this->addFlash('billing_limit_modal', [
          'title' => 'Limite de votre offre atteinte',
          'message' => $e->getMessage(),
          'quotaKey' => $e->getQuotaKey(),
          'current' => $e->getCurrent(),
          'limit' => $e->getLimit(),
          'currentPlanName' => $currentPlan?->getName(),
          'nextPlanName' => $nextPlan?->getName(),
          'nextPlanPriceMonthly' => $nextPlan instanceof Plan && $nextPlan->getPriceMonthlyCents() !== null
              ? number_format($nextPlan->getPriceMonthlyCents() / 100, 2, ',', ' ')
              : null,
          'billingUrl' => $this->generateUrl('app_administrateur_billing', [
              'entite' => $entite->getId(),
          ]),
      ]);
  }
}
