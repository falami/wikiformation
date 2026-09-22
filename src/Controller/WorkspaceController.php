<?php

namespace App\Controller;

use App\Entity\Entite;
use App\Entity\Utilisateur;
use App\Repository\UtilisateurEntiteRepository;
use App\Service\Tenant\TenantContext;
use App\Security\MembershipHomeRoute;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;

final class WorkspaceController extends AbstractController
{
  #[Route('/workspace', name: 'app_workspace', methods: ['GET'])]
  public function index(UtilisateurEntiteRepository $ueRepo, MembershipHomeRoute $homeRoute): Response
  {
    $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
    /** @var Utilisateur $user */
    $user = $this->getUser();

    $ues = $ueRepo->findAllForUser($user);

    $isTenantDirigeant = false;
    $homeRoutes = [];
    foreach ($ues as $membership) {
      $homeRoutes[$membership->getId()] = $homeRoute->forMembership($membership);
      if ($membership->isActive()
          && $membership->getEntite()?->getId() === TenantContext::PLATFORM_ENTITE_ID
          && $membership->hasRole(\App\Entity\UtilisateurEntite::TENANT_DIRIGEANT)) {
        $isTenantDirigeant = true;
      }
    }

    return $this->render('workspace/index.html.twig', [
      'memberships' => $ues,
      'homeRoutes' => $homeRoutes,
      'user' => $user,
      'platformEntiteId' => TenantContext::PLATFORM_ENTITE_ID,
      'isTenantDirigeant' => $isTenantDirigeant,
    ]);
  }


  #[Route('/workspace/switch/{id}', name: 'app_workspace_switch', methods: ['POST'])]
  public function switchEntite(
    Entite $entite,
    TenantContext $tenant,
    EntityManagerInterface $em,
    UtilisateurEntiteRepository $ueRepo,
    Request $request,
    MembershipHomeRoute $homeRoute,
  ): Response {
    $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

    /** @var Utilisateur $user */
    $user = $this->getUser();

    // (optionnel mais recommandé) CSRF
    if (!$this->isCsrfTokenValid('switch_entite_' . $entite->getId(), (string) $request->request->get('_token'))) {
      throw $this->createAccessDeniedException('CSRF invalide.');
    }

    $membership = $ueRepo->findMembership($user, $entite);
    if (!$membership || !$membership->isActive()) {
      throw $this->createAccessDeniedException('Aucun accès actif à cette entité.');
    }

    $route = $homeRoute->forMembership($membership);
    if (!$route) {
      $this->addFlash('warning', 'Aucun espace dédié n’est disponible pour vos rôles dans cet organisme. Contactez son administrateur pour vérifier vos accès.');
      return $this->redirectToRoute('app_workspace');
    }

    $tenant->setCurrentEntite($user, $entite);
    $entite->touchActivity();
    $em->flush();

    return $this->redirectToRoute($route, ['entite' => $entite->getId()]);
  }
}
