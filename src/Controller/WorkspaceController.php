<?php

namespace App\Controller;

use App\Entity\Entite;
use App\Entity\Utilisateur;
use App\Repository\UtilisateurEntiteRepository;
use App\Service\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Entity\UtilisateurEntite;
use Symfony\Component\HttpFoundation\Request;

final class WorkspaceController extends AbstractController
{
  #[Route('/workspace', name: 'app_workspace', methods: ['GET'])]
  public function index(UtilisateurEntiteRepository $ueRepo): Response
  {
    $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
    /** @var Utilisateur $user */
    $user = $this->getUser();

    $ues = $ueRepo->findAllForUser($user);

    $isTenantDirigeant = $ueRepo->isTenantDirigeant($user);

    return $this->render('workspace/index.html.twig', [
      'memberships' => $ues,
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
  ): Response {
    $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

    /** @var Utilisateur $user */
    $user = $this->getUser();

    // (optionnel mais recommandé) CSRF
    if (!$this->isCsrfTokenValid('switch_entite_' . $entite->getId(), (string) $request->request->get('_token'))) {
      throw $this->createAccessDeniedException('CSRF invalide.');
    }

    $tenant->setCurrentEntite($user, $entite);
    $entite->touchActivity();
    $em->flush();

    $membership = $ueRepo->findMembership($user, $entite);
    if (!$membership) {
      throw $this->createAccessDeniedException('Aucun accès à cette entité.');
    }

    $roles = $membership->getRoles();

    // Priorités (du plus puissant au plus "simple")
    if (in_array(UtilisateurEntite::TENANT_DIRIGEANT, $roles, true) || in_array(UtilisateurEntite::TENANT_ADMIN, $roles, true)) {
      return $this->redirectToRoute('app_administrateur_dashboard_index', ['entite' => $entite->getId()]);
    }

    if (in_array(UtilisateurEntite::TENANT_OF, $roles, true)) {
      return $this->redirectToRoute('app_of_dashboard', ['entite' => $entite->getId()]);
    }

    if (in_array(UtilisateurEntite::TENANT_COMMERCIAL, $roles, true)) {
      return $this->redirectToRoute('app_commercial_dashboard', ['entite' => $entite->getId()]);
    }

    if (in_array(UtilisateurEntite::TENANT_FORMATEUR, $roles, true)) {
      return $this->redirectToRoute('app_formateur_dashboard', ['entite' => $entite->getId()]);
    }

    if (in_array(UtilisateurEntite::TENANT_ENTREPRISE, $roles, true)) {
      return $this->redirectToRoute('app_entreprise_dashboard', ['entite' => $entite->getId()]);
    }

    if (in_array(UtilisateurEntite::TENANT_OPCO, $roles, true)) {
      return $this->redirectToRoute('app_opco_dashboard', ['entite' => $entite->getId()]);
    }

    // Fallback stagiaire (ou page “extranet”)
    return $this->redirectToRoute('app_stagiaire_dashboard', ['entite' => $entite->getId()]);
  }
}
