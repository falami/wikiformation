<?php
// src/Controller/Administrateur/ProspectInteractionController.php
declare(strict_types=1);

namespace App\Controller\Administrateur;

use App\Entity\{ProspectInteraction, Entite, Utilisateur, Prospect};
use App\Form\Administrateur\ProspectInteractionType; // <- ton FormType
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, JsonResponse, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Security\Permission\TenantPermission;


#[Route('/administrateur/{entite}/prospects/{prospect}', name: 'app_administrateur_prospect_')]
#[IsGranted(TenantPermission::PROSPECT_INTERACTION_MANAGE, subject: 'entite')]
final class ProspectInteractionController extends AbstractController
{
  #[Route('/interaction/create', name: 'interaction_create', methods: ['POST'])]
  public function create(
    Entite $entite,
    Prospect $prospect,
    Request $request,
    EntityManagerInterface $em
  ): Response {
    // sécurité: s'assurer que le prospect appartient bien à l'entité
    if ($prospect->getEntite()?->getId() !== $entite->getId()) {
      return new JsonResponse(['ok' => false, 'error' => 'Accès refusé.'], 403);
    }
    /** @var Utilisateur $user */
    $user = $this->getUser();

    $interaction = new ProspectInteraction();
    $interaction->setCreateur($user);
    $interaction->setEntite($entite);
    $interaction->setProspect($prospect);

    $form = $this->createForm(ProspectInteractionType::class, $interaction, [
      'entite' => $entite,
      'current_user' => $this->getUser(), // 👈 ici
    ]);

    $form->handleRequest($request);

    if (!$form->isSubmitted() || !$form->isValid()) {
      // tu peux renvoyer les erreurs field par field si tu veux,
      // ici simple:
      return new JsonResponse([
        'ok' => false,
        'error' => 'Formulaire invalide. Vérifie les champs requis.'
      ], 422);
    }

    $em->persist($interaction);
    $em->flush();

    return new JsonResponse(['ok' => true]);
  }
}
