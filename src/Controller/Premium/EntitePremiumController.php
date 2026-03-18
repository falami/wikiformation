<?php

namespace App\Controller\Premium;

use App\Entity\Entite;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurEntite;
use App\Form\Premium\EntitePremiumType;
use App\Security\Permission\TenantPermission;
use App\Service\Entite\EntiteManager;
use App\Service\FileUploader;
use App\Service\Photo\PhotoManager;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;


#[Route('/premium/{entite}/entite', name: 'app_premium_entite_', requirements: ['entite' => '\d+', 'id' => '\d+'])]
#[IsGranted(TenantPermission::PREMIUM_MANAGE, subject: 'entite')]
final class EntitePremiumController extends AbstractController
{
    public function __construct(
        private PhotoManager $photoManager,
        private EntiteManager $entiteManager,
        private UtilisateurEntiteManager $utilisateurEntiteManager,
    ) {}

    private function denyIfNoEntiteAccess(Entite $entite): UtilisateurEntite
    {
        $user = $this->getUser();

        if (!$user instanceof Utilisateur) {
            throw $this->createAccessDeniedException('Utilisateur non authentifié.');
        }

        if ($this->isGranted('ROLE_SUPER')) {
            $utilisateurEntite = $this->utilisateurEntiteManager->getRepository()
                ->createQueryBuilder('ue')
                ->andWhere('ue.entite = :entite')
                ->setParameter('entite', $entite)
                ->addOrderBy('ue.id', 'ASC')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if (!$utilisateurEntite instanceof UtilisateurEntite) {
                throw $this->createNotFoundException('Aucune relation utilisateur/entité trouvée pour cette entité.');
            }

            return $utilisateurEntite;
        }

        $this->denyAccessUnlessGranted(TenantPermission::BILLING_MANAGE, $entite);

        $utilisateurEntite = $this->utilisateurEntiteManager->getRepository()->findOneBy([
            'entite' => $entite,
            'utilisateur' => $user,
        ]);

        if (!$utilisateurEntite instanceof UtilisateurEntite) {
            throw $this->createAccessDeniedException("Vous n'avez pas accès à cette entité.");
        }

        return $utilisateurEntite;
    }

    #[Route('/{id}', name: 'index')]
    public function index(Entite $entite): Response
    {

        return $this->render('premium/entite/index.html.twig', [
            'utilisateur' => $this->getUser(),
            'entite' => $entite,
        ]);
    }

    #[Route('/modifier/{id}', name: 'modifier')]
    public function modifier(
        Entite $entite,
        Entite $id,
        FileUploader $fileUploader,
        Request $request
    ): Response {
        $form = $this->createForm(EntitePremiumType::class, $id);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $uploadPath = $this->getParameter('logo_entite');

            if ($form->get('removeLogo')->getData() === '1') {
                $this->photoManager->deleteImageIfExists($id->getLogo(), $uploadPath);
                $id->setLogo(null);
            }

            if ($form->get('removeLogoMenu')->getData() === '1') {
                $this->photoManager->deleteImageIfExists($id->getLogoMenu(), $uploadPath);
                $id->setLogoMenu(null);
            }

            $this->photoManager->handleImageUpload(
                $form,
                'logo',
                fn($filename) => $id->setLogo($filename),
                $fileUploader,
                $uploadPath,
                800,
                800,
                $id->getLogo()
            );

            $this->photoManager->handleImageUpload(
                $form,
                'logoMenu',
                fn($filename) => $id->setLogoMenu($filename),
                $fileUploader,
                $uploadPath,
                200,
                80,
                $id->getLogoMenu()
            );

            if ($this->entiteManager->create($id)) {
                $this->addFlash('success', 'Les paramètres du club ont bien été mis à jour !');

                return $this->redirectToRoute('app_premium_entite_index', [
                    'id' => $id->getId(),
                    'entite' => $entite->getId(),
                ]);
            }

            $this->addFlash('danger', 'Erreur lors de la mise à jour de l\'entité !');
        }

        return $this->render('premium/entite/modifier.html.twig', [
            'form' => $form->createView(),
            'entite' => $entite,
            'google_maps_browser_key' => $this->getParameter('GOOGLE_MAPS_BROWSER_KEY'),
        ]);
    }

    #[Route('/adherent/ajouter', name: 'adherent_ajouter')]
    public function creerEntite(Entite $entite, FileUploader $fileUploader, Request $request): Response
    {
        /** @var Utilisateur|null $user */
        $user = $this->getUser();

        if (!$user instanceof Utilisateur) {
            throw $this->createAccessDeniedException('Utilisateur non authentifié.');
        }

        if ($user->getAbonnement() != 'PREMIUM') {
            $this->addFlash('danger', 'Vous devez avoir souscrit à un abonnement PREMIUM pour pouvoir créer votre club !');

            return $this->redirectToRoute('app_adherent', [
                'entite' => $entite->getId(),
            ]);
        }

        $nouvelleEntite = new Entite();
        $nouvelleEntite->setCouleurPrincipal('#163860');
        $nouvelleEntite->setCouleurSecondaire('#ecb62f');
        $nouvelleEntite->setCouleurTertiaire('#212529');
        $nouvelleEntite->setCouleurQuaternaire('#000000');
        $nouvelleEntite->setCreateur($user);

        $form = $this->createForm(EntitePremiumType::class, $nouvelleEntite);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $uploadPath = $this->getParameter('logo_entite');

            if ($form->get('removeLogo')->getData() === '1') {
                $this->photoManager->deleteImageIfExists($nouvelleEntite->getLogo(), $uploadPath);
                $nouvelleEntite->setLogo(null);
            }

            if ($form->get('removeLogoMenu')->getData() === '1') {
                $this->photoManager->deleteImageIfExists($nouvelleEntite->getLogoMenu(), $uploadPath);
                $nouvelleEntite->setLogoMenu(null);
            }

            $this->photoManager->handleImageUpload(
                $form,
                'logo',
                fn($filename) => $nouvelleEntite->setLogo($filename),
                $fileUploader,
                $uploadPath,
                800,
                800,
                $nouvelleEntite->getLogo()
            );

            $this->photoManager->handleImageUpload(
                $form,
                'logoMenu',
                fn($filename) => $nouvelleEntite->setLogoMenu($filename),
                $fileUploader,
                $uploadPath,
                100,
                40,
                $nouvelleEntite->getLogoMenu()
            );

            if ($this->entiteManager->create($nouvelleEntite)) {
                $ue = new UtilisateurEntite();
                $ue->setRoles([UtilisateurEntite::TENANT_ADMIN]);
                $ue->setCouleur(sprintf('#%06X', mt_rand(0, 0xFFFFFF)));
                $ue->setUtilisateur($user);
                $ue->setEntite($nouvelleEntite);

                $this->utilisateurEntiteManager->create($ue);

                $this->addFlash('success', 'La nouvelle entité a bien été créée (id n°' . $nouvelleEntite->getId() . ')');

                return $this->redirectToRoute('app_adherent_carnet', [
                    'entite' => $nouvelleEntite->getId(),
                ]);
            }

            $this->addFlash('danger', 'Erreur à la création de l\'entité !');

            return $this->redirectToRoute('app_adherent_carnet', [
                'entite' => $entite->getId(),
            ]);
        }

        return $this->render('premium/entite/ajouter.html.twig', [
            'form' => $form->createView(),
            'nouvelleEntite' => $nouvelleEntite,
            'entite' => $entite,
        ]);
    }

    #[Route('/adherent/nouvelle', name: 'adherent_nouvelle')]
    public function nouveauEntite(Entite $entite): Response
    {
        /** @var Utilisateur|null $user */
        $user = $this->getUser();

        if (!$user instanceof Utilisateur) {
            throw $this->createAccessDeniedException('Utilisateur non authentifié.');
        }

        if ($user->getAbonnement() != 'PREMIUM') {
            $this->addFlash('danger', 'Vous devez avoir souscrit à un abonnement PREMIUM pour pouvoir créer votre club !');

            return $this->redirectToRoute('app_adherent', [
                'entite' => $entite->getId(),
            ]);
        }

        return $this->render('premium/entite/nouvelle.html.twig', [
            'entite' => $entite,
        ]);
    }
}