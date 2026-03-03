<?php

namespace App\Security;

use App\Entity\Entite;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurEntite;
use App\Repository\UtilisateurEntiteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;



final class RedirectAfterLogin
{
  public function __construct(
    private readonly RouterInterface $router,
    private readonly EntityManagerInterface $em,
    private readonly UtilisateurEntiteRepository $ueRepo,
  ) {}

  public function redirect(Request $request, Utilisateur $u): RedirectResponse
  {
    $session = $request->getSession();
    $targetPath = $session?->get('_security.main.target_path');

    // 1) Si on a tenté d'accéder à /administrateur/{entite}/...
    if (is_string($targetPath) && preg_match('#/administrateur/(\d+)(/|$)#', $targetPath, $m)) {
      $entiteId = (int) $m[1];
      $entite = $this->em->getRepository(Entite::class)->find($entiteId);

      $ue = $entite ? $this->ueRepo->findOneBy(['utilisateur' => $u, 'entite' => $entite]) : null;

      if (!$ue instanceof UtilisateurEntite || !$ue->isActive()) {
        $session?->remove('_security.main.target_path');
        return new RedirectResponse($this->router->generate('app_onboarding'));
      }

      if (!$this->canAccessAdminArea($ue)) {
        $session?->remove('_security.main.target_path');
        return $this->redirectByMembershipRoles($u, $ue);
      }

      return new RedirectResponse($targetPath);
    }

    // 2) Sinon, rediriger selon memberships actives
    $memberships = $this->ueRepo->findBy(['utilisateur' => $u], ['id' => 'ASC']);
    $memberships = array_values(array_filter(
      $memberships,
      fn(UtilisateurEntite $ue) => $ue->isActive() && $ue->getEntite()
    ));

    if (count($memberships) === 0) {
      return new RedirectResponse($this->router->generate('app_onboarding'));
    }

    if (count($memberships) === 1) {
      return $this->redirectByMembershipRoles($u, $memberships[0]);
    }

    return new RedirectResponse($this->router->generate('app_workspace'));
  }

  private function canAccessAdminArea(UtilisateurEntite $ue): bool
  {
    return $ue->isTenantAdmin(); // ou tenant_dirigeant + tenant_admin selon ton modèle
  }

  private function redirectByMembershipRoles(Utilisateur $u, UtilisateurEntite $ue): RedirectResponse
  {
    $entite = $ue->getEntite();
    if (!$entite || !$entite->getId()) {
      return new RedirectResponse($this->router->generate('app_onboarding'));
    }

    $entiteId = $entite->getId();

    if ($ue->isTenantAdmin()) {
      return new RedirectResponse($this->router->generate('app_administrateur_dashboard_index', [
        'entite' => $entiteId,
      ]));
    }

    if ($ue->hasRole(UtilisateurEntite::TENANT_FORMATEUR)) {
      return new RedirectResponse($this->router->generate('app_formateur_dashboard', [
        'entite' => $entiteId,
      ]));
    }

    if ($ue->hasRole(UtilisateurEntite::TENANT_ENTREPRISE)) {
      if ($u->getEntreprise() !== null) {
        return new RedirectResponse($this->router->generate('app_entreprise_dashboard', [
          'entite' => $entiteId,
        ]));
      }
    }

    if ($ue->hasRole(UtilisateurEntite::TENANT_OPCO)) {
      return new RedirectResponse($this->router->generate('app_opco_dashboard', [
        'entite' => $entiteId,
      ]));
    }

    if ($ue->hasRole(UtilisateurEntite::TENANT_COMMERCIAL)) {
      return new RedirectResponse($this->router->generate('app_commercial_dashboard', [
        'entite' => $entiteId,
      ]));
    }

    return new RedirectResponse($this->router->generate('app_stagiaire_dashboard', [
      'entite' => $entiteId,
    ]));
  }
}
