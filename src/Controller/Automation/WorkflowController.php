<?php
namespace App\Controller\Automation;

use App\Entity\{Entite, Qcm};
use App\Entity\Automation\{TrainingWorkflow, WorkflowTask, WorkflowParticipant};
use App\Form\Automation\QuickWorkflowType;
use App\Security\Permission\TenantPermission;
use App\Service\Automation\{QuickWorkflowCreator, WorkflowPlanner, WorkflowPortal};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/administrateur/{entite}/dossiers-automatises', name: 'app_administrateur_workflow_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::ADMIN, subject: 'entite')]
final class WorkflowController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Entite $entite, Request $request, EntityManagerInterface $em): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        return $this->render('automation/index.html.twig', ['entite' => $entite, 'page' => $page,
            'workflows' => $em->getRepository(TrainingWorkflow::class)->findBy(['entite' => $entite], ['createdAt' => 'DESC'], 25, ($page - 1) * 25)]);
    }

    #[Route('/nouveau', name: 'new', methods: ['GET', 'POST'])]
    public function new(Entite $entite, Request $request, QuickWorkflowCreator $creator, WorkflowPlanner $planner): Response
    {
        $form = $this->createForm(QuickWorkflowType::class, null, ['entite' => $entite]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $workflow = $creator->create($entite, $this->getUser(), $form->getData());
                $planner->synchronize($workflow);
                $this->addFlash('success', 'Session, devis et convention préparés. Vérifiez le calendrier puis activez les envois.');
                return $this->redirectToRoute('app_administrateur_workflow_show', ['entite' => $entite->getId(), 'id' => $workflow->getId()]);
            } catch (\DomainException $e) { $form->addError(new FormError($e->getMessage())); }
        }
        return $this->render('automation/new.html.twig', ['entite' => $entite, 'form' => $form]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Entite $entite, TrainingWorkflow $workflow, EntityManagerInterface $em, WorkflowPortal $portal): Response
    {
        $this->checkTenant($entite, $workflow);
        return $this->render('automation/show.html.twig', ['entite' => $entite, 'workflow' => $workflow, 'labels' => WorkflowPlanner::LABELS,
            'tasks' => $em->getRepository(WorkflowTask::class)->findBy(['workflow' => $workflow], ['dueAt' => 'ASC', 'id' => 'ASC']),
            'participants' => $em->getRepository(WorkflowParticipant::class)->findBy(['workflow' => $workflow], ['position' => 'ASC']),
            'postQcms' => $em->getRepository(Qcm::class)->findBy(['entite' => $entite, 'isActive' => true], ['titre' => 'ASC']),
            'portalUrl' => $portal->url($workflow)]);
    }

    #[Route('/{id}/reglages', name: 'settings', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function settings(Entite $entite, TrainingWorkflow $workflow, Request $request, EntityManagerInterface $em, WorkflowPlanner $planner): Response
    {
        $this->checkTenant($entite, $workflow);
        if (!$this->isCsrfTokenValid('workflow_settings_' . $workflow->getId(), $request->request->getString('_token'))) throw $this->createAccessDeniedException();
        $action = $request->request->getString('action');
        if ($action === 'rotate') { $workflow->rotatePortalNonce(); $this->addFlash('success', 'L’ancien lien client a été révoqué.'); }
        elseif ($action === 'save') {
            $email = $request->request->getString('email');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 180) throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Email du contact invalide.');
            $postQcmId = $request->request->getString('postQcmId', (string) ($workflow->getOptions()['postQcmId'] ?? ''));
            $postQcm = null;
            if ($postQcmId !== '') {
                if (!ctype_digit($postQcmId) || (int) $postQcmId < 1) throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Choix du QCM invalide.');
                $postQcm = $em->getRepository(Qcm::class)->findOneBy(['id' => (int) $postQcmId, 'entite' => $entite, 'isActive' => true]);
                if (!$postQcm) throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Choisissez un QCM actif de cet organisme.');
            }
            $workflow->setContactEmail($email)->setEnabled($request->request->getBoolean('enabled'))
                ->setRenewalEnabled($request->request->getBoolean('renewalEnabled') && $workflow->getValidityMonths() !== null && !$workflow->isRenewalOptOut());
            $workflow->setOptions(array_replace($workflow->getOptions(), ['autoImportParticipants' => $request->request->getBoolean('autoImportParticipants'), 'postQcmId' => $postQcm?->getId()]));
            $this->addFlash('success', $workflow->isEnabled() ? 'Automatisation activée. Les actions dues seront traitées par le planificateur.' : 'Automatisation en pause.');
        } else throw $this->createNotFoundException();
        $em->flush(); $planner->synchronize($workflow);
        return $this->redirectToRoute('app_administrateur_workflow_show', ['entite' => $entite->getId(), 'id' => $workflow->getId()]);
    }

    private function checkTenant(Entite $entite, TrainingWorkflow $w): void
    { if ($w->getEntite()?->getId() !== $entite->getId()) throw $this->createNotFoundException(); }
}
