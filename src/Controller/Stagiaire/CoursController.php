<?php

declare(strict_types=1);

namespace App\Controller\Stagiaire;

use App\Entity\{Categorie, Entite, Utilisateur};
use App\Repository\CategorieRepository;
use App\Repository\FormationRepository;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Security\Permission\TenantPermission;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;



#[Route('/stagiaire/{entite}/cours', name: 'app_stagiaire_cours_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::STAGIAIRE_COURS_MANAGE, subject: 'entite')]
final class CoursController extends AbstractController
{
  public function __construct(
    private UtilisateurEntiteManager $utilisateurEntiteManager,
  ) {}

  #[Route('', name: 'index', methods: ['GET'])]
  public function index(
    #[MapEntity(id: 'entite')] Entite $entite,
    FormationRepository $formationRepo,
    CategorieRepository $categorieRepo,
  ): Response {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    // 1) toutes les formations payées
    $formations = $formationRepo->findPaidForStagiaire($user, $entite);

    // 2) on récupère les catégories liées à ces formations
    // Option A (simple) : on construit côté PHP
    $categories = [];
    foreach ($formations as $f) {
      if ($f->getCategorie()) {
        $categories[$f->getCategorie()->getId()] = $f->getCategorie();
      }
    }

    // (optionnel) trier par nom
    usort($categories, fn($a, $b) => strcmp($a->getNom(), $b->getNom()));

    return $this->render('stagiaire/cours/index.html.twig', [
      'entite'     => $entite,
      'categories' => $categories,
      'countFormations' => count($formations),


    ]);
  }

  #[Route('/categorie/{slug}', name: 'categorie', methods: ['GET'])]
  public function categorie(
    #[MapEntity(id: 'entite')] Entite $entite,
    #[MapEntity(expr: 'repository.findOneBySlug(slug)')] Categorie $categorie,
    FormationRepository $formationRepo,
  ): Response {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    $formations = $formationRepo->findPaidForStagiaireByCategorie($user, $entite, $categorie);

    return $this->render('stagiaire/cours/categorie.html.twig', [
      'entite'     => $entite,
      'categorie'  => $categorie,
      'formations' => $formations,


    ]);
  }
}
