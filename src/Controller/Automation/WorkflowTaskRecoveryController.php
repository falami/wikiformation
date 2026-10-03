<?php

declare(strict_types=1);

namespace App\Controller\Automation;

use App\Entity\Automation\WorkflowTask;
use App\Entity\{Entite, Utilisateur};
use App\Security\Permission\TenantPermission;
use App\Service\Automation\WorkflowTaskRecovery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;

final class WorkflowTaskRecoveryController extends AbstractController
{
    #[Route('/administrateur/{entite}/dossiers-automatises/etape/{id}/rapprocher', name: 'app_administrateur_workflow_task_recover', requirements: ['entite' => '\d+', 'id' => '\d+'], methods: ['POST'])]
    public function recover(Entite $entite, WorkflowTask $task, Request $request, WorkflowTaskRecovery $recovery): Response
    {
        $this->denyAccessUnlessGranted(TenantPermission::ADMIN, $entite);
        if ($task->getWorkflow()?->getEntite()?->getId() !== $entite->getId()) throw $this->createNotFoundException();
        if (!$this->isCsrfTokenValid('workflow_recovery_' . $task->getId(), $request->request->getString('_token'))) throw $this->createAccessDeniedException();
        $actor = $this->getUser();
        if (!$actor instanceof Utilisateur) throw $this->createAccessDeniedException();
        try {
            if ($request->request->getString('verified') !== '1') throw new \DomainException('Confirmez avoir vérifié le document et la messagerie.');
            $recovery->recover($entite, $task, $actor, $request->request->getString('decision'), $request->request->getString('reason'), $request->request->getString('revision'));
            $this->addFlash('success', 'Votre vérification est enregistrée dans l’historique. Aucun message n’a été envoyé par cette action.');
        } catch (\DomainException $e) {
            $this->addFlash('warning', $e->getMessage());
        }
        return $this->redirectToRoute('app_administrateur_workflow_show', ['entite' => $entite->getId(), 'id' => $task->getWorkflow()->getId()], Response::HTTP_SEE_OTHER);
    }
}
