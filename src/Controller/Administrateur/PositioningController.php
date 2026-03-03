<?php
// src/Controller/Administrateur/PositioningAdminController.php
declare(strict_types=1);

namespace App\Controller\Administrateur;

use App\Entity\{PositioningAttempt, Entite, Utilisateur};
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use App\Repository\PositioningAnswerRepository;
use App\Security\Permission\TenantPermission;


#[Route('/administrateur/{entite}/positionnements', name: 'app_administrateur_positioning_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::POSITIONING_MANAGE, subject: 'entite')]
final class PositioningController extends AbstractController
{

  public function __construct(
    private UtilisateurEntiteManager $utilisateurEntiteManager,
  ) {}

  #[Route('/{attempt}', name: 'show', methods: ['GET'], requirements: ['attempt' => '\d+'])]
  public function show(Entite $entite, PositioningAttempt $attempt, PositioningAnswerRepository $answerRepo): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    if ($attempt->getQuestionnaire()?->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException();
    }

    $answers = $answerRepo->findForAttemptOrdered($attempt);

    return $this->render('administrateur/positioning/show.html.twig', [
      'entite' => $entite,
      'attempt' => $attempt,
      'answers' => $answers, // ✅ triés


    ]);
  }
}
