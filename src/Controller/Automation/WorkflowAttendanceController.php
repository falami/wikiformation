<?php
namespace App\Controller\Automation;

use App\Entity\{Entite, Session, Inscription};
use App\Entity\Automation\TrainingWorkflow;
use App\Enum\StatusInscription;
use App\Security\Permission\TenantPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class WorkflowAttendanceController extends AbstractController
{
    #[Route('/administrateur/{entite}/dossiers-automatises/{id}/qr', name: 'app_administrateur_workflow_qr', methods: ['GET'], requirements: ['entite'=>'\d+', 'id'=>'\d+'])]
    #[IsGranted(TenantPermission::ADMIN, subject: 'entite')]
    public function qr(Entite $entite, TrainingWorkflow $workflow): Response
    {
        if ($workflow->getEntite()?->getId() !== $entite->getId()) throw $this->createNotFoundException();
        $url = $this->generateUrl('app_workflow_attendance', ['entite' => $entite->getId(), 'session' => $workflow->getSession()->getId()], 0);
        return $this->render('automation/qr.html.twig', ['entite'=>$entite, 'workflow'=>$workflow, 'attendanceUrl'=>$url]);
    }

    #[Route('/stagiaire/{entite}/presence/{session}', name: 'app_workflow_attendance', methods: ['GET'], requirements: ['entite'=>'\d+', 'session'=>'\d+'])]
    #[IsGranted(TenantPermission::STAGIAIRE_EMARGEMENT_MANAGE, subject: 'entite')]
    public function attend(Entite $entite, Session $session, EntityManagerInterface $em): Response
    {
        if ($session->getEntite()?->getId() !== $entite->getId()) throw $this->createNotFoundException();
        $i = $em->getRepository(Inscription::class)->findOneBy(['entite'=>$entite,'session'=>$session,'stagiaire'=>$this->getUser()]);
        if (!$i || in_array($i->getStatus(), [StatusInscription::ANNULE,StatusInscription::ABSENT], true)) throw $this->createAccessDeniedException('Vous devez être inscrit à cette session.');
        if (!$session->isEmargementRequis()) throw $this->createAccessDeniedException('Les présences sont gérées par l’organisme donneur d’ordre.');
        // Scanning only opens the existing personal signature flow; it never signs attendance by itself.
        return $this->redirect($this->generateUrl('app_stagiaire_session_show', ['entite'=>$entite->getId(),'id'=>$session->getId()]) . '#timeline');
    }
}
