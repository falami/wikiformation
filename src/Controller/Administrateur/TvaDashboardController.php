<?php
// src/Controller/Administrateur/TvaDashboardController.php
declare(strict_types=1);

namespace App\Controller\Administrateur;

use App\Entity\{Entite, Utilisateur};
use App\Repository\DepenseCategorieRepository;
use App\Repository\DepenseFournisseurRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use App\Security\Permission\TenantPermission;





#[Route('/administrateur/{entite}/tva', name: 'app_administrateur_tva_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::TVA_DASHBOARD_MANAGE, subject: 'entite')]
final class TvaDashboardController extends AbstractController
{
  public function __construct(
    private UtilisateurEntiteManager $utilisateurEntiteManager,
  ) {}
  #[Route('', name: 'dashboard', methods: ['GET'])]
  public function dashboard(
    Entite $entite,
    DepenseCategorieRepository $catRepo,
    DepenseFournisseurRepository $fourRepo,
  ): Response {
    /** @var Utilisateur $user */
    $user = $this->getUser();
    $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
    $start = $now->modify('first day of january')->format('Y-m-d');
    $end   = $now->format('Y-m-d');

    return $this->render('administrateur/tva/dashboard.html.twig', [
      'entite' => $entite,
      'start' => $start,
      'end' => $end,
      'categories' => $catRepo->findBy(['entite' => $entite], ['libelle' => 'ASC']),
      'fournisseurs' => $fourRepo->findBy(['entite' => $entite], ['nom' => 'ASC']),

    ]);
  }
}
