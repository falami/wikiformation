<?php

declare(strict_types=1);

namespace App\Controller\Stagiaire;

use App\Entity\{Formation, Entite, Utilisateur};
use App\Repository\FormationContentNodeRepository;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Repository\FormationRepository;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Security\Permission\TenantPermission;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;


#[Route('/stagiaire/{entite}/formation', name: 'app_stagiaire_formation_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::STAGIAIRE_FORMATION_CONTENT_MANAGE, subject: 'entite')]
final class FormationContentController extends AbstractController
{
    public function __construct(
        private UtilisateurEntiteManager $utilisateurEntiteManager,
    ) {}
    #[Route('/{slug}/contenu', name: 'contenu', methods: ['GET'], requirements: ['slug' => '[^/]+'])]
    public function show(
        Entite $entite,
        #[MapEntity(expr: 'repository.findOneBySlug(slug)')]
        Formation $formation,
        FormationContentNodeRepository $repo,
        FormationRepository $formationRepo,
    ): Response {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        /*if (!$formationRepo->isPaidForStagiaire($user, $entite, $formation)) {
            throw new AccessDeniedHttpException('Accès refusé : formation non suivie ou non payée.');
        }*/


        $roots = $repo->rootsForFormation($formation);

        return $this->render('stagiaire/formation/contenu.html.twig', [
            'formation' => $formation,
            'roots'     => $roots,
            'entite' => $entite,

        ]);
    }
}
