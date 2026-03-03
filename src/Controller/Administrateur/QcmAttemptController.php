<?php

declare(strict_types=1);

namespace App\Controller\Administrateur;

use App\Entity\{QcmAttempt, Entite, Utilisateur};
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Security\Permission\TenantPermission;


#[Route('/administrateur/{entite}/qcm/attempts', name: 'app_administrateur_qcm_attempt_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::QCM_ATTEMPT_MANAGE, subject: 'entite')]
final class QcmAttemptController extends AbstractController
{
  public function __construct(private UtilisateurEntiteManager $uem) {}

  #[Route('/{attempt}', name: 'show', methods: ['GET'], requirements: ['attempt' => '\d+'])]
  public function show(Entite $entite, QcmAttempt $attempt, EM $em): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    $ue = $this->uem->getUserEntiteLink($entite);
    if (!$ue) {
      throw $this->createAccessDeniedException('Accès interdit à cette entité.');
    }

    // ✅ Sécurité session -> entité (comme Satisfaction)
    $assignment = $attempt->getAssignment();
    $session = $assignment?->getSession();

    if (!$assignment || !$session || $session->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException();
    }

    $inscription = $assignment->getInscription();
    $stagiaire = $inscription?->getStagiaire();
    $qcm = $assignment->getQcm();
    $phase = $assignment->getPhase();

    return $this->render('administrateur/qcm/attempt/show.html.twig', [
      'entite' => $entite,
      'utilisateurEntite' => $ue,
      'attempt' => $attempt,
      'assignment' => $assignment,
      'session' => $session,
      'inscription' => $inscription,
      'stagiaire' => $stagiaire,
      'qcm' => $qcm,
      'phase' => $phase,
      'title' => 'Tentative QCM',
    ]);
  }
}
