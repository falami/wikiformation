<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\{Entite, Utilisateur, Inscription, Entreprise, Formateur, HabilitationTemplate, HabilitationDossier, HabilitationRevision};
use App\Security\Permission\TenantPermission as P;
use App\Service\Habilitation\{HabilitationSchema, HabilitationWorkflow};
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\{EntityManagerInterface, OptimisticLockException};
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;

#[Route('/habilitation/{entite}', name: 'app_habilitation_', requirements: ['entite' => '\d+'])]
final class HabilitationController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em, private HabilitationSchema $schema, private HabilitationWorkflow $workflow, private \App\Service\Habilitation\HabilitationAssignments $assignments) {}

    private function admin(Entite $e): bool { return $this->isGranted(P::ADMIN, $e); }
    private function member(Entite $e): void { $this->denyAccessUnlessGranted(P::ACCESS, $e); }
    private function csrf(Request $r, string $key): void
    {
        if (!$this->isCsrfTokenValid($key, $r->request->getString('_token'))) throw $this->createAccessDeniedException('Formulaire expiré. Rechargez la page.');
    }
    private function privateRender(string $view, array $data, int $status = 200): Response
    {
        $r = $this->render('habilitation/'.$view.'.html.twig', $data, new Response('', $status));
        $r->headers->set('Cache-Control', 'private, no-store'); return $r;
    }
    private function base(Entite $e): array { return ['entite' => $e, 'isAdmin' => $this->admin($e)]; }

    #[Route('/stagiaire', name: 'student_index', methods: ['GET'])]
    #[Route('/formateur', name: 'trainer_index', methods: ['GET'])]
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(#[MapEntity(id: 'entite')] Entite $entite, Request $request, \App\Service\Entreprise\EntreprisePortalContext $portal): Response
    {
        $this->member($entite); $u = $this->getUser(); $admin = $this->admin($entite);
        $studentContext = $request->attributes->get('_route') === 'app_habilitation_student_index';
        $trainerContext = $request->attributes->get('_route') === 'app_habilitation_trainer_index';
        if ($studentContext) { $this->denyAccessUnlessGranted(P::STAGIAIRE, $entite); $admin = false; }
        if ($trainerContext) { $this->denyAccessUnlessGranted(P::FORMATEUR, $entite); $admin = false; }
        $trainer = $this->isGranted(P::FORMATEUR, $entite); $company = $this->isGranted(P::HABILITATION_ENTREPRISE, $entite); $student = $this->isGranted(P::STAGIAIRE, $entite);
        if ($trainerContext) { $company = false; $student = false; }
        if ($studentContext) { $company = false; $trainer = false; }
        if (!$admin && !$trainer && !$company && !$student) throw $this->createAccessDeniedException();
        $sync = ($admin || $trainer) ? $this->assignments->synchronize($entite) : ['created'=>0, 'incomplete'=>0];
        $sessionId = max(0, $request->query->getInt('session'));
        $qb = $this->em->createQueryBuilder()->select('d', 'i', 's', 'u', 'c')->from(HabilitationDossier::class, 'd')
            ->join('d.inscription', 'i')->join('i.session', 's')->join('i.stagiaire', 'u')->join('d.entreprise', 'c')
            ->where('d.entite = :e')->setParameter('e', $entite)->orderBy('d.createdAt', 'DESC');
        if (!$admin) {
            $allowed = [];
            if ($trainer) { $allowed[] = '(d.formateur = :me OR :me MEMBER OF d.formateurs)'; $qb->setParameter('me', $u); }
            if ($company) {
                $companies = $portal->representedCompanies($u, $entite);
                if ($request->query->has('entreprise')) $companies = [$portal->resolve($u, $entite, true)];
                if ($companies) {
                    $allowed[] = "(d.entreprise IN (:companies) AND i.entite = :e AND s.entite = :e AND d.avisToken IS NOT NULL AND d.state IN ('awaiting_company', 'published'))";
                    $qb->setParameter('companies', $companies);
                }
            }
            if ($student) { $allowed[] = "(i.stagiaire = :student AND d.avisToken IS NOT NULL AND d.state IN ('awaiting_company', 'published'))"; $qb->setParameter('student', $u); }
            $qb->andWhere($allowed ? '('.implode(' OR ', $allowed).')' : '1 = 0')->andWhere('d.active = true AND d.deleted = false');
        }
        if ($sessionId) $qb->andWhere('s.id = :session')->setParameter('session', $sessionId);
        $q = mb_substr(trim($request->query->getString('q')), 0, 100);
        if ($q !== '') $qb->andWhere('s.code LIKE :q OR u.nom LIKE :q OR u.prenom LIKE :q OR c.raisonSociale LIKE :q')->setParameter('q', '%'.$q.'%');
        $page = max(1, $request->query->getInt('page', 1));
        $rows = $qb->setFirstResult(($page - 1) * 30)->setMaxResults(31)->getQuery()->getResult();
        $more = count($rows) > 30;
        return $this->privateRender('index', ['isAdmin'=>$admin, 'trainerContext'=>$trainerContext, 'studentContext'=>$studentContext, 'indexRoute'=>$trainerContext ? 'app_habilitation_trainer_index' : ($studentContext ? 'app_habilitation_student_index' : 'app_habilitation_index'), 'showRoute'=>$trainerContext ? 'app_habilitation_trainer_show' : ($studentContext ? 'app_habilitation_student_show' : 'app_habilitation_show')] + $this->base($entite) + ['dossiers' => array_slice($rows, 0, 30), 'canCreate' => $admin, 'sessionId' => $sessionId, 'sync' => $sync, 'q' => $q, 'page' => $page, 'more' => $more]);
    }

    #[Route('/attestations', name: 'attestations', methods: ['GET'])]
    public function attestations(#[MapEntity(id: 'entite')] Entite $entite, Request $request): Response
    {
        $this->member($entite);
        $this->denyAccessUnlessGranted(P::ADMIN, $entite);
        $page = max(1, $request->query->getInt('page', 1));
        $qb = $this->em->createQueryBuilder()->select('r', 'd')
            ->from(HabilitationRevision::class, 'r')->join('r.dossier', 'd')
            ->where('d.entite = :entite')->andWhere('r.kind = :kind')
            ->setParameter('entite', $entite)->setParameter('kind', 'avis')
            ->orderBy('r.createdAt', 'DESC')->addOrderBy('r.id', 'DESC');
        $rows = $qb->setFirstResult(($page - 1) * 30)->setMaxResults(31)->getQuery()->getResult();
        return $this->privateRender('attestations', $this->base($entite) + [
            'revisions' => array_slice($rows, 0, 30), 'page' => $page, 'more' => count($rows) > 30,
        ]);
    }

    #[Route('/modeles', name: 'templates', methods: ['GET'])]
    public function templates(#[MapEntity(id: 'entite')] Entite $entite): Response
    {
        $this->denyAccessUnlessGranted(P::ADMIN, $entite);
        return $this->privateRender('templates', $this->base($entite) + ['models' => $this->em->getRepository(HabilitationTemplate::class)->findBy(['entite' => $entite], ['id' => 'DESC'])]);
    }

    #[Route('/modeles/{id}/supprimer', name: 'template_delete', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function deleteModel(#[MapEntity(id: 'entite')] Entite $entite, int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted(P::ADMIN, $entite);
        $this->csrf($request, 'habilitation_model');
        $model = $this->em->getRepository(HabilitationTemplate::class)->findOneBy(['id' => $id, 'entite' => $entite]);
        if (!$model) throw $this->createNotFoundException();
        try {
            $this->em->lock($model, LockMode::OPTIMISTIC, $request->request->getInt('version'));
            $used = $this->em->getRepository(HabilitationDossier::class)->count(['template' => $model])
                + $this->em->getRepository(\App\Entity\Formation::class)->count(['habilitationTemplate' => $model]);
            if ($used) $model->setActive(false); else $this->em->remove($model);
            $this->em->flush();
            $this->addFlash('success', $used ? 'Ce modèle est utilisé : il a été désactivé pour préserver les dossiers existants.' : 'Modèle supprimé.');
        } catch (OptimisticLockException $e) { return new Response('Modèle modifié ailleurs. Rechargez la page.', 409); }
        return $this->redirectToRoute('app_habilitation_templates', ['entite' => $entite->getId()]);
    }

    #[Route('/modeles/nouveau', name: 'template_new', methods: ['GET', 'POST'])]
    #[Route('/modeles/{id}/modifier', name: 'template_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function model(#[MapEntity(id: 'entite')] Entite $entite, Request $request, ?int $id = null): Response
    {
        $this->denyAccessUnlessGranted(P::ADMIN, $entite);
        $model = $id ? $this->em->getRepository(HabilitationTemplate::class)->findOneBy(['id' => $id, 'entite' => $entite]) : (new HabilitationTemplate())->setEntite($entite)->setTitre('Habilitation électrique — évaluation')->setSchema($this->schema->example());
        if (!$model) throw $this->createNotFoundException();
        $error = null; $raw = json_encode($model->getSchema(), JSON_THROW_ON_ERROR);
        if ($request->isMethod('POST')) {
            $this->csrf($request, 'habilitation_model');
            try {
                if ($id) $this->em->lock($model, LockMode::OPTIMISTIC, $request->request->getInt('version'));
                $title = trim($request->request->getString('titre'));
                if (!$title || mb_strlen($title) > 180) throw new \InvalidArgumentException('Le titre doit comporter entre 1 et 180 caractères.');
                $raw = $request->request->getString('schema');
                if (strlen($raw) > 200000) throw new \InvalidArgumentException('Le modèle est trop volumineux.');
                $schema = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($schema)) throw new \InvalidArgumentException('Modèle invalide.');
                $model->setTitre($title)->setSchema($this->schema->validate($schema))->setActive($request->request->getBoolean('active'));
                $this->em->persist($model); $this->em->flush();
                $this->addFlash('success', 'Modèle enregistré. Les dossiers déjà affectés conservent leur version.');
                return $this->redirectToRoute('app_habilitation_templates', ['entite' => $entite->getId()]);
            } catch (\InvalidArgumentException|\JsonException $e) { $error = $e->getMessage(); }
            catch (OptimisticLockException $e) { return new Response('Ce modèle a été modifié ailleurs. Rechargez la page avant de recommencer.', 409); }
        }
        return $this->privateRender('model', $this->base($entite) + ['model' => $model, 'schemaJson' => $raw, 'error' => $error], $error ? 422 : 200);
    }

    #[Route('/affecter', name: 'assign', methods: ['GET', 'POST'])]
    #[Route('/{id}/reaffecter', name: 'reassign', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function assign(#[MapEntity(id: 'entite')] Entite $entite, Request $request, ?int $id = null): Response
    {
        $this->member($entite); $admin = $this->admin($entite); $me = $this->getUser();
        if (!$admin) throw $this->createAccessDeniedException();
        $d = $id ? $this->em->getRepository(HabilitationDossier::class)->findOneBy(['id' => $id, 'entite' => $entite]) : null;
        if ($id && !$d) throw $this->createNotFoundException();
        $inscriptions = $this->em->getRepository(Inscription::class)->findBy(['entite' => $entite]);
        $inscriptions = array_values(array_filter($inscriptions, static function ($i) use ($admin, $me, $entite) {
            if ($i->getSession()->getEntite()->getId() !== $entite->getId()) return false;
            if ($admin) return true;
            foreach ($i->getSession()->getFormateursEffectifs() as $f) if ($f->getUtilisateur()?->getId() === $me->getId()) return true;
            return false;
        }));
        $models = $this->em->getRepository(HabilitationTemplate::class)->findBy(['entite' => $entite, 'active' => true]);
        $companies = $this->em->getRepository(Entreprise::class)->findBy(['entite' => $entite], ['raisonSociale' => 'ASC']);
        $trainers = $this->em->getRepository(Formateur::class)->findBy(['entite' => $entite]);
        $error = null;
        if ($request->isMethod('POST')) {
            $this->csrf($request, 'habilitation_assign');
            try {
                if ($d) $this->em->lock($d, LockMode::OPTIMISTIC, $request->request->getInt('version'));
                $i = $this->pick($inscriptions, $request->request->getInt('inscription'));
                $t = $this->pick($models, $request->request->getInt('template'));
                $c = $this->pick($companies, $request->request->getInt('entreprise'));
                $trainerIds = $request->request->all('formateurs');
                // Accept the previous single-select payload for existing integrations.
                if (!$trainerIds && $request->request->has('formateur')) $trainerIds = [$request->request->getInt('formateur')];
                if (!$trainerIds) throw new \InvalidArgumentException('Sélectionnez au moins un formateur.');
                $assigned = [];
                foreach (array_unique($trainerIds) as $trainerId) {
                    $f = $this->pick($trainers, (int) $trainerId);
                    if (!$f->getUtilisateur()) throw new \InvalidArgumentException('Chaque formateur doit disposer d’un compte.');
                    $assigned[] = $f->getUtilisateur();
                }
                $existing = $this->em->getRepository(HabilitationDossier::class)->findOneBy(['inscription' => $i]);
                if ($existing && $existing !== $d) throw new \InvalidArgumentException('Cette inscription possède déjà un dossier. Ouvrez-le depuis la liste (ou demandez sa réactivation).');
                $previous = $d ? $this->workflow->identity($d) : null;
                $d ??= (new HabilitationDossier())->setEntite($entite);
                $d->setInscription($i)->setTemplate($t)->setSchema($t->getSchema())->setFormateurs($assigned)->setEntreprise($c)
                    ->setTrainerData([])->setEmployerData([])->setAvisToken(null)->setTitreToken(null)->setState('draft')->setActive(true)->setDeleted(false);
                $this->em->persist($d);
                $this->em->persist($this->workflow->record($d, $me, $previous ? 'reassign' : 'assign', ['previous' => $previous, 'identity' => $this->workflow->identity($d)]));
                $this->em->flush();
                $this->addFlash('success', 'Dossier affecté. Une nouvelle évaluation et de nouvelles signatures sont nécessaires.');
                return $this->redirectToRoute('app_habilitation_show', ['entite' => $entite->getId(), 'id' => $d->getId()]);
            } catch (\InvalidArgumentException $e) { $error = $e->getMessage(); }
            catch (OptimisticLockException $e) { return new Response('Dossier modifié ailleurs. Rechargez la page.', 409); }
            catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) { return new Response('Cette inscription possède déjà un dossier. Rechargez la liste.', 409); }
        }
        return $this->privateRender('assign', $this->base($entite) + compact('d', 'inscriptions', 'models', 'companies', 'trainers', 'error'), $error ? 422 : 200);
    }

    #[Route('/modifier-selection', name: 'bulk_assign', methods: ['POST'])]
    public function bulkAssign(#[MapEntity(id: 'entite')] Entite $entite, Request $request): Response
    {
        $this->member($entite);
        $this->denyAccessUnlessGranted(P::ADMIN, $entite);
        $this->csrf($request, 'habilitation_bulk');
        $ids = $request->request->all('ids');
        if (!$ids || count($ids) > 100) return new Response('Sélectionnez entre 1 et 100 dossiers.', 422);
        foreach ($ids as $id) if (!is_scalar($id) || !ctype_digit((string) $id)) return new Response('Sélection invalide.', 422);
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $dossiers = $this->em->getRepository(HabilitationDossier::class)->findBy(['id'=>$ids, 'entite'=>$entite], ['id'=>'ASC']);
        if (count($dossiers) !== count($ids)) throw $this->createNotFoundException();
        $models = $this->em->getRepository(HabilitationTemplate::class)->findBy(['entite'=>$entite, 'active'=>true]);
        $companies = $this->em->getRepository(Entreprise::class)->findBy(['entite'=>$entite], ['raisonSociale'=>'ASC']);
        $trainers = $this->em->getRepository(Formateur::class)->findBy(['entite'=>$entite]);
        $inscriptions = array_values(array_filter($this->em->getRepository(Inscription::class)->findBy(['entite'=>$entite]),
            static fn ($i) => $i->getSession()->getEntite()->getId() === $entite->getId()));
        $values = $request->request->all(); $error = null;
        if ($request->request->getString('action') === 'apply') {
            try {
                $company = $request->request->getInt('entreprise') ? $this->pick($companies, $request->request->getInt('entreprise')) : null;
                $model = $request->request->getInt('template') ? $this->pick($models, $request->request->getInt('template')) : null;
                $assigned = null;
                if ($request->request->getBoolean('change_trainers')) {
                    $assigned = [];
                    foreach ($request->request->all('formateurs') as $fid) {
                        if (!is_scalar($fid) || !ctype_digit((string)$fid)) throw new \InvalidArgumentException('Formateur invalide.');
                        $f = $this->pick($trainers, (int)$fid);
                        if (!$f->getUtilisateur()) throw new \InvalidArgumentException('Le formateur doit avoir un compte.');
                        $assigned[$f->getUtilisateur()->getId()] = $f->getUtilisateur();
                    }
                    if (!$assigned) throw new \InvalidArgumentException('Sélectionnez au moins un formateur ou conservez les affectations actuelles.');
                    $assigned = array_values($assigned);
                }
                $versions = $request->request->all('versions');
                $registrations = $request->request->all('inscriptions');
                $changes = [];
                foreach ($dossiers as $d) {
                    $version = $versions[$d->getId()] ?? null;
                    if (!is_scalar($version) || !ctype_digit((string)$version) || (int)$version !== $d->getVersion()) {
                        return new Response('Un dossier a été modifié ailleurs. Revenez à la liste et recommencez la sélection. Aucune modification appliquée.', 409);
                    }
                    $this->em->lock($d, LockMode::OPTIMISTIC, (int)$version);
                    $registration = $registrations[$d->getId()] ?? $d->getInscription()->getId();
                    if (!is_scalar($registration) || !ctype_digit((string)$registration)) throw new \InvalidArgumentException('Inscription invalide.');
                    $i = $this->pick($inscriptions, (int)$registration);
                    $existing = $this->em->getRepository(HabilitationDossier::class)->findOneBy(['inscription'=>$i]);
                    if ($existing && $existing !== $d) throw new \InvalidArgumentException('Une inscription sélectionnée possède déjà un dossier.');
                    $oldTrainers = array_map(static fn ($u) => $u->getId(), $d->getFormateurs());
                    $newTrainers = $assigned === null ? $oldTrainers : array_map(static fn ($u) => $u->getId(), $assigned);
                    sort($oldTrainers); sort($newTrainers);
                    if (($company && $company !== $d->getEntreprise()) || ($model && $model !== $d->getTemplate()) || $i !== $d->getInscription() || $oldTrainers !== $newTrainers) {
                        $changes[] = [$d, $i];
                    }
                }
                if (!$changes) throw new \InvalidArgumentException('Aucune différence à appliquer. Choisissez les champs à modifier.');
                if (!$request->request->getBoolean('confirm_reset')) throw new \InvalidArgumentException('Confirmez la remise en évaluation des dossiers modifiés.');
                $this->em->wrapInTransaction(function () use ($changes, $company, $model, $assigned) {
                    foreach ($changes as [$d, $i]) {
                        $before = ['identity'=>$this->workflow->identity($d), 'schema'=>$d->getSchema(), 'trainerData'=>$d->getTrainerData(),
                            'employerData'=>$d->getEmployerData(), 'avisToken'=>$d->getAvisToken(), 'titreToken'=>$d->getTitreToken(), 'state'=>$d->getState()];
                        $d->setInscription($i);
                        if ($company) $d->setEntreprise($company);
                        if ($model) $d->setTemplate($model)->setSchema($model->getSchema());
                        if ($assigned !== null) $d->setFormateurs($assigned);
                        $d->setTrainerData([])->setEmployerData([])->setAvisToken(null)->setTitreToken(null)->setState('draft');
                        $this->em->persist($this->workflow->record($d, $this->getUser(), 'reassign', ['bulk'=>true, 'previous'=>$before, 'identity'=>$this->workflow->identity($d)]));
                    }
                });
                $this->addFlash('success', count($changes).' dossier(s) modifié(s). Les versions précédentes sont conservées ; les dossiers modifiés nécessitent une nouvelle évaluation.');
                return $this->redirectToRoute('app_habilitation_index', ['entite'=>$entite->getId()]);
            } catch (\InvalidArgumentException $e) { $error = $e->getMessage(); }
            catch (OptimisticLockException|\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) {
                return new Response('Conflit de modification : aucune modification appliquée. Rechargez la liste.', 409);
            }
        }
        return $this->privateRender('bulk_assign', $this->base($entite) + compact('dossiers','models','companies','trainers','inscriptions','values','error'), $error ? 422 : 200);
    }

    private function pick(array $objects, int $id): object
    {
        foreach ($objects as $object) if ($object->getId() === $id) return $object;
        throw new \InvalidArgumentException('Sélectionnez une affectation valide dans votre organisme.');
    }

    private function access(Entite $e, HabilitationDossier $d): array
    {
        $this->member($e);
        if ($d->getEntite()->getId() !== $e->getId()) throw $this->createNotFoundException();
        $u = $this->getUser(); $admin = $this->admin($e);
        $trainer = $this->isGranted(P::FORMATEUR, $e) && $d->isAssignedTo($u);
        $company = $this->isGranted(P::HABILITATION_ENTREPRISE, $e) && \App\Service\Entreprise\EntreprisePortalContext::isRepresentative($u, $d->getEntreprise());
        $student = $this->isGranted(P::STAGIAIRE, $e) && $d->getInscription()->getStagiaire()->getId() === $u->getId() && $d->getAvisToken() && in_array($d->getState(), ['awaiting_company', 'published'], true);
        if (!$admin && ((!$d->getActive() || $d->getDeleted()) || (!$trainer && !($company && $d->getAvisToken() && in_array($d->getState(), ['awaiting_company', 'published'], true)) && !$student))) throw $this->createAccessDeniedException();
        return compact('admin', 'trainer', 'company', 'student');
    }

    #[Route('/stagiaire/{id}', name: 'student_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[Route('/formateur/{id}', name: 'trainer_show', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    #[Route('/{id}', name: 'show', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function show(#[MapEntity(id: 'entite')] Entite $entite, #[MapEntity(id: 'id')] HabilitationDossier $dossier, Request $request): Response
    {
        $rights = $this->access($entite, $dossier);
        if ($request->attributes->get('_route') === 'app_habilitation_student_show') {
            if (!$rights['student'] || !$dossier->getActive() || $dossier->getDeleted()) throw $this->createAccessDeniedException();
            $rights = ['admin'=>false, 'trainer'=>false, 'company'=>false, 'student'=>true];
        }
        if ($request->attributes->get('_route') === 'app_habilitation_trainer_show') {
            if (!$rights['trainer'] || !$dossier->getActive() || $dossier->getDeleted()) throw $this->createAccessDeniedException();
            $rights = ['admin'=>false, 'trainer'=>true, 'company'=>false, 'student'=>false];
        }
        return $this->editDossier($entite, $dossier, $request, $rights);
    }

    #[Route('/{id}/entreprise', name: 'company_title', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function companyTitle(#[MapEntity(id: 'entite')] Entite $entite, #[MapEntity(id: 'id')] HabilitationDossier $dossier, Request $request,
        \App\Service\Entreprise\EntreprisePortalContext $portal, \App\Service\Entreprise\EntrepriseHabilitations $documents): Response
    {
        $this->denyAccessUnlessGranted(P::HABILITATION_ENTREPRISE, $entite);
        $entreprise = $portal->resolve($this->getUser(), $entite, true);
        if (!$documents->available($dossier, $entite, $entreprise) || !$documents->current($dossier, 'avis')) throw $this->createNotFoundException();
        // Even an administrator uses the employer's limited, independent copy here.
        $response = $this->editDossier($entite, $dossier, $request, ['admin'=>false, 'trainer'=>false, 'company'=>true, 'student'=>false], $entreprise, $portal->representedCompanies($this->getUser(), $entite));
        return $response;
    }

    private function editDossier(Entite $entite, HabilitationDossier $dossier, Request $request, array $rights, ?Entreprise $entreprise = null, array $entreprisesDisponibles = []): Response
    {
        $studentContext = $request->attributes->get('_route') === 'app_habilitation_student_show';
        $trainerContext = $request->attributes->get('_route') === 'app_habilitation_trainer_show';
        $enterpriseContext = $entreprise !== null;
        $showRoute = $enterpriseContext ? 'app_habilitation_company_title' : ($trainerContext ? 'app_habilitation_trainer_show' : ($studentContext ? 'app_habilitation_student_show' : 'app_habilitation_show'));
        $showRouteParams = ['entite'=>$entite->getId(), 'id'=>$dossier->getId()];
        if ($enterpriseContext) $showRouteParams['entreprise'] = $entreprise->getId();
        $stage = $request->query->getString('stage', $rights['company'] && !$rights['trainer'] && !$rights['admin'] ? 'titre' : 'avis');
        if (!in_array($stage, ['avis', 'titre'], true)) throw $this->createNotFoundException();
        if ($stage === 'titre' && !$rights['admin'] && !$rights['company'] && !$rights['student']) throw $this->createAccessDeniedException();
        if ($stage === 'titre' && $rights['student'] && !$rights['admin'] && !$rights['company'] && !$dossier->getTitreToken()) throw $this->createAccessDeniedException('Le titre signé n’est pas encore disponible.');
        $editable = ($stage === 'avis' ? ($rights['admin'] || $rights['trainer']) : $rights['company']) && $dossier->getActive() && !$dossier->getDeleted() && ($stage === 'avis' || ($dossier->getAvisToken() && in_array($dossier->getState(), ['awaiting_company', 'published'], true)));
        $canSign = $stage === 'avis' ? $rights['trainer'] : $rights['company'];
        $data = $stage === 'avis' ? $dossier->getTrainerData() : $dossier->getEmployerData();
        $error = null;
        $trainerReference = [];
        $employerChoices = [];
        if ($dossier->getAvisToken() && in_array($dossier->getState(), ['awaiting_company', 'published'], true)) {
            try {
                $trainerReference = $this->workflow->signedTrainerData($dossier);
                $employerChoices = $this->schema->employerChoices($dossier->getSchema(), $trainerReference);
                if ($stage === 'titre' && !$data) $data = $this->workflow->initialEmployerData($dossier);
                if ($stage === 'avis' && ($enterpriseContext || ($rights['student'] && !$editable))) $data = $trainerReference;
            } catch (\InvalidArgumentException $e) {
                if ($stage === 'titre' || $enterpriseContext) { $error = $e->getMessage(); $editable = false; }
            }
        }
        if ($request->isMethod('POST')) {
            $this->csrf($request, 'habilitation_'.$dossier->getId());
            if (!$editable) throw $this->createAccessDeniedException();
            $sign = $request->request->getString('action') === 'sign';
            if ($sign && !$canSign) throw $this->createAccessDeniedException('La signature appartient au formateur affecté ou au représentant de l’entreprise.');
            if ($request->request->getString('_complete') !== '1') return new Response('Le formulaire reçu est incomplet. Aucune modification enregistrée. Réduisez la taille du modèle ou contactez l’administrateur.', 422);
            $data = $request->request->all('data');
            try {
                $this->em->lock($dossier, LockMode::OPTIMISTIC, $request->request->getInt('version'));
                if ($sign && !$request->request->getBoolean('confirm')) throw new \InvalidArgumentException('Confirmez votre validation avant de signer.');
                $revision = $this->workflow->save($dossier, $stage, $data, $sign, $this->getUser(), $request->request->getString('signature'));
                $this->em->persist($revision); $this->em->flush();
                $this->addFlash('success', $sign ? ($stage === 'avis' ? 'Évaluation validée. L’administrateur peut maintenant la transmettre à l’entreprise.' : 'Titre signé et disponible dans l’espace stagiaire.') : 'Brouillon enregistré. Les anciennes versions signées restent dans l’historique ; une nouvelle signature est nécessaire.');
                return $this->redirectToRoute($showRoute, $showRouteParams + ['stage'=>$stage]);
            } catch (\InvalidArgumentException $e) { $error = $e->getMessage(); }
            catch (OptimisticLockException $e) { return new Response('Ce dossier a été modifié dans une autre fenêtre. Rechargez avant de recommencer.', 409); }
        }
        $revisions = $this->em->getRepository(HabilitationRevision::class)->findBy(['dossier' => $dossier], ['id' => 'DESC'], 100);
        $revisions = array_values(array_filter($revisions, fn ($r) => $this->visibleRevision($r, $dossier, $rights)));
        $displaySchema = $stage === 'titre' ? $this->schema->employerSchema($dossier->getSchema(), $trainerReference) : $dossier->getSchema();
        $context = compact('displaySchema', 'studentContext', 'entreprisesDisponibles', 'trainerContext', 'dossier', 'rights', 'stage', 'editable', 'canSign', 'data', 'error', 'revisions', 'trainerReference', 'employerChoices', 'enterpriseContext', 'entreprise', 'showRoute', 'showRouteParams');
        if ($enterpriseContext) {
            $context['avisPdfUrl'] = $this->generateUrl('app_entreprise_documents_habilitation_pdf', $showRouteParams + ['kind'=>'avis']);
            $context['titrePdfUrl'] = $dossier->getTitreToken() ? $this->generateUrl('app_entreprise_documents_habilitation_pdf', $showRouteParams + ['kind'=>'titre']) : null;
            $context['returnDocumentsUrl'] = $this->generateUrl('app_entreprise_documents_index', ['entite'=>$entite->getId(), 'entreprise'=>$entreprise->getId()]).'#pane-habilitations';
        }
        return $this->privateRender('show', array_replace($this->base($entite), $context, ['isAdmin'=>$rights['admin']]), $error ? 422 : 200);
    }

    private function visibleRevision(HabilitationRevision $r, HabilitationDossier $d, array $rights): bool
    {
        if ($rights['admin']) return true;
        if (!in_array($r->getKind(), ['avis', 'titre'], true)) return false;
        $identity = $r->getSnapshot()['identity'] ?? [];
        if (($identity['traineeId'] ?? null) !== $d->getInscription()->getStagiaire()->getId() || ($identity['companyId'] ?? null) !== $d->getEntreprise()->getId()) return false;
        if ($rights['trainer']) return true;
        return in_array($r->getToken(), [$d->getAvisToken(), $d->getTitreToken()], true);
    }

    #[Route('/{id}/transmettre', name: 'transmit', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function transmit(#[MapEntity(id: 'entite')] Entite $entite, #[MapEntity(id: 'id')] HabilitationDossier $dossier, Request $request): Response
    {
        $this->denyAccessUnlessGranted(P::ADMIN, $entite);
        $this->access($entite, $dossier);
        $this->csrf($request, 'habilitation_'.$dossier->getId());
        try {
            $this->em->lock($dossier, LockMode::OPTIMISTIC, $request->request->getInt('version'));
            $this->em->persist($this->workflow->transmit($dossier, $this->getUser()));
            $this->em->flush();
            $this->addFlash('success', 'Évaluation transmise : elle est disponible dans l’espace entreprise.');
        } catch (\InvalidArgumentException $e) { return new Response($e->getMessage(), 422); }
        catch (OptimisticLockException $e) { return new Response('Dossier modifié ailleurs. Rechargez la page.', 409); }
        return $this->redirectToRoute('app_habilitation_show', ['entite'=>$entite->getId(), 'id'=>$dossier->getId()]);
    }

    #[Route('/{id}/etat', name: 'state', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function state(#[MapEntity(id: 'entite')] Entite $entite, #[MapEntity(id: 'id')] HabilitationDossier $dossier, Request $request): Response
    {
        $this->denyAccessUnlessGranted(P::ADMIN, $entite); $this->access($entite, $dossier);
        $this->csrf($request, 'habilitation_'.$dossier->getId());
        try {
            $this->em->lock($dossier, LockMode::OPTIMISTIC, $request->request->getInt('version'));
            $action = $request->request->getString('action');
            if (!in_array($action, ['disable', 'enable', 'delete'], true)) throw $this->createNotFoundException();
            $reason = trim($request->request->getString('reason'));
            if (!$reason || mb_strlen($reason) > 1000) return new Response('Précisez un motif (1 à 1 000 caractères).', 422);
            $dossier->setActive($action === 'enable')->setDeleted($action === 'delete');
            $this->em->persist($this->workflow->record($dossier, $this->getUser(), $action, ['reason' => $reason])); $this->em->flush();
        } catch (OptimisticLockException $e) { return new Response('Dossier modifié ailleurs. Rechargez la page.', 409); }
        return $this->redirectToRoute('app_habilitation_index', ['entite' => $entite->getId()]);
    }

    #[Route('/attestations-selection', name: 'attestations_export', methods: ['POST'])]
    public function exportAttestations(#[MapEntity(id: 'entite')] Entite $entite, Request $request, \App\Service\Habilitation\HabilitationDocument $renderer): Response
    {
        $this->member($entite);
        $this->csrf($request, 'habilitation_export');
        $ids = array_values(array_unique(array_map('intval', $request->request->all('ids'))));
        if (!$ids || count($ids) > 50 || min($ids) < 1) return new Response('Sélectionnez entre 1 et 50 attestations.', 422);
        $documents = [];
        foreach ($ids as $id) {
            $d = $this->em->find(HabilitationDossier::class, $id);
            if (!$d) throw $this->createNotFoundException();
            $rights = $this->access($entite, $d);
            $revision = $d->getAvisToken() ? $this->em->getRepository(HabilitationRevision::class)->findOneBy(['dossier'=>$d, 'token'=>$d->getAvisToken(), 'kind'=>'avis']) : null;
            if (!$revision || !$this->visibleRevision($revision, $d, $rights)) return new Response('Une attestation sélectionnée n’est plus disponible. Rechargez la liste.', 422);
            $documents[] = $d;
        }
        $path = tempnam(sys_get_temp_dir(), 'hab-export-');
        if ($path === false) throw new \RuntimeException('Impossible de préparer le téléchargement.');
        $zip = new \ZipArchive();
        try {
            if ($zip->open($path, \ZipArchive::OVERWRITE) !== true) throw new \RuntimeException('Impossible de créer l’archive.');
            foreach ($documents as $d) {
                $pdf = $this->pdf($entite, $d, $d->getAvisToken(), $renderer, true);
                $name = $d->getInscription()->getStagiaire()->getPrenom().'-'.$d->getInscription()->getStagiaire()->getNom();
                $name = preg_replace('/[^\pL\pN_-]+/u', '-', $name);
                if (!$zip->addFromString('attestation-'.$name.'-'.$d->getId().'.pdf', $pdf->getContent())) throw new \RuntimeException('Impossible d’ajouter le document.');
            }
            if (!$zip->close()) throw new \RuntimeException('Impossible de finaliser l’archive.');
            $bytes = file_get_contents($path);
            if ($bytes === false) throw new \RuntimeException('Impossible de lire l’archive.');
            return new Response($bytes, 200, ['Content-Type'=>'application/zip', 'Content-Disposition'=>'attachment; filename="attestations-electriques.zip"', 'Cache-Control'=>'private, no-store', 'X-Content-Type-Options'=>'nosniff']);
        } finally {
            if (is_file($path)) unlink($path);
        }
    }

    #[Route('/{id}/document/{token}', name: 'pdf', methods: ['GET'], requirements: ['id' => '\d+', 'token' => '[a-f0-9]{32}'])]
    #[Route('/{id}/attestation/{token}', name: 'attestation', methods: ['GET'], defaults: ['attestation' => true], requirements: ['id' => '\d+', 'token' => '[a-f0-9]{32}'])]
    public function pdf(#[MapEntity(id: 'entite')] Entite $entite, #[MapEntity(id: 'id')] HabilitationDossier $dossier, string $token, \App\Service\Habilitation\HabilitationDocument $documents, bool $attestation = false): Response
    {
        $rights = $this->access($entite, $dossier);
        $r = $this->em->getRepository(HabilitationRevision::class)->findOneBy(['dossier' => $dossier, 'token' => $token]);
        if (!$r || !$this->visibleRevision($r, $dossier, $rights) || !in_array($r->getKind(), ['avis', 'titre'], true)) throw $this->createNotFoundException();
        if ($attestation && $r->getKind() !== 'avis') throw $this->createNotFoundException();
        $content = $documents->bytes($r, $attestation);
        $prefix = $attestation ? 'avis-post-formation' : 'habilitation';
        return new Response($content, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$prefix.'-'.$dossier->getId().'-'.$r->getKind().'-'.substr($token, 0, 8).'.pdf"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
