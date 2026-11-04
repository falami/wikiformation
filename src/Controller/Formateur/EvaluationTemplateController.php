<?php

declare(strict_types=1);
namespace App\Controller\Formateur;

use App\Entity\{Entite, EvaluationTemplate, Utilisateur};
use App\Service\Evaluation\{EvaluationLibrary, EvaluationWorkflow};
use App\Security\Permission\TenantPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/formateur/{entite}/evaluations/modeles', name:'app_formateur_evaluation_template_', requirements:['entite'=>'\d+'])]
#[IsGranted(TenantPermission::FORMATEUR, subject:'entite')]
final class EvaluationTemplateController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em, private EvaluationLibrary $library, private EvaluationWorkflow $workflow) {}
    private function actor(Entite $entite): Utilisateur
    {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur || !$this->library->trainerIn($user, $entite)) throw $this->createAccessDeniedException();
        return $user;
    }
    private function own(Entite $entite, EvaluationTemplate $model): void
    {
        if ($model->getTrainerOwner()?->getId() !== $this->actor($entite)->getId()) throw $this->createNotFoundException();
    }
    #[Route('', name:'index', methods:['GET'])]
    public function index(Entite $entite): Response
    {
        return $this->render('evaluation/formateur/library.html.twig', ['entite'=>$entite, 'templates'=>$this->em->getRepository(EvaluationTemplate::class)->findBy(['trainerOwner'=>$this->actor($entite)], ['id'=>'DESC'])]);
    }
    #[Route('/nouveau', name:'new', methods:['GET','POST'])]
    public function create(Entite $entite, Request $request): Response
    {
        $user = $this->actor($entite);
        return $this->editor($entite, (new EvaluationTemplate())->setEntite($entite)->setCreateur($user)->setTrainerOwner($user)->setActive(false), $request);
    }
    #[Route('/{id}/modifier', name:'edit', requirements:['id'=>'\d+'], methods:['GET','POST'])]
    public function edit(Entite $entite, EvaluationTemplate $template, Request $request): Response
    {
        $this->own($entite, $template);
        if ($this->library->locked($template) || $template->getArchivedAt()) return $this->render('evaluation/admin/template_locked.html.twig', ['entite'=>$entite, 'template'=>$template, 'trainerMode'=>true]);
        return $this->editor($entite, $template, $request);
    }
    private function editor(Entite $entite, EvaluationTemplate $template, Request $request): Response
    {
        $errors = [];
        $values = ['title'=>$template->getTitle(), 'active'=>$template->isActive(), 'schema'=>$template->getSchema() ?: ['theory'=>[], 'practice'=>[], 'theoryThreshold'=>null, 'practiceThreshold'=>null]];
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('evaluation_model_'.$entite->getId().'_'.($template->getId() ?? 'new'), $request->request->getString('_token'))) throw $this->createAccessDeniedException();
            $values['title'] = trim($request->request->getString('title')); $values['active'] = $request->request->getBoolean('active');
            try {
                $raw = $request->request->getString('schema');
                if (strlen($raw) > 500000) throw new \InvalidArgumentException('Le modèle est trop volumineux.');
                $schema = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
                if (!is_array($schema)) throw new \InvalidArgumentException('Contenu invalide.');
                $values['schema'] = $schema;
                if ($values['title'] === '' || mb_strlen($values['title']) > 180) throw new \InvalidArgumentException('Choisissez un titre de 1 à 180 caractères.');
                $schema = $this->workflow->validateTemplate($schema);
                $this->library->save($template, $request->request->getInt('modelVersion'), $values['title'], $schema, $values['active']);
                $this->addFlash('success', 'Modèle enregistré. Vous en restez propriétaire.');
                return $this->redirectToRoute('app_formateur_evaluation_template_index', ['entite'=>$entite->getId()]);
            } catch (\InvalidArgumentException|\JsonException $error) { $errors[] = $error->getMessage(); }
        }
        return $this->render('evaluation/admin/template_edit.html.twig', compact('entite','template','values','errors') + ['trainerMode'=>true]);
    }
    #[Route('/{id}/{action}', name:'lifecycle', requirements:['id'=>'\d+', 'action'=>'archiver|dupliquer'], methods:['POST'])]
    public function lifecycle(Entite $entite, EvaluationTemplate $template, string $action, Request $request): Response
    {
        $this->own($entite, $template);
        if (!$this->isCsrfTokenValid('evaluation_lifecycle_'.$template->getId(), $request->request->getString('_token'))) throw $this->createAccessDeniedException();
        if ($action === 'dupliquer') {
            $copy = $this->library->duplicate($template, $this->actor($entite));
            return $this->redirectToRoute('app_formateur_evaluation_template_edit', ['entite'=>$entite->getId(), 'id'=>$copy->getId()]);
        }
        $this->library->archive($template);
        $this->addFlash('success', 'Modèle archivé. Les attributions existantes sont conservées.');
        return $this->redirectToRoute('app_formateur_evaluation_template_index', ['entite'=>$entite->getId()]);
    }
}
