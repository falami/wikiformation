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
use Dompdf\{Dompdf, Options};
use Symfony\Bridge\Doctrine\Attribute\MapEntity;

#[Route('/habilitation/{entite}', name: 'app_habilitation_', requirements: ['entite' => '\d+'])]
final class HabilitationController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em, private HabilitationSchema $schema, private HabilitationWorkflow $workflow) {}

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

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(#[MapEntity(id: 'entite')] Entite $entite, Request $request): Response
    {
        $this->member($entite); $u = $this->getUser(); $admin = $this->admin($entite);
        $trainer = $this->isGranted(P::FORMATEUR, $entite); $company = $this->isGranted(P::ENTREPRISE, $entite); $student = $this->isGranted(P::STAGIAIRE, $entite);
        if (!$admin && !$trainer && !$company && !$student) throw $this->createAccessDeniedException();
        $qb = $this->em->createQueryBuilder()->select('d', 'i', 's', 'u', 'c')->from(HabilitationDossier::class, 'd')
            ->join('d.inscription', 'i')->join('i.session', 's')->join('i.stagiaire', 'u')->join('d.entreprise', 'c')
            ->where('d.entite = :e')->setParameter('e', $entite)->orderBy('d.createdAt', 'DESC');
        if (!$admin) {
            $allowed = [];
            if ($trainer) { $allowed[] = 'd.formateur = :me'; $qb->setParameter('me', $u); }
            if ($company && $u->getEntreprise()) { $allowed[] = '(d.entreprise = :company AND d.avisToken IS NOT NULL)'; $qb->setParameter('company', $u->getEntreprise()); }
            if ($student) { $allowed[] = "(i.stagiaire = :student AND d.state = 'published')"; $qb->setParameter('student', $u); }
            $qb->andWhere($allowed ? '('.implode(' OR ', $allowed).')' : '1 = 0')->andWhere('d.active = true AND d.deleted = false');
        }
        $q = mb_substr(trim($request->query->getString('q')), 0, 100);
        if ($q !== '') $qb->andWhere('s.code LIKE :q OR u.nom LIKE :q OR u.prenom LIKE :q OR c.raisonSociale LIKE :q')->setParameter('q', '%'.$q.'%');
        $page = max(1, $request->query->getInt('page', 1));
        $rows = $qb->setFirstResult(($page - 1) * 30)->setMaxResults(31)->getQuery()->getResult();
        $more = count($rows) > 30;
        return $this->privateRender('index', $this->base($entite) + ['dossiers' => array_slice($rows, 0, 30), 'canCreate' => $admin || $trainer, 'q' => $q, 'page' => $page, 'more' => $more]);
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
        if (!$admin && ($id || !$this->isGranted(P::FORMATEUR, $entite))) throw $this->createAccessDeniedException();
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
                $f = $this->pick($trainers, $request->request->getInt('formateur'));
                if (!$admin && ($f->getUtilisateur()?->getId() !== $me->getId() || $c->getId() !== ($i->getEntreprise() ?? $i->getStagiaire()->getEntreprise())?->getId() || $t->getId() !== $i->getSession()->getFormation()?->getHabilitationTemplate()?->getId())) throw new \InvalidArgumentException('L’affectation doit correspondre à votre session, à son modèle et à l’employeur du stagiaire.');
                $existing = $this->em->getRepository(HabilitationDossier::class)->findOneBy(['inscription' => $i]);
                if ($existing && $existing !== $d) throw new \InvalidArgumentException('Cette inscription possède déjà un dossier. Ouvrez-le depuis la liste (ou demandez sa réactivation).');
                if (!$f->getUtilisateur()) throw new \InvalidArgumentException('Le formateur doit disposer d’un compte.');
                $previous = $d ? $this->workflow->identity($d) : null;
                $d ??= (new HabilitationDossier())->setEntite($entite);
                $d->setInscription($i)->setTemplate($t)->setSchema($t->getSchema())->setFormateur($f->getUtilisateur())->setEntreprise($c)
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
        $trainer = $this->isGranted(P::FORMATEUR, $e) && $d->getFormateur()->getId() === $u->getId();
        $company = $this->isGranted(P::ENTREPRISE, $e) && $d->getEntreprise()->getId() === $u->getEntreprise()?->getId();
        $student = $this->isGranted(P::STAGIAIRE, $e) && $d->getInscription()->getStagiaire()->getId() === $u->getId() && $d->getState() === 'published';
        if (!$admin && ((!$d->getActive() || $d->getDeleted()) || (!$trainer && !($company && $d->getAvisToken()) && !$student))) throw $this->createAccessDeniedException();
        return compact('admin', 'trainer', 'company', 'student');
    }

    #[Route('/{id}', name: 'show', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function show(#[MapEntity(id: 'entite')] Entite $entite, #[MapEntity(id: 'id')] HabilitationDossier $dossier, Request $request): Response
    {
        $rights = $this->access($entite, $dossier);
        $stage = $request->query->getString('stage', $rights['company'] && !$rights['trainer'] && !$rights['admin'] ? 'titre' : 'avis');
        if (!in_array($stage, ['avis', 'titre'], true)) throw $this->createNotFoundException();
        $editable = ($rights['admin'] || ($stage === 'avis' ? $rights['trainer'] : $rights['company'])) && $dossier->getActive() && !$dossier->getDeleted() && ($stage === 'avis' || $dossier->getAvisToken());
        $canSign = $stage === 'avis' ? $rights['trainer'] : $rights['company'];
        $data = $stage === 'avis' ? $dossier->getTrainerData() : $dossier->getEmployerData();
        if ($stage === 'titre' && !$data) {
            $data = $dossier->getTrainerData(); unset($data['issuedAt'], $data['validUntil'], $data['signerFunction']);
            foreach ($dossier->getSchema()['sections'] as $section) foreach ($section['rows'] as $row) if (($data['rows'][$row['id']]['verdict'] ?? '') !== 'favorable') foreach ($row['fields'] as $field) if ($field['type'] === 'checkbox') $data['rows'][$row['id']][$field['id']] = [];
        }
        $error = null;
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
                $this->addFlash('success', $sign ? ($stage === 'avis' ? 'Avis signé et transmis à l’espace entreprise.' : 'Titre signé et disponible dans l’espace stagiaire.') : 'Brouillon enregistré. Les anciennes versions signées restent dans l’historique ; une nouvelle signature est nécessaire.');
                return $this->redirectToRoute('app_habilitation_show', ['entite' => $entite->getId(), 'id' => $dossier->getId(), 'stage' => $stage]);
            } catch (\InvalidArgumentException $e) { $error = $e->getMessage(); }
            catch (OptimisticLockException $e) { return new Response('Ce dossier a été modifié dans une autre fenêtre. Rechargez avant de recommencer.', 409); }
        }
        $revisions = $this->em->getRepository(HabilitationRevision::class)->findBy(['dossier' => $dossier], ['id' => 'DESC'], 100);
        $revisions = array_values(array_filter($revisions, fn ($r) => $this->visibleRevision($r, $dossier, $rights)));
        return $this->privateRender('show', $this->base($entite) + compact('dossier', 'rights', 'stage', 'editable', 'canSign', 'data', 'error', 'revisions'), $error ? 422 : 200);
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

    #[Route('/{id}/document/{token}', name: 'pdf', methods: ['GET'], requirements: ['id' => '\d+', 'token' => '[a-f0-9]{32}'])]
    public function pdf(#[MapEntity(id: 'entite')] Entite $entite, #[MapEntity(id: 'id')] HabilitationDossier $dossier, string $token): Response
    {
        $rights = $this->access($entite, $dossier);
        $r = $this->em->getRepository(HabilitationRevision::class)->findOneBy(['dossier' => $dossier, 'token' => $token]);
        if (!$r || !$this->visibleRevision($r, $dossier, $rights) || !in_array($r->getKind(), ['avis', 'titre'], true)) throw $this->createNotFoundException();
        $options = new Options(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
        $pdf = new Dompdf($options); $pdf->setPaper('A4', 'landscape');
        $snapshot = $r->getSnapshot();
        if ($r->getKind() === 'titre') {
            foreach ($snapshot['schema']['sections'] as &$section) {
                $section['rows'] = array_values(array_filter($section['rows'], static function ($row) use ($snapshot) {
                    foreach ($row['fields'] as $field) if ($field['type'] === 'checkbox' && !empty($snapshot['data']['rows'][$row['id']][$field['id']])) return true;
                    return false;
                }));
            }
            unset($section);
            $snapshot['schema']['sections'] = array_values(array_filter($snapshot['schema']['sections'], static fn ($section) => $section['rows'] !== []));
        }
        $pdf->loadHtml($this->renderView('habilitation/pdf.html.twig', ['revision' => $r, 'snapshot' => $snapshot, 'archived' => !in_array($token, [$dossier->getAvisToken(), $dossier->getTitreToken()], true), 'inactive' => !$dossier->getActive() || $dossier->getDeleted()]));
        $pdf->render();
        return new Response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="habilitation-'.$dossier->getId().'-'.$r->getKind().'-'.substr($token, 0, 8).'.pdf"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
