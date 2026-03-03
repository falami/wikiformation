<?php
// src/Controller/Administrateur/SatisfactionAttemptController.php
declare(strict_types=1);

namespace App\Controller\Administrateur;

use App\Entity\{SatisfactionAttempt, Entite, Utilisateur};
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Security\Permission\TenantPermission;


#[Route('/administrateur/{entite}/satisfaction/attempts', name: 'app_administrateur_satisfaction_attempt_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::SATISFACTION_ATTEMPT_MANAGE, subject: 'entite')]
final class SatisfactionAttemptController extends AbstractController
{
  public function __construct(private UtilisateurEntiteManager $utilisateurEntiteManager) {}

  #[Route('/{attempt}', name: 'show', methods: ['GET'], requirements: ['attempt' => '\d+'])]
  public function show(Entite $entite, SatisfactionAttempt $attempt, EM $em): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    $ue = $this->utilisateurEntiteManager->getUserEntiteLink($entite);
    if (!$ue) throw $this->createAccessDeniedException('Accès interdit à cette entité.');

    // sécurité via session->entite
    $assignment = $attempt->getAssignment();
    $session = $assignment?->getSession();
    if (!$session || $session->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException();
    }

    return $this->render('administrateur/satisfaction/attempt/show.html.twig', [
      'entite' => $entite,
      'utilisateurEntite' => $ue,
      'attempt' => $attempt,
      'assignment' => $assignment,
      'session' => $session,
      'stagiaire' => $assignment?->getStagiaire(),
      'template' => $assignment?->getTemplate(),
      'title' => 'Tentative de satisfaction',
    ]);
  }
}
