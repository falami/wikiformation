<?php

declare (strict_types=1);
namespace App\Controller\Portail;

use App\Entity\{Entite, Utilisateur, UtilisateurEntite, AuditLog, Formation, Site, Session, Inscription, Formateur};
use App\Service\Delegation\{DossierAccess, DossierRegistry, DossierForm};
use App\Service\Billing\{BillingGuard, EntitlementService};
use App\Exception\BillingQuotaExceededException;
use App\Service\Sequence\SessionNumberGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface;
#[Route('/{espace}/{entite}', name: 'app_portail_', requirements: ['espace' => 'commercial|opco', 'entite' => '\d+'])]
final class DossierPortalController extends AbstractController
{
    public function __construct(private readonly DossierAccess $access, private readonly DossierRegistry $registry, private readonly DossierForm $forms, private readonly EntityManagerInterface $em, private readonly EntitlementService $entitlements, private readonly \App\Service\Delegation\CommercialRules $rules, private readonly \App\Service\Delegation\PortfolioScope $scope, private readonly \App\Service\Delegation\TrainerContracts $trainerContracts, private readonly \App\Service\Sequence\ContratFormateurNumberGenerator $trainerNumbers)
    {
    }
    private function context(Entite $entite, string $espace): UtilisateurEntite
    {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            throw $this->createAccessDeniedException();
        }
        $membership = $this->access->membership($user, $entite, $espace === 'commercial' ? UtilisateurEntite::TENANT_COMMERCIAL : UtilisateurEntite::TENANT_OPCO);
        if (!$this->entitlements->isEntiteActive($entite)) {
            throw $this->createAccessDeniedException('L’abonnement de cet organisme doit être renouvelé par son administrateur.');
        }
        return $membership;
    }
    private function modules(string $espace): array
    {
        return $espace === 'commercial' ? DossierRegistry::MODULES : array_intersect_key(DossierRegistry::MODULES, array_flip(DossierRegistry::PARTNER_MODULES));
    }
    private function checkModule(string $espace, string $module): void
    {
        if (!isset($this->modules($espace)[$module])) {
            throw $this->createNotFoundException();
        }
    }
    #[Route('/dashboard', name: 'commercial_dashboard', defaults: ['espace' => 'commercial'], requirements: ['espace' => 'commercial'], methods: ['GET'])]
    #[Route('/dashboard', name: 'opco_dashboard', defaults: ['espace' => 'opco'], requirements: ['espace' => 'opco'], methods: ['GET'])]
    #[Route('/dashboard', name: 'dashboard', methods: ['GET'])]
    public function dashboard(
        #[MapEntity(id: 'entite')]
        Entite $entite,
        string $espace
    ): Response
    {
        $membership = $this->context($entite, $espace);
        $modules = $this->modules($espace);
        $counts = [];
        foreach ($modules as $key => $config) {
            $counts[$key] = count($this->access->rows($membership, $key));
        }
        $companies = $espace === 'commercial' ? $this->access->rows($membership, 'entreprises') : [];
        $reminders = [];
        $quotesTotal = 0;
        $invoiceTotal = 0;
        if ($espace === 'commercial') {
            foreach ($this->access->rows($membership, 'devis') as $quote) {
                if ($quote->getStatus() === \App\Enum\DevisStatus::DRAFT || $quote->getStatus() === \App\Enum\DevisStatus::SENT) {
                    $quotesTotal += $quote->getMontantHtCents();
                }
            }
            foreach ($this->access->rows($membership, 'factures') as $invoice) {
                if ($invoice->getStatus() !== \App\Enum\FactureStatus::CANCELED) {
                    $invoiceTotal += $invoice->getMontantHtCents();
                }
            }
            foreach ($this->em->getRepository(\App\Entity\CommercialActivity::class)->findBy(['entite' => $entite, 'completedAt' => null], ['dueAt' => 'ASC']) as $activity) {
                if (!$activity->getDueAt()) {
                    continue;
                }
                try {
                    $this->access->grant($membership, $activity->getModule(), $activity->getRecordId());
                    $reminders[] = $activity;
                } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
                }
            }
        }
        return $this->render('portail/dashboard.html.twig', compact('entite', 'espace', 'membership', 'modules', 'counts', 'companies', 'reminders', 'quotesTotal', 'invoiceTotal'));
    }
    #[Route('/dossiers/{module}', name: 'list', methods: ['GET'])]
    public function list(
        #[MapEntity(id: 'entite')]
        Entite $entite,
        string $espace,
        string $module,
        Request $request
    ): Response
    {
        $membership = $this->context($entite, $espace);
        $this->checkModule($espace, $module);
        $config = $this->registry->config($module);
        $query = mb_substr(trim($request->query->getString('q')), 0, 150);
        $companyId = $request->query->getInt('company');
        $companies = $espace === 'commercial' ? $this->access->rows($membership, 'entreprises') : [];
        $rows = [];
        foreach ($this->access->rows($membership, $module) as $record) {
            if ($companyId && !array_filter($this->scope->companies($record), fn($c) => $c->getId() === $companyId)) {
                continue;
            }
            $title = $this->registry->title($record);
            $details = $this->registry->details($module, $record);
            if ($query !== '' && !str_contains(mb_strtolower($title . ' ' . implode(' ', $details)), mb_strtolower($query))) {
                continue;
            }
            $rows[] = ['id' => $record->getId(), 'title' => $title, 'status' => $details['Statut'] ?? 'Attribué'];
        }
        $total = count($rows);
        $page = max(1, $request->query->getInt('page', 1));
        $rows = array_slice($rows, ($page - 1) * 25, 25);
        $canCreate = $espace === 'commercial';
        return $this->render('portail/list.html.twig', compact('entite', 'espace', 'module', 'config', 'query', 'rows', 'total', 'page', 'canCreate', 'companies', 'companyId'));
    }
    #[Route('/dossiers/{module}/nouveau', name: 'new', methods: ['GET', 'POST'])]
    public function create(
        #[MapEntity(id: 'entite')]
        Entite $entite,
        string $espace,
        string $module,
        Request $request,
        BillingGuard $billing,
        SluggerInterface $slugger,
        SessionNumberGenerator $numbers,
        \App\Service\Delegation\CommercialDocuments $documents
    ): Response
    {
        $membership = $this->context($entite, $espace);
        $this->checkModule($espace, $module);
        if ($espace !== 'commercial') {
            throw $this->createAccessDeniedException();
        }
        if ($module === 'contrats-formateurs') {
            return $this->contract($entite, $membership, $request);
        }
        if (in_array($module, DossierRegistry::FINANCIAL_MODULES, true)) {
            return $this->issueDocument($entite, $membership, $module, $request, $documents);
        }
        $config = $this->registry->config($module);
        $class = $config[0];
        $record = new $class();
        $record->setEntite($entite)->setCreateur($this->getUser());
        // Generated identifiers must exist before entity validation; the final values are assigned on save.
        if ($record instanceof Formation || $record instanceof Site) {
            $record->setSlug('nouveau-' . bin2hex(random_bytes(8)));
        }
        if ($record instanceof Session) {
            $record->setCode('BROUILLON-' . bin2hex(random_bytes(8)));
        }
        $form = $this->forms->build($module, $record, $membership, true);
        if (!$request->isMethod('POST') && $companyId = $request->query->getInt('company')) {
            $this->requireManagement($membership, $espace, 'entreprises', $companyId);
            $company = $this->access->record($entite, 'entreprises', $companyId);
            foreach (['company', 'entrepriseCliente', 'linkedEntreprise', 'entreprise'] as $field) {
                if ($form->has($field)) {
                    $form->get($field)->setData($company);
                }
            }
        }
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid() && $record instanceof Session && $form->get('end')->getData() <= $form->get('start')->getData()) {
            $form->get('end')->addError(new FormError('La fin doit être postérieure au début du créneau.'));
        }
        if ($form->isSubmitted() && $form->isValid() && $record instanceof Inscription) {
            if ($record->getSession()->getEntrepriseCliente() && $record->getEntreprise() !== $record->getSession()->getEntrepriseCliente()) {
                $form->addError(new FormError('Sélectionnez l’entreprise cliente de cette session pour l’inscription.'));
            }
            if ($this->access->grant($membership, 'sessions', $record->getSession()->getId())->getAccessLevel() !== 'edit') {
                $form->addError(new FormError('La gestion de cette session doit vous être attribuée pour y inscrire un stagiaire.'));
            }
        }
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                if ($module === 'clients' || $module === 'formateurs' && !$record->getUtilisateur()) {
                    $email = mb_strtolower(trim((string) $form->get('email')->getData()));
                    if (!$email || !trim((string) $form->get('prenom')->getData()) || !trim((string) $form->get('nom')->getData())) {
                        $form->addError(new FormError('Choisissez un compte existant ou renseignez le prénom, le nom et l’e-mail du nouveau compte.'));
                        return $this->render('portail/edit.html.twig', ['entite' => $entite, 'espace' => $espace, 'module' => $module, 'config' => $config, 'title' => 'Créer un compte', 'form' => $form]);
                    }
                    if ($this->em->getRepository(Utilisateur::class)->findByCanonicalEmail(['email' => $email])) {
                        $form->addError(new FormError('Cette adresse est déjà utilisée. Demandez à votre administrateur de rattacher et attribuer le compte existant.'));
                        return $this->render('portail/edit.html.twig', ['entite' => $entite, 'espace' => $espace, 'module' => $module, 'config' => $config, 'title' => 'Nouveau client', 'form' => $form]);
                    }
                    $billing->assertCanTransitionUtilisateurEntite($entite, [], UtilisateurEntite::STATUS_INVITED, [$module === 'formateurs' ? UtilisateurEntite::TENANT_FORMATEUR : UtilisateurEntite::TENANT_STAGIAIRE], UtilisateurEntite::STATUS_ACTIVE);
                    $user = (new Utilisateur())->setEntite($entite)->setCreateur($this->getUser())->setEmail($email)->setPrenom($form->get('prenom')->getData())->setNom($form->get('nom')->getData())->setPassword(password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT));
                    $record->setUtilisateur($user);
                    if ($record instanceof UtilisateurEntite) {
                        $record->setRoles([UtilisateurEntite::TENANT_STAGIAIRE]);
                    } else {
                        $this->em->persist((new UtilisateurEntite())->setEntite($entite)->setCreateur($this->getUser())->setUtilisateur($user)->setRoles([UtilisateurEntite::TENANT_FORMATEUR]));
                    }
                    if ($form->has('company') && $form->get('company')->getData()) {
                        $user->setEntreprise($form->get('company')->getData());
                    }
                    $this->em->persist($user);
                }
                if ($module === 'entreprises') {
                    $billing->assertCanCreateEntreprise($entite);
                }
                if ($record instanceof Formation || $record instanceof Site) {
                    $record->setSlug(strtolower($slugger->slug($this->registry->title($record)) . '-' . bin2hex(random_bytes(4))));
                }
                if ($record instanceof Session) {
                    $record->setCode($numbers->nextForEntite($entite->getId()));
                }
                if ($record instanceof Formateur && $this->em->getRepository(Formateur::class)->findOneBy(['entite' => $entite, 'utilisateur' => $record->getUtilisateur()])) {
                    $form->addError(new FormError('Un formateur existe déjà pour ce compte. Demandez son attribution à votre administrateur.'));
                } elseif ($record instanceof Inscription && $this->em->getRepository(Inscription::class)->findOneBy(['session' => $record->getSession(), 'stagiaire' => $record->getStagiaire()])) {
                    $form->addError(new FormError('Ce stagiaire est déjà inscrit à cette session.'));
                } else {
                    $this->em->wrapInTransaction(function () use ($record, $membership, $module, $form, $entite) {
                        if ($record instanceof Session) {
                            $day = (new \App\Entity\SessionJour())->setSession($record)->setEntite($entite)->setCreateur($this->getUser())->setDateDebut($form->get('start')->getData())->setDateFin($form->get('end')->getData())->setFormateur($record->getFormateur());
                            $record->addJour($day);
                            $this->em->persist($day);
                        }
                        if ($record instanceof Inscription) {
                            $dossier = (new \App\Entity\DossierInscription())->setCreateur($this->getUser())->setEntite($entite)->setInscription($record);
                            $record->setDossier($dossier);
                            $this->em->persist($dossier);
                        }
                        $this->em->persist($record);
                        $this->em->flush();
                        $this->access->assign($membership, $module, $record->getId(), 'edit', $this->getUser());
                        $this->audit($membership, $module, $record->getId(), 'created');
                    });
                    $this->addFlash('success', 'Dossier créé et ajouté à votre portefeuille.');
                    return $this->redirectToRoute('app_portail_show', ['entite' => $entite->getId(), 'espace' => $espace, 'module' => $module, 'id' => $record->getId()]);
                }
            } catch (BillingQuotaExceededException $e) {
                $form->addError(new FormError($e->getMessage()));
            }
        }
        return $this->render('portail/edit.html.twig', ['entite' => $entite, 'espace' => $espace, 'module' => $module, 'config' => $config, 'title' => 'Nouveau dossier', 'form' => $form]);
    }
    #[Route('/dossiers/{module}/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function show(
        #[MapEntity(id: 'entite')]
        Entite $entite,
        string $espace,
        string $module,
        int $id,
        Request $request
    ): Response
    {
        $membership = $this->context($entite, $espace);
        $this->checkModule($espace, $module);
        $grant = $this->access->grant($membership, $module, $id);
        $record = $this->access->record($entite, $module, $id);
        $config = $this->registry->config($module);
        $title = $this->registry->title($record);
        $details = $this->registry->details($module, $record);
        $canEdit = $espace === 'commercial' && $grant->getAccessLevel() === 'edit' && $this->forms->editable($module) && !$this->rules->editBlock($record) && $module !== 'contrats-formateurs';
        if ($record instanceof Inscription && $record->getStatus() === \App\Enum\StatusInscription::TERMINE) {
            $canEdit = false;
        }
        $form = null;
        if ($canEdit) {
            $form = $this->forms->build($module, $record, $membership, false)->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                if ($record instanceof UtilisateurEntite) {
                    foreach (['prenom', 'nom', 'telephone'] as $field) {
                        $record->getUtilisateur()->{'set' . ucfirst($field)}($form->get($field)->getData());
                    }
                }
                $this->audit($membership, $module, $id, 'updated');
                $this->em->flush();
                $this->addFlash('success', 'Modifications enregistrées.');
                return $this->redirectToRoute('app_portail_show', ['entite' => $entite->getId(), 'espace' => $espace, 'module' => $module, 'id' => $id]);
            }
        } elseif ($request->isMethod('POST')) {
            throw $this->createAccessDeniedException();
        }
        $canManage = $espace === 'commercial' && $grant->getAccessLevel() === 'edit';
        $editBlock = $this->rules->editBlock($record);
        $deleteBlock = $this->rules->deleteBlock($record);
        $activities = $this->em->getRepository(\App\Entity\CommercialActivity::class)->findBy(['entite' => $entite, 'module' => $module, 'recordId' => $id], ['createdAt' => 'DESC'], 30);
        $related = [];
        if ($module === 'entreprises') {
            foreach (['clients', 'sessions', 'inscriptions', 'devis', 'conventions', 'factures', 'contrats-formateurs', 'prospects'] as $childModule) {
                $related[$childModule] = [];
                foreach ($this->access->rows($membership, $childModule) as $child) {
                    if (in_array($record, $this->scope->companies($child), true)) {
                        $related[$childModule][] = ['id' => $child->getId(), 'title' => $this->registry->title($child)];
                    }
                }
            }
        }
        $days = $record instanceof Session ? $record->getJours() : [];
        $modules = $this->modules($espace);
        return $this->render('portail/show.html.twig', compact('entite', 'espace', 'module', 'id', 'config', 'title', 'details', 'form', 'grant', 'canEdit', 'canManage', 'editBlock', 'deleteBlock', 'activities', 'related', 'modules', 'days'));
    }
    #[Route('/sessions/{id}/creneau/{dayId}', name: 'schedule_edit', requirements: ['id' => '\d+', 'dayId' => '\d+'], methods: ['GET', 'POST'])]
    #[Route('/sessions/{id}/creneau', name: 'schedule', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function schedule(
        #[MapEntity(id: 'entite')]
        Entite $entite,
        string $espace,
        int $id,
        Request $request,
        ?int $dayId = null
    ): Response
    {
        $membership = $this->context($entite, $espace);
        $grant = $this->access->grant($membership, 'sessions', $id);
        if ($espace !== 'commercial' || $grant->getAccessLevel() !== 'edit') {
            throw $this->createAccessDeniedException();
        }
        $record = $this->access->record($entite, 'sessions', $id);
        if ($record->getEmargementClotureAt()) {
            throw $this->createAccessDeniedException('Le suivi des émargements est clôturé.');
        }
        $existingDay = $dayId ? $this->em->find(\App\Entity\SessionJour::class, $dayId) : null;
        if ($dayId && (!$existingDay || $existingDay->getSession()->getId() !== $id || $existingDay->getEntite()->getId() !== $entite->getId())) {
            throw $this->createNotFoundException();
        }
        if ($existingDay && $this->rules->deleteBlock($record)) {
            throw $this->createAccessDeniedException('Le planning lié à des inscriptions ou documents ne peut plus être modifié ici.');
        }
        $day = $existingDay ?? (new \App\Entity\SessionJour())->setSession($record)->setEntite($entite)->setCreateur($this->getUser());
        $form = $this->createFormBuilder($day, ['csrf_token_id' => 'schedule_' . $membership->getId() . '_' . $id])->add('dateDebut', \Symfony\Component\Form\Extension\Core\Type\DateTimeType::class, ['label' => 'Début', 'widget' => 'single_text', 'input' => 'datetime_immutable', 'constraints' => [new \Symfony\Component\Validator\Constraints\NotNull()]])->add('dateFin', \Symfony\Component\Form\Extension\Core\Type\DateTimeType::class, ['label' => 'Fin', 'widget' => 'single_text', 'input' => 'datetime_immutable', 'constraints' => [new \Symfony\Component\Validator\Constraints\NotNull()]])->add('pauseMinutes', \Symfony\Component\Form\Extension\Core\Type\IntegerType::class, ['label' => 'Pause en minutes', 'required' => false])->add('formateur', \Symfony\Component\Form\Extension\Core\Type\ChoiceType::class, ['label' => 'Formateur', 'choices' => $this->access->rows($membership, 'formateurs'), 'choice_value' => fn($f) => $f?->getId(), 'choice_label' => fn($f) => $this->registry->title($f), 'required' => false, 'placeholder' => 'Sans formateur'])->getForm()->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            foreach ($record->getJours() as $existing) {
                if ($existing->getId() !== $day->getId() && $day->getDateDebut() < $existing->getDateFin() && $day->getDateFin() > $existing->getDateDebut()) {
                    $form->addError(new FormError('Ce créneau chevauche un créneau existant.'));
                }
            }
            if ($form->isValid()) {
                $this->em->persist($day);
                $this->audit($membership, 'sessions', $id, 'schedule_added');
                $this->em->flush();
                $this->addFlash('success', 'Créneau ajouté au planning.');
                return $this->redirectToRoute('app_portail_show', ['entite' => $entite->getId(), 'espace' => $espace, 'module' => 'sessions', 'id' => $id]);
            }
        }
        return $this->render('portail/edit.html.twig', ['entite' => $entite, 'espace' => $espace, 'module' => 'sessions', 'config' => $this->registry->config('sessions'), 'title' => ($dayId ? 'Modifier le créneau · ' : 'Ajouter un créneau · ') . $record->getCode(), 'form' => $form, 'submitLabel' => $dayId ? 'Enregistrer le créneau' : 'Ajouter le créneau']);
    }
    private function issueDocument(Entite $entite, UtilisateurEntite $membership, string $module, Request $request, \App\Service\Delegation\CommercialDocuments $documents, ?object $existing = null): Response
    {
        $form = $documents->form($membership, $module, $existing);
        if (!$existing && !$request->isMethod('POST') && $companyId = $request->query->getInt('company')) {
            $this->requireManagement($membership, 'commercial', 'entreprises', $companyId);
            $form->get('company')->setData($this->access->record($entite, 'entreprises', $companyId));
        }
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            if ($module === 'conventions') {
                $targets = [['sessions', $data['session']->getId()]];
                foreach ($data['participants'] as $participant) {
                    $targets[] = ['inscriptions', $participant->getId()];
                }
                foreach ($targets as [$targetModule, $targetId]) {
                    if ($this->access->grant($membership, $targetModule, $targetId)->getAccessLevel() !== 'edit') {
                        $form->addError(new FormError('La gestion de la session et des inscriptions doit vous être attribuée pour établir leur convention.'));
                        break;
                    }
                }
            }
            if (!$form->isValid()) {
                return $this->render('portail/document.html.twig', ['entite' => $entite, 'espace' => 'commercial', 'module' => $module, 'config' => $this->registry->config($module), 'form' => $form, 'editing' => $existing !== null]);
            }
            if ($error = $documents->validate($data, $module)) {
                $form->addError(new FormError($error));
            } else {
                $recipient = $data['company'] ?? $data['learner'];
                $recipientModule = $data['company'] ? 'entreprises' : 'clients';
                if ($this->access->grant($membership, $recipientModule, $recipient->getId())->getAccessLevel() !== 'edit') {
                    $form->addError(new FormError('Votre administrateur doit vous accorder la gestion du destinataire pour émettre un document.'));
                } else {
                    $record = $this->em->wrapInTransaction(function () use ($documents, $membership, $module, $data, $existing) {
                        $record = $documents->issue($membership, $module, $data, $this->getUser(), $existing);
                        $this->audit($membership, $module, $record->getId(), $existing ? 'updated' : 'issued');
                        return $record;
                    });
                    $this->addFlash('success', $existing ? 'Document mis à jour.' : 'Document créé. Vous pouvez télécharger son PDF.');
                    return $this->redirectToRoute('app_portail_show', ['entite' => $entite->getId(), 'espace' => 'commercial', 'module' => $module, 'id' => $record->getId()]);
                }
            }
        }
        return $this->render('portail/document.html.twig', ['entite' => $entite, 'espace' => 'commercial', 'module' => $module, 'config' => $this->registry->config($module), 'form' => $form, 'editing' => $existing !== null]);
    }
    #[Route('/dossiers/{module}/{id}/pdf', name: 'pdf', requirements: ['id' => '\d+', 'module' => 'devis|factures|conventions|contrats-formateurs'], methods: ['GET'])]
    public function pdf(
        #[MapEntity(id: 'entite')]
        Entite $entite,
        string $espace,
        string $module,
        int $id,
        \App\Service\Pdf\PdfManager $pdf,
        \App\Service\Convention\ConventionDocument $conventions,
        \App\Service\Pdf\ContratFormateurDocument $trainerDocument
    ): Response
    {
        $membership = $this->context($entite, $espace);
        $this->checkModule($espace, $module);
        $this->access->grant($membership, $module, $id);
        $record = $this->access->record($entite, $module, $id);
        if ($module === 'contrats-formateurs') {
            if ($trainerDocument->isFrozen($record)) {
                $path = $trainerDocument->storedPath($record);
                if (!$path) {
                    throw $this->createNotFoundException('Le document signé doit être restauré par un administrateur.');
                }
                return new \Symfony\Component\HttpFoundation\BinaryFileResponse($path, 200, ['Cache-Control' => 'private, no-store']);
            }
            return $pdf->createPortrait($this->renderView('pdf/contrat_formateur.html.twig', $trainerDocument->templateData($record)), 'Contrat-' . $record->getNumero());
        }
        if ($module === 'conventions') {
            return $conventions->response($record);
        }
        $name = $module === 'devis' ? 'devis' : 'facture';
        $response = $pdf->createPortrait($this->renderView('pdf/' . $name . '.html.twig', ['entite' => $entite, $name => $record]), $name . '-' . $record->getNumero());
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }
    #[Route('/dossiers/{module}/{id}/modifier', name: 'edit_document', requirements: ['id' => '\d+', 'module' => 'devis|conventions|contrats-formateurs'], methods: ['GET', 'POST'])]
    public function editDocument(
        #[MapEntity(id: 'entite')]
        Entite $entite,
        string $espace,
        string $module,
        int $id,
        Request $request,
        \App\Service\Delegation\CommercialDocuments $documents
    ): Response
    {
        $membership = $this->context($entite, $espace);
        $this->requireManagement($membership, $espace, $module, $id);
        $record = $this->access->record($entite, $module, $id);
        if ($reason = $this->rules->editBlock($record)) {
            throw $this->createAccessDeniedException($reason);
        }
        if ($module === 'contrats-formateurs') {
            return $this->contract($entite, $membership, $request, $record);
        }
        return $this->issueDocument($entite, $membership, $module, $request, $documents, $record);
    }
    private function contract(Entite $entite, UtilisateurEntite $membership, Request $request, ?\App\Entity\ContratFormateur $record = null): Response
    {
        $new = $record === null;
        $record ??= (new \App\Entity\ContratFormateur())->setEntite($entite)->setCreateur($this->getUser());
        if ($new) {
            $this->trainerContracts->defaults($record);
        }
        $form = $this->trainerContracts->form($record, $membership)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->trainerContracts->validate($form, $record);
            if ($form->isValid()) {
                $this->em->wrapInTransaction(function () use ($new, $record, $membership, $entite) {
                    if ($new) {
                        $record->setNumero($this->trainerNumbers->nextForEntite($entite->getId()));
                    }
                    $this->em->persist($record);
                    $this->em->flush();
                    $this->access->assign($membership, 'contrats-formateurs', $record->getId(), 'edit', $this->getUser());
                    $this->audit($membership, 'contrats-formateurs', $record->getId(), $new ? 'created' : 'updated');
                });
                $this->addFlash('success', 'Contrat formateur enregistré.');
                return $this->redirectToRoute('app_portail_show', ['entite' => $entite->getId(), 'espace' => 'commercial', 'module' => 'contrats-formateurs', 'id' => $record->getId()]);
            }
        }
        return $this->render('portail/edit.html.twig', ['entite' => $entite, 'espace' => 'commercial', 'module' => 'contrats-formateurs', 'config' => $this->registry->config('contrats-formateurs'), 'title' => $new ? 'Préparer un contrat formateur' : 'Modifier le contrat ' . $record->getNumero(), 'form' => $form, 'submitLabel' => 'Enregistrer le contrat']);
    }
    private function requireManagement(UtilisateurEntite $member, string $space, string $module, int $id): void
    {
        if ($space !== 'commercial' || $this->access->grant($member, $module, $id)->getAccessLevel() !== 'edit') {
            throw $this->createAccessDeniedException();
        }
    }
    #[Route('/dossiers/{module}/{id}/supprimer', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteRecord(
        #[MapEntity(id: 'entite')]
        Entite $entite,
        string $espace,
        string $module,
        int $id,
        Request $request
    ): Response
    {
        $membership = $this->context($entite, $espace);
        $this->requireManagement($membership, $espace, $module, $id);
        if (!$this->isCsrfTokenValid('portal_delete_' . $module . '_' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $record = $this->access->record($entite, $module, $id);
        if ($reason = $this->rules->deleteBlock($record)) {
            $this->addFlash('warning', $reason);
            return $this->redirectToRoute('app_portail_show', ['entite' => $entite->getId(), 'espace' => $espace, 'module' => $module, 'id' => $id]);
        }
        $this->em->wrapInTransaction(function () use ($record, $membership, $module, $id) {
            $this->audit($membership, $module, $id, 'deleted');
            foreach ($this->em->getRepository(\App\Entity\DossierDelegation::class)->findBy(['module' => $module, 'recordId' => $id]) as $grant) {
                $this->em->remove($grant);
            }
            $this->em->remove($record);
        });
        $this->addFlash('success', 'Dossier supprimé.');
        return $this->redirectToRoute('app_portail_list', ['entite' => $entite->getId(), 'espace' => $espace, 'module' => $module]);
    }
    #[Route('/dossiers/{module}/{id}/ecrire', name: 'compose', requirements: ['id' => '\d+', 'module' => 'entreprises|prospects|factures|devis'], methods: ['GET', 'POST'])]
    public function compose(
        #[MapEntity(id: 'entite')]
        Entite $entite,
        string $espace,
        string $module,
        int $id,
        Request $request,
        \App\Service\Delegation\CommercialMail $mail
    ): Response
    {
        $membership = $this->context($entite, $espace);
        $this->requireManagement($membership, $espace, $module, $id);
        $record = $this->access->record($entite, $module, $id);
        $recipient = $mail->recipient($record);
        $form = $this->createFormBuilder(['subject' => in_array($module, ['factures', 'devis'], true) ? 'Votre ' . $this->registry->title($record) : 'Suivi de votre projet de formation', 'body' => "Bonjour,\n\n\n\nBien cordialement,\n" . $this->getUser()->getPrenom() . ' ' . $this->getUser()->getNom(), 'sendKey' => bin2hex(random_bytes(16))], ['csrf_token_id' => 'commercial_send_' . $module . '_' . $id])->add('subject', \Symfony\Component\Form\Extension\Core\Type\TextType::class, ['label' => 'Objet', 'constraints' => [new \Symfony\Component\Validator\Constraints\NotBlank(), new \Symfony\Component\Validator\Constraints\Length(max: 180)]])->add('body', \Symfony\Component\Form\Extension\Core\Type\TextareaType::class, ['label' => 'Message', 'attr' => ['rows' => 10], 'constraints' => [new \Symfony\Component\Validator\Constraints\NotBlank(), new \Symfony\Component\Validator\Constraints\Length(max: 20000)]])->add('sendKey', \Symfony\Component\Form\Extension\Core\Type\HiddenType::class, ['constraints' => [new \Symfony\Component\Validator\Constraints\Regex('/^[a-f0-9]{32}$/')]])->getForm()->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            try {
                $sent = $mail->send($record, $module, $this->getUser(), $data['subject'], $data['body'], $data['sendKey']);
                $this->addFlash('success', $sent ? 'Message envoyé et ajouté à l’historique.' : 'Ce message a déjà été pris en charge.');
                return $this->redirectToRoute('app_portail_show', ['entite' => $entite->getId(), 'espace' => $espace, 'module' => $module, 'id' => $id]);
            } catch (\DomainException $e) {
                $form->addError(new FormError($e->getMessage()));
            }
        }
        return $this->render('portail/compose.html.twig', ['entite' => $entite, 'espace' => $espace, 'module' => $module, 'id' => $id, 'title' => $this->registry->title($record), 'recipient' => $recipient, 'form' => $form]);
    }
    #[Route('/dossiers/{module}/{id}/suivi', name: 'activity', requirements: ['id' => '\d+', 'module' => 'entreprises|prospects'], methods: ['GET', 'POST'])]
    public function activity(
        #[MapEntity(id: 'entite')]
        Entite $entite,
        string $espace,
        string $module,
        int $id,
        Request $request
    ): Response
    {
        $membership = $this->context($entite, $espace);
        $this->requireManagement($membership, $espace, $module, $id);
        $activity = (new \App\Entity\CommercialActivity())->setEntite($entite)->setAuthor($this->getUser())->setModule($module)->setRecordId($id);
        $form = $this->createFormBuilder($activity, ['csrf_token_id' => 'activity_' . $module . '_' . $id])->add('title', \Symfony\Component\Form\Extension\Core\Type\TextType::class, ['label' => 'Objet du suivi', 'constraints' => [new \Symfony\Component\Validator\Constraints\NotBlank(), new \Symfony\Component\Validator\Constraints\Length(max: 180)]])->add('kind', \Symfony\Component\Form\Extension\Core\Type\ChoiceType::class, ['label' => 'Type', 'choices' => ['Note' => 'note', 'Appel' => 'call', 'Rendez-vous' => 'meeting', 'Relance à effectuer' => 'reminder']])->add('content', \Symfony\Component\Form\Extension\Core\Type\TextareaType::class, ['label' => 'Compte rendu / prochaine action', 'attr' => ['rows' => 5], 'constraints' => [new \Symfony\Component\Validator\Constraints\NotBlank(), new \Symfony\Component\Validator\Constraints\Length(max: 20000)]])->add('dueAt', \Symfony\Component\Form\Extension\Core\Type\DateTimeType::class, ['label' => 'Échéance de la prochaine action', 'required' => false, 'widget' => 'single_text', 'input' => 'datetime_immutable'])->getForm()->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($activity);
            $this->em->flush();
            $this->addFlash('success', 'Suivi ajouté.');
            return $this->redirectToRoute('app_portail_show', ['entite' => $entite->getId(), 'espace' => $espace, 'module' => $module, 'id' => $id]);
        }
        return $this->render('portail/edit.html.twig', ['entite' => $entite, 'espace' => $espace, 'module' => $module, 'config' => $this->registry->config($module), 'title' => 'Ajouter un suivi commercial', 'form' => $form, 'submitLabel' => 'Enregistrer le suivi']);
    }
    #[Route('/suivi/{id}/terminer', name: 'activity_done', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function completeActivity(
        #[MapEntity(id: 'entite')]
        Entite $entite,
        string $espace,
        int $id,
        Request $request
    ): Response
    {
        $member = $this->context($entite, $espace);
        $activity = $this->em->find(\App\Entity\CommercialActivity::class, $id);
        if (!$activity || $activity->getEntite()->getId() !== $entite->getId()) {
            throw $this->createNotFoundException();
        }
        $this->requireManagement($member, $espace, $activity->getModule(), $activity->getRecordId());
        if (!$this->isCsrfTokenValid('activity_done_' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $activity->complete();
        $this->em->flush();
        return $this->redirectToRoute('app_portail_show', ['entite' => $entite->getId(), 'espace' => $espace, 'module' => $activity->getModule(), 'id' => $activity->getRecordId()]);
    }
    #[Route('/entreprises/{id}/stagiaires', name: 'company_learners', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function companyLearners(
        #[MapEntity(id: 'entite')]
        Entite $entite,
        string $espace,
        int $id,
        Request $request
    ): Response
    {
        $member = $this->context($entite, $espace);
        $this->requireManagement($member, $espace, 'entreprises', $id);
        $company = $this->access->record($entite, 'entreprises', $id);
        // Only already accessible learner accounts can be moved into this portfolio.
        $choices = array_values(array_filter($this->access->editableRows($member, 'clients'), fn($m) => $m->hasRole(UtilisateurEntite::TENANT_STAGIAIRE) && !$this->rules->editBlock($m)));
        $form = $this->createFormBuilder(null, ['csrf_token_id' => 'company_learners_' . $id])->add('learner', \Symfony\Component\Form\Extension\Core\Type\ChoiceType::class, ['label' => 'Stagiaire de mon portefeuille', 'choices' => $choices, 'choice_value' => fn($m) => $m?->getId(), 'choice_label' => fn($m) => $this->registry->title($m), 'placeholder' => 'Sélectionner un stagiaire', 'constraints' => [new \Symfony\Component\Validator\Constraints\NotNull()]])->add('operation', \Symfony\Component\Form\Extension\Core\Type\ChoiceType::class, ['label' => 'Action', 'choices' => ['Rattacher à cette entreprise' => 'attach', 'Retirer de cette entreprise' => 'detach']])->getForm()->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $learner = $data['learner'];
            $user = $learner->getUtilisateur();
            if ($data['operation'] === 'attach') {
                $user->addEntreprisesAssociee($company);
            } else {
                if ($user->getEntreprise() === $company) {
                    $user->setEntreprise(null);
                }
                $user->removeEntreprisesAssociee($company);
                // Creation grants must not retain access once the learner leaves a client portfolio.
                foreach ($this->em->getRepository(\App\Entity\DossierDelegation::class)->findBy(['membership' => $member, 'module' => 'clients', 'recordId' => $learner->getId()]) as $grant) {
                    $this->em->remove($grant);
                }
            }
            $this->audit($member, 'entreprises', $id, 'learner_' . $data['operation']);
            $this->em->flush();
            $this->addFlash('success', 'Rattachement mis à jour. Les inscriptions et les documents existants sont conservés.');
            return $this->redirectToRoute('app_portail_show', ['entite' => $entite->getId(), 'espace' => $espace, 'module' => 'entreprises', 'id' => $id]);
        }
        return $this->render('portail/edit.html.twig', ['entite' => $entite, 'espace' => $espace, 'module' => 'entreprises', 'config' => $this->registry->config('entreprises'), 'title' => 'Gérer les stagiaires · ' . $company->getRaisonSociale(), 'form' => $form, 'submitLabel' => 'Enregistrer le rattachement']);
    }
    private function audit(UtilisateurEntite $membership, string $module, int $id, string $action): void
    {
        $this->em->persist((new AuditLog())->setCreateur($this->getUser())->setCreatedAt(new \DateTimeImmutable())->setEntite($membership->getEntite())->setActor($this->getUser())->setEvent('delegation.' . $action)->setPayload(['module' => $module, 'id' => $id, 'membership' => $membership->getId()]));
    }
}
