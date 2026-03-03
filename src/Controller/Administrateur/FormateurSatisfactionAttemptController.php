<?php

declare(strict_types=1);

namespace App\Controller\Administrateur;

use App\Entity\{FormateurSatisfactionAttempt, Entite, Utilisateur};
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Security\Permission\TenantPermission;



#[Route('/administrateur/{entite}/formateur-satisfaction/attempts', name: 'app_administrateur_formateur_satisfaction_attempt_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::FORMATEUR_SATISFACTION_ATTEMPT_MANAGE, subject: 'entite')]
final class FormateurSatisfactionAttemptController extends AbstractController
{
  public function __construct(private UtilisateurEntiteManager $uem) {}

  #[Route('/{attempt}', name: 'show', methods: ['GET'], requirements: ['attempt' => '\d+'])]
  public function show(Entite $entite, FormateurSatisfactionAttempt $attempt, EM $em): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    $ue = $this->uem->getRepository()->findOneBy(['entite' => $entite, 'utilisateur' => $user]);
    if (!$ue) throw $this->createAccessDeniedException('Accès interdit à cette entité.');

    $assignment = $attempt->getAssignment();
    $session = $assignment?->getSession();
    if (!$session || $session->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException();
    }

    return $this->render('administrateur/formateur/satisfaction/attempt/show.html.twig', [
      'entite' => $entite,
      'utilisateurEntite' => $ue,
      'attempt' => $attempt,
      'assignment' => $assignment,
      'session' => $session,
      'formateur' => $assignment?->getFormateur(),
      'template' => $assignment?->getTemplate(),
      'title' => 'Tentative formateur (satisfaction)',
    ]);
  }
}
