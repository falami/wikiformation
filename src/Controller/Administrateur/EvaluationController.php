<?php

declare(strict_types=1);

namespace App\Controller\Administrateur;

use App\Entity\{Entite, EvaluationDossier, EvaluationTemplate, Inscription, Qcm, Session, Utilisateur, UtilisateurEntite};
use App\Enum\StatusInscription;
use App\Security\Permission\TenantPermission;
use App\Service\Evaluation\{EvaluationWorkflow, WorkAtHeightPreset, EvaluationLibrary};
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/administrateur/{entite}/evaluations', name: 'app_administrateur_evaluation_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::QCM_MANAGE, subject: 'entite')]
final class EvaluationController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em, private EvaluationWorkflow $workflow, private UtilisateurEntiteManager $uem, private EvaluationLibrary $library) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Entite $entite, Request $request): Response
    {
        $search = mb_substr(trim($request->query->getString('q')), 0, 180);
        $state = $request->query->getString('state');
        $page = max(1, $request->query->getInt('page', 1));
        $query = $this->em->createQueryBuilder()->select('d', 'i', 's', 'u', 'e')->from(EvaluationDossier::class, 'd')
            ->join('d.inscription', 'i')->join('d.trainingSession', 's')->join('d.learner', 'u')->join('d.evaluator', 'e')
            ->where('d.entite = :tenant AND i.entite = :tenant AND s.entite = :tenant')->setParameter('tenant', $entite);
        if ($search !== '') $query->andWhere('u.nom LIKE :q OR u.prenom LIKE :q OR s.code LIKE :q OR e.nom LIKE :q OR e.prenom LIKE :q')->setParameter('q', '%'.$search.'%');
        if ($state === 'assigned') $query->andWhere('d.theorySubmittedAt IS NULL AND d.publishedAt IS NULL');
        if ($state === 'to_review') $query->andWhere('d.theorySubmittedAt IS NOT NULL AND d.publishedAt IS NULL');
        if ($state === 'published') $query->andWhere('d.publishedAt IS NOT NULL');
        $total = (int) (clone $query)->select('COUNT(d.id)')->getQuery()->getSingleScalarResult();
        $page = min($page, max(1, (int) ceil($total / 25)));
        $dossiers = $query->orderBy('d.id', 'DESC')->setFirstResult(($page - 1) * 25)->setMaxResults(25)->getQuery()->getResult();
        return $this->page('index', $entite, [
            'templates' => $this->library->catalog($entite),
            'qcms' => $this->em->getRepository(Qcm::class)->findBy(['entite' => $entite], ['titre' => 'ASC']),
            'dossiers' => $dossiers, 'search' => $search, 'state' => $state, 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / 25)),
        ]);
    }

    #[Route('/modeles/nouveau', name: 'template_new', methods: ['GET', 'POST'])]
    public function templateNew(Entite $entite, Request $request, WorkAtHeightPreset $preset): Response
    {
        $template = (new EvaluationTemplate())->setEntite($entite)->setCreateur($this->actor())->setActive(false);
        if ($request->isMethod('GET') && $request->query->getString('preset') === 'height') {
            $template->setTitle('Travail en hauteur — théorie et pratique')->setSchema($preset->schema());
        }
        if ($request->isMethod('GET') && $request->query->getInt('qcm') > 0) {
            $qcm = $this->em->getRepository(Qcm::class)->findOneBy(['id' => $request->query->getInt('qcm'), 'entite' => $entite]);
            if (!$qcm) throw $this->createNotFoundException();
            $template->setTitle($qcm->getTitre().' — théorie et pratique')->setSchema($this->copyQcm($qcm));
        }
        return $this->editModel($entite, $template, $request);
    }

    #[Route('/modeles/{id}/consulter', name: 'template_show', requirements: ['id'=>'\\d+'], methods: ['GET'])]
    public function templateShow(Entite $entite, int $id): Response
    {
        $template = $this->em->getRepository(EvaluationTemplate::class)->find($id);
        if (!$template || !((!$template->getTrainerOwner() && $template->getEntite()?->getId() === $entite->getId()) || $this->library->available($template, $entite))) throw $this->createNotFoundException();
        return $this->page('template_preview', $entite, ['template'=>$template]);
    }

    #[Route('/modeles/{id}/modifier', name: 'template_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function templateEdit(Entite $entite, EvaluationTemplate $template, Request $request): Response
    {
        if ($template->getTrainerOwner() || $template->getEntite()?->getId() !== $entite->getId()) throw $this->createNotFoundException();
        if ($this->library->locked($template) || $template->getArchivedAt()) return $this->page('template_locked', $entite, ['template'=>$template]);
        return $this->editModel($entite, $template, $request);
    }

    #[Route('/modeles/{id}/{action}', name: 'template_lifecycle', requirements: ['id'=>'\\d+', 'action'=>'archiver|dupliquer'], methods: ['POST'])]
    public function lifecycle(Entite $entite, EvaluationTemplate $template, string $action, Request $request): Response
    {
        if ($template->getTrainerOwner() || $template->getEntite()?->getId() !== $entite->getId()) throw $this->createNotFoundException();
        if (!$this->isCsrfTokenValid('evaluation_lifecycle_'.$template->getId(), $request->request->getString('_token'))) throw $this->createAccessDeniedException();
        if ($action === 'dupliquer') {
            $copy = $this->library->duplicate($template, $this->actor());
            return $this->redirectToRoute('app_administrateur_evaluation_template_edit', ['entite'=>$entite->getId(), 'id'=>$copy->getId()]);
        }
        $this->library->archive($template);
        $this->addFlash('success', 'Modèle archivé. Les dossiers existants restent disponibles.');
        return $this->redirectToRoute('app_administrateur_evaluation_index', ['entite'=>$entite->getId()]);
    }

    private function editModel(Entite $entite, EvaluationTemplate $template, Request $request): Response
    {
        $errors = [];
        $values = ['title' => $template->getTitle(), 'active' => $template->isActive(), 'schema' => $template->getSchema() ?: ['theory' => [], 'practice' => [], 'theoryThreshold' => null, 'practiceThreshold' => null]];
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('evaluation_model_'.$entite->getId().'_'.($template->getId() ?? 'new'), $request->request->getString('_token'))) throw $this->createAccessDeniedException('Le formulaire a expiré.');
            $values['title'] = trim($request->request->getString('title'));
            $values['active'] = $request->request->getBoolean('active');
            try {
                $raw = $request->request->getString('schema');
                if (strlen($raw) > 500000) throw new \InvalidArgumentException('Le modèle est trop volumineux.');
                $schema = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
                if (!is_array($schema)) throw new \InvalidArgumentException('Le contenu du modèle est invalide.');
                $values['schema'] = $schema;
                if ($values['title'] === '' || mb_strlen($values['title']) > 180) throw new \InvalidArgumentException('Le nom du modèle doit contenir entre 1 et 180 caractères.');
                $schema = $this->workflow->validateTemplate($schema);
                $this->library->save($template, $request->request->getInt('modelVersion'), $values['title'], $schema, $values['active']);
                $this->addFlash('success', 'Modèle enregistré. Les évaluations déjà affectées conservent leur version.');
                return $this->redirectToRoute('app_administrateur_evaluation_index', ['entite' => $entite->getId()]);
            } catch (\InvalidArgumentException|\JsonException $exception) { $errors[] = $exception->getMessage(); }
        }
        return $this->page('template_edit', $entite, ['template' => $template, 'values' => $values, 'errors' => $errors]);
    }

    #[Route('/affecter', name: 'assign', methods: ['GET', 'POST'])]
    public function assign(Entite $entite, Request $request): Response
    {
        $this->denyAccessUnlessGranted(TenantPermission::QCM_ASSIGNMENT_MANAGE, $entite);
        $sessionId = $request->isMethod('POST') ? $request->request->getInt('session') : $request->query->getInt('session');
        $session = $sessionId ? $this->em->getRepository(Session::class)->findOneBy(['id' => $sessionId, 'entite' => $entite]) : null;
        if ($sessionId && !$session) throw $this->createNotFoundException();
        $templateId = $request->isMethod('POST') ? $request->request->getInt('template') : $request->query->getInt('template');
        $inscriptions = $session ? $this->em->getRepository(Inscription::class)->findBy(['session' => $session, 'entite' => $entite]) : [];
        $inscriptions = array_values(array_filter($inscriptions, static fn (Inscription $i) => !in_array($i->getStatus(), [StatusInscription::ANNULE, StatusInscription::ABSENT], true)));
        $evaluators = $session ? $this->evaluators($session, $entite) : [];
        $errors = [];
        $selected = $request->isMethod('POST') ? $request->request->all('inscriptions') : [];
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('evaluation_assign_'.$entite->getId(), $request->request->getString('_token'))) throw $this->createAccessDeniedException('Le formulaire a expiré.');
            $template = $this->em->getRepository(EvaluationTemplate::class)->find($templateId);
            $evaluator = $evaluators[$request->request->getInt('evaluator')] ?? null;
            if (!$session || !$template || !$this->library->available($template, $entite) || !$evaluator) $errors[] = 'Choisissez un modèle actif, une session et un formateur intervenant sur cette session.';
            $allowed = []; foreach ($inscriptions as $inscription) $allowed[$inscription->getId()] = $inscription;
            if (!$selected || count($selected) > 500) $errors[] = 'Sélectionnez entre 1 et 500 stagiaires.';
            foreach ($selected as $id) if (!is_scalar($id) || !ctype_digit((string) $id) || !isset($allowed[(int) $id])) { $errors[] = 'Une inscription sélectionnée ne correspond pas à cette session.'; break; }
            if (!$errors) {
                $created = $this->em->wrapInTransaction(function () use ($selected, $allowed, $template, $evaluator, $entite): int {
                    $this->em->lock($template, LockMode::PESSIMISTIC_WRITE);
                    $this->em->refresh($template);
                    if (!$this->library->available($template, $entite)) return -1;
                    $snapshot = $this->workflow->snapshotTemplate($template);
                    $created = 0;
                    $ids = array_unique(array_map('intval', $selected)); sort($ids);
                    foreach ($ids as $id) {
                        $inscription = $allowed[$id];
                        $this->em->lock($inscription, LockMode::PESSIMISTIC_WRITE);
                        if ($this->em->getRepository(EvaluationDossier::class)->findOneBy(['entite' => $entite, 'template' => $template, 'inscription' => $inscription])) continue;
                        $dossier = (new EvaluationDossier())->setEntite($entite)->setTemplate($template)->setInscription($inscription)->setEvaluator($evaluator)->setCreateur($this->actor())->setSnapshot($snapshot);
                        $this->em->persist($dossier); ++$created;
                    }
                    if ($created > 0) $template->markAssigned();
                    return $created;
                });
                if ($created < 0) { $this->addFlash('warning', 'Ce modèle n’est plus disponible.'); return $this->redirectToRoute('app_administrateur_evaluation_assign', ['entite'=>$entite->getId()]); }
                $this->addFlash('success', sprintf('%d dossier(s) créé(s). Les affectations déjà existantes ont été conservées.', $created));
                return $this->redirectToRoute('app_administrateur_evaluation_index', ['entite' => $entite->getId()]);
            }
        }
        return $this->page('assign', $entite, ['sessions' => $this->em->getRepository(Session::class)->findBy(['entite' => $entite], ['id' => 'DESC']), 'session' => $session,
            'templates' => $this->library->catalog($entite, true), 'templateId' => $templateId,
            'inscriptions' => $inscriptions, 'evaluators' => $evaluators, 'evaluatorId' => $request->request->getInt('evaluator'), 'selected' => $selected, 'errors' => $errors]);
    }

    #[Route('/dossiers/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Entite $entite, EvaluationDossier $dossier): Response
    {
        $this->assertDossier($entite, $dossier);
        return $this->page('show', $entite, ['dossier' => $dossier, 'summary' => $this->workflow->scoreSummary($dossier),
            'evaluators' => $this->evaluators($dossier->getSession(), $entite),
            'nextDossier' => $this->em->getRepository(EvaluationDossier::class)->findOneBy(['entite' => $entite, 'previousDossier' => $dossier])]);
    }

    #[Route('/dossiers/{id}/evaluateur', name: 'evaluator', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function evaluator(Entite $entite, EvaluationDossier $dossier, Request $request): Response
    {
        $this->assertDossier($entite, $dossier);
        $this->denyAccessUnlessGranted(TenantPermission::QCM_ASSIGNMENT_MANAGE, $entite);
        if (!$this->isCsrfTokenValid('evaluation_evaluator_'.$dossier->getId(), $request->request->getString('_token'))) throw $this->createAccessDeniedException();
        $evaluator = $this->evaluators($dossier->getSession(), $entite)[$request->request->getInt('evaluator')] ?? null;
        if (!$evaluator || $dossier->getPublishedAt() || $dossier->getTheoryGrading() !== [] || $dossier->getPracticeGrading() !== []) {
            $this->addFlash('warning', 'La réaffectation est possible avant toute notation, vers un intervenant de la session.');
        } else { $dossier->setEvaluator($evaluator); $this->em->flush(); $this->addFlash('success', 'Évaluateur mis à jour.'); }
        return $this->redirectToRoute('app_administrateur_evaluation_show', ['entite' => $entite->getId(), 'id' => $dossier->getId()]);
    }

    #[Route('/dossiers/{id}/nouvelle-tentative', name: 'retake', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function retake(Entite $entite, EvaluationDossier $dossier, Request $request): Response
    {
        $this->assertDossier($entite, $dossier);
        $this->denyAccessUnlessGranted(TenantPermission::QCM_ASSIGNMENT_MANAGE, $entite);
        if (!$this->isCsrfTokenValid('evaluation_retake_'.$dossier->getId(), $request->request->getString('_token'))) throw $this->createAccessDeniedException();
        $evaluator = $this->evaluators($dossier->getSession(), $entite)[$request->request->getInt('evaluator')] ?? null;
        if (!$dossier->getPublishedAt() || !$evaluator) { $this->addFlash('warning', 'Finalisez le dossier précédent et choisissez un intervenant de la session.'); return $this->redirectToRoute('app_administrateur_evaluation_show', ['entite' => $entite->getId(), 'id' => $dossier->getId()]); }
        $next = $this->em->wrapInTransaction(function () use ($entite, $dossier, $evaluator): ?EvaluationDossier {
            $this->em->lock($dossier->getInscription(), LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($dossier->getInscription());
            if ($dossier->getInscription()->getStagiaire()?->getId() !== $dossier->getStagiaire()?->getId()
                || $dossier->getInscription()->getSession()?->getId() !== $dossier->getSession()?->getId()) return null;
            $existing = $this->em->getRepository(EvaluationDossier::class)->findOneBy(['entite' => $entite, 'previousDossier' => $dossier]);
            if ($existing) return $existing;
            $next = (new EvaluationDossier())->setEntite($entite)->setTemplate($dossier->getTemplate())->setInscription($dossier->getInscription())->setEvaluator($evaluator)->setCreateur($this->actor())->setSnapshot($dossier->getSnapshot())->setPreviousDossier($dossier);
            $this->em->persist($next); return $next;
        });
        if (!$next) {
            $this->addFlash('warning', 'L’inscription a changé de stagiaire ou de session. Ce dossier reste conservé ; créez une affectation depuis l’inscription correspondant au stagiaire concerné.');
            return $this->redirectToRoute('app_administrateur_evaluation_show', ['entite' => $entite->getId(), 'id' => $dossier->getId()]);
        }
        $this->addFlash('success', 'La nouvelle tentative est disponible. Le dossier précédent reste conservé.');
        return $this->redirectToRoute('app_administrateur_evaluation_show', ['entite' => $entite->getId(), 'id' => $next->getId()]);
    }

    private function assertDossier(Entite $entite, EvaluationDossier $dossier): void
    {
        if (!$dossier->getTemplate()?->getTrainerOwner() && $dossier->getTemplate()?->getEntite()?->getId() !== $entite->getId()) throw $this->createNotFoundException();
        foreach ([$dossier->getEntite(), $dossier->getInscription()?->getEntite(), $dossier->getSession()?->getEntite()] as $tenant) if ($tenant?->getId() !== $entite->getId()) throw $this->createNotFoundException();
    }

    /** @return array<int, Utilisateur> */
    private function evaluators(Session $session, Entite $entite): array
    {
        $result = [];
        foreach ($session->getFormateursEffectifs() as $trainer) {
            $user = $trainer->getUtilisateur();
            if (!$user || $trainer->getEntite()?->getId() !== $entite->getId()) continue;
            $membership = $this->em->getRepository(UtilisateurEntite::class)->findOneBy(['utilisateur' => $user, 'entite' => $entite]);
            if ($membership?->isActive() && $membership->hasRole(UtilisateurEntite::TENANT_FORMATEUR)) $result[$user->getId()] = $user;
        }
        return $result;
    }

    private function copyQcm(Qcm $qcm): array
    {
        $theory = [];
        foreach ($qcm->getQuestions() as $question) {
            $options = []; $correct = [];
            foreach ($question->getOptions() as $option) { $id = 'o'.$option->getId(); $options[] = ['id' => $id, 'label' => trim(strip_tags($option->getLabel()))]; if ($option->isCorrect()) $correct[] = $id; }
            $theory[] = ['id' => 'q'.$question->getId(), 'label' => trim(strip_tags($question->getEnonce())), 'type' => $question->getType()->value, 'points' => $question->getPointsMax(), 'options' => $options, 'correct' => $correct];
        }
        return ['theory' => $theory, 'practice' => [], 'theoryThreshold' => null, 'practiceThreshold' => null];
    }

    private function actor(): Utilisateur
    {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) throw $this->createAccessDeniedException();
        return $user;
    }

    private function page(string $template, Entite $entite, array $data): Response
    {
        $response = $this->render('evaluation/admin/'.$template.'.html.twig', $data + ['entite' => $entite, 'utilisateurEntite' => $this->uem->getUserEntiteLink($entite)]);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }
}
