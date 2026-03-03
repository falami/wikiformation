<?php

namespace App\Controller\Administrateur;

use App\Entity\{QcmAssignment, Entite, Utilisateur};
use App\Enum\QcmAssignmentStatus;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use App\Security\Permission\TenantPermission;



#[Route('/administrateur/{entite}/qcm/follow-up', name: 'app_administrateur_qcm_followup_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::QCM_FOLLOW_UP_MANAGE, subject: 'entite')]
final class QcmFollowUpController extends AbstractController
{
  public function __construct(
    private UtilisateurEntiteManager $utilisateurEntiteManager,
  ) {}
  #[Route('/{id}', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
  public function edit(Entite $entite, QcmAssignment $a, Request $req, EM $em): Response
  {
    if ($a->getSession()?->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createAccessDeniedException();
    }
    /** @var Utilisateur $user */
    $user = $this->getUser();

    if ($req->isMethod('POST')) {
      if (!$this->isCsrfTokenValid('qcm_followup_' . $a->getId(), (string)$req->request->get('_token'))) {
        throw $this->createAccessDeniedException('CSRF invalide');
      }

      $note = trim((string)$req->request->get('note'));
      if ($note === '') {
        $this->addFlash('danger', 'Le champ action corrective est obligatoire.');
      } else {
        $a->setAdminFollowUp($note, $this->getUser());
        $a->setStatus(QcmAssignmentStatus::VALIDATED);
        $em->flush();
        $this->addFlash('success', 'Action corrective enregistrée.');
        return $this->redirectToRoute('app_administrateur_qcm_index', [
          'entite' => $entite->getId(),
        ]);
      }
    }

    return $this->render('administrateur/qcm/followup.html.twig', [
      'entite' => $entite,
      'a' => $a,
    ]);
  }
}
