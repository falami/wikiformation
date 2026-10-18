<?php

declare(strict_types=1);
namespace App\Controller\Administrateur;
use App\Entity\Entite;
use App\Security\Permission\TenantPermission;
use App\Service\TrainerReminder\TrainerCompliance;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request,Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class TrainerComplianceController extends AbstractController
{
    #[Route('/administrateur/{entite}/dashboard/relance-formateur', name:'app_admin_trainer_compliance_remind', methods:['POST'])]
    #[IsGranted(TenantPermission::ADMIN_DASHBOARD_MANAGE, subject:'entite')]
    #[IsGranted(TenantPermission::FORMATEUR_MANAGE, subject:'entite')]
    public function remind(Entite $entite, Request $request, TrainerCompliance $compliance): Response
    {
        if (!$this->isCsrfTokenValid('trainer_compliance_'.$entite->getId(),$request->request->getString('_token'))) throw $this->createAccessDeniedException();
        $sentAt = null;
        $result=$compliance->send($entite,$request->request->getString('key'), $sentAt);
        if ($request->isXmlHttpRequest()) {
            return $this->json(['lastReminderAt'=>$sentAt,'sent'=>$result==='sent'?1:0,'skipped'=>$result==='skipped'?1:0,'failed'=>$result==='unknown'?1:0]);
        }
        $this->addFlash($result==='sent'?'success':'warning', match($result) {
            'sent'=>'La relance a été transmise au service de messagerie.',
            'unknown'=>'Envoi non confirmé : vérifiez la messagerie avant de renouveler la relance. Le nouvel envoi est bloqué pour éviter un doublon.',
            default=>'Aucun envoi : élément déjà régularisé, compte indisponible ou relance déjà effectuée dans les dernières 24 heures.'
        });
        return $this->redirectToRoute('app_administrateur_dashboard_index',['entite'=>$entite->getId(),'_fragment'=>'trainer-compliance']);
    }
}
