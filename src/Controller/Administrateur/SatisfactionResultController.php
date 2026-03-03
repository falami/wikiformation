<?php
// src/Controller/Administrateur/SatisfactionResultController.php
namespace App\Controller\Administrateur;

use App\Entity\{Session, Entite};
use App\Repository\QuestionnaireSatisfactionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Security\Permission\TenantPermission;





#[Route('/administrateur/{entite}/satisfaction', name: 'app_administrateur_satisfaction_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::SATISFACTION_RESULT_MANAGE, subject: 'entite')]
class SatisfactionResultController extends AbstractController
{
  #[Route('/session/{session}', name: 'session', methods: ['GET'], requirements: ['session' => '\d+'])]
  public function session(Entite $entite, Session $session, QuestionnaireSatisfactionRepository $repo): Response
  {
    $items = $repo->findSubmittedForSession($session);

    return $this->render('administrateur/satisfaction/result/session.html.twig', [
      'entite' => $entite,
      'session' => $session,
      'items' => $items,
    ]);
  }
}
