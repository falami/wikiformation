<?php

declare(strict_types=1);

namespace App\Controller\Shared\Habilitation;

use App\Entity\{Entite, Entreprise, HabilitationDossier, HabilitationRevision};
use App\Security\Permission\TenantPermission as P;
use App\Service\Habilitation\{HabilitationSchema, HabilitationWorkflow};
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\{EntityManagerInterface, OptimisticLockException};
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Bridge\Doctrine\Attribute\MapEntity;

abstract class AbstractHabilitationController extends AbstractController
{
    protected const SPACE = 'administrateur';

    public function __construct(protected EntityManagerInterface $em, protected HabilitationSchema $schema, protected HabilitationWorkflow $workflow, protected \App\Service\Habilitation\HabilitationAssignments $assignments) {}

    protected function admin(Entite $e): bool { return $this->isGranted(P::ADMIN, $e); }

    protected function member(Entite $e): void { $this->denyAccessUnlessGranted(P::ACCESS, $e); }

    protected function csrf(Request $r, string $key): void
    {
        if (!$this->isCsrfTokenValid($key, $r->request->getString('_token'))) throw $this->createAccessDeniedException('Formulaire expiré. Rechargez la page.');
    }

    protected function privateRender(string $view, array $data, int $status = 200): Response
    {
        $r = $this->render(static::SPACE.'/habilitation/'.$view.'.html.twig', $data, new Response('', $status));
        $r->headers->set('Cache-Control', 'private, no-store'); return $r;
    }

    protected function base(Entite $e): array { return ['entite' => $e, 'isAdmin' => $this->admin($e)]; }

    protected function listDossiers(#[MapEntity(id: 'entite')] Entite $entite, Request $request, \App\Service\Entreprise\EntreprisePortalContext $portal): Response
    {
        $this->member($entite); $u = $this->getUser(); $admin = $this->admin($entite);
        $studentContext = static::SPACE === 'stagiaire';
        $trainerContext = static::SPACE === 'formateur';
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

    protected function pick(array $objects, int $id): object
    {
        foreach ($objects as $object) if ($object->getId() === $id) return $object;
        throw new \InvalidArgumentException('Sélectionnez une affectation valide dans votre organisme.');
    }

    protected function access(Entite $e, HabilitationDossier $d): array
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

    protected function editDossier(Entite $entite, HabilitationDossier $dossier, Request $request, array $rights, ?Entreprise $entreprise = null, array $entreprisesDisponibles = []): Response
    {
        $studentContext = static::SPACE === 'stagiaire';
        $trainerContext = static::SPACE === 'formateur';
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

    protected function visibleRevision(HabilitationRevision $r, HabilitationDossier $d, array $rights): bool
    {
        if ($rights['admin']) return true;
        if (!in_array($r->getKind(), ['avis', 'titre'], true)) return false;
        $identity = $r->getSnapshot()['identity'] ?? [];
        if (($identity['traineeId'] ?? null) !== $d->getInscription()->getStagiaire()->getId() || ($identity['companyId'] ?? null) !== $d->getEntreprise()->getId()) return false;
        if ($rights['trainer']) return true;
        return in_array($r->getToken(), [$d->getAvisToken(), $d->getTitreToken()], true);
    }

    protected function renderPdf(#[MapEntity(id: 'entite')] Entite $entite, #[MapEntity(id: 'id')] HabilitationDossier $dossier, string $token, \App\Service\Habilitation\HabilitationDocument $documents, bool $attestation = false): Response
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
