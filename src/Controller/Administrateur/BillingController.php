<?php // src/Controller/Administrateur/BillingController.php
namespace App\Controller\Administrateur;

use App\Entity\{Entite, Utilisateur, UtilisateurEntite};
use App\Repository\Billing\{EntiteSubscriptionRepository, PlanRepository, AddonRepository, EntiteUsageYearRepository};
use App\Service\Billing\EntitlementService;
use App\Service\Tenant\TenantContext;
use App\Security\Permission\TenantPermission;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/administrateur/{entite}', name: 'app_administrateur_')]
final class BillingController extends AbstractController
{
  public function __construct(
    private UtilisateurEntiteManager $utilisateurEntiteManager,
    private TenantContext $tenant, // ✅
  ) {}

  #[Route('/billing', name: 'billing')]
  public function index(
    Entite $entite,
    EntiteSubscriptionRepository $subs,
    PlanRepository $plans,
    AddonRepository $addons,
    EntiteUsageYearRepository $usageRepo,
    EntitlementService $entitlement,
  ): Response {
    $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

    /** @var Utilisateur $user */
    $user = $this->getUser();

    // ✅ 1) vérifier membership utilisateur -> entité
    $ue = $this->utilisateurEntiteManager->getRepository()->findOneBy([
      'entite' => $entite,
      'utilisateur' => $user->getId(),
    ]);

    if (!$ue instanceof UtilisateurEntite) {
      throw $this->createAccessDeniedException("Vous n'avez pas accès à cet organisme.");
    }

    // ✅ 2) vérifier droits dans l'entité (admin entité)
    // ✅ droits via voter
    $this->denyAccessUnlessGranted(TenantPermission::BILLING_MANAGE, $entite);


    // ✅ 3) synchroniser l'entité courante (session + éventuellement user->entite)
    $this->tenant->setCurrentEntite($user, $entite);
    // NB: si setCurrentEntite modifie user->entite, il faut flush dans un subscriber ou ici
    // Ici on ne flush pas pour éviter un flush à chaque page, le subscriber proposé juste après le fera proprement.
    // Si tu veux flush ici: injecte EntityManagerInterface et $em->flush();

    $sub = $subs->findLatestForEntite($entite);
    $limits = $entitlement->limits($entite);

    $year = (int)(new \DateTimeImmutable())->format('Y');
    $usage = $usageRepo->getOrCreate($entite, $year);

    $trialRemainingDays = null;
    if ($sub && $sub->getStatus() === 'trialing' && $sub->getTrialEndsAt()) {
      $diff = $sub->getTrialEndsAt()->diff(new \DateTimeImmutable());
      $trialRemainingDays = $diff->invert ? (int) $diff->days : 0;
    }

    return $this->render('administrateur/billing/index.html.twig', [
      'sub' => $sub,
      'plans' => $plans->findActiveOrdered(),
      'addons' => $addons->findActiveOrdered(),
      'limits' => $limits,
      'usage' => $usage,
      'year' => $year,
      'entite' => $entite,
      'trialRemainingDays' => $trialRemainingDays,
      'utilisateurEntite' => $ue,
    ]);
  }
}
