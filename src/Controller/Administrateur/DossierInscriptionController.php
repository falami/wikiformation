<?php

namespace App\Controller\Administrateur;

use App\Entity\{DossierInscription, Entite, Utilisateur, Inscription, PieceDossier};
use App\Form\Administrateur\DossierInscriptionType;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{
    Request,
    Response,
    File\UploadedFile,
    BinaryFileResponse,
    ResponseHeaderBag
};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use App\Security\Permission\TenantPermission;


#[Route('/administrateur/{entite}/dossier', name: 'app_administrateur_dossier_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::DOSSIER_INSCRIPTION_MANAGE, subject: 'entite')]
class DossierInscriptionController extends AbstractController
{
    public function __construct(
        private UtilisateurEntiteManager $utilisateurEntiteManager,
        private string $uploadDir,
    ) {}

    #[Route('/inscription/{id}', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Entite $entite, Inscription $id, Request $req, EM $em): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $dossier = $id->getDossier();

        // ✅ crée le dossier si absent
        if (!$dossier) {
            $dossier = new DossierInscription();
            $dossier->setCreateur($user);
            $dossier->setEntite($entite);
            $dossier->setInscription($id);
            $id->setDossier($dossier); // inverse side

            $em->persist($dossier);
            $em->flush(); // si tu veux absolument un id dossier
        } else {
            // ✅ sécurise un dossier existant (cas legacy)
            if (!$dossier->getCreateur()) {
                $dossier->setCreateur($user);
            }
            if (!$dossier->getEntite()) {
                $dossier->setEntite($entite);
            }
            if (!$dossier->getDateCreation()) {
                $dossier->setDateCreation(new \DateTimeImmutable());
            }
        }

        $form = $this->createForm(DossierInscriptionType::class, $dossier);
        $form->handleRequest($req);

        if ($form->isSubmitted() && $form->isValid()) {

        // ✅ IMPORTANT : on boucle sur les sous-formulaires (order safe)
            /** @var \Symfony\Component\Form\FormInterface $pieceForm */
            foreach ($form->get('pieces') as $pieceForm) {

                /** @var PieceDossier|null $piece */
                $piece = $pieceForm->getData();
                if (!$piece) {
                    continue;
                }

                /** @var UploadedFile|null $file */
                $file = $pieceForm->get('filename')->getData();

                if ($file instanceof UploadedFile) {
                    @mkdir($this->uploadDir, 0775, true);

                    $ext = $file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin';
                    $name = uniqid('piece_', true) . '.' . $ext;

                    $file->move($this->uploadDir, $name);

                    $piece->setFilename($name);
                    $piece->setUploadedAt(new \DateTimeImmutable());
                }

                // ✅ relations obligatoires
                $piece->setDossier($dossier);
                $piece->setInscription($id);

                // ✅ si PieceDossier a createur/entite/dateCreation NOT NULL
                if (method_exists($piece, 'getCreateur') && !$piece->getCreateur()) {
                    $piece->setCreateur($user);
                }
                if (method_exists($piece, 'getEntite') && !$piece->getEntite()) {
                    $piece->setEntite($entite);
                }
                if (method_exists($piece, 'getDateCreation') && !$piece->getDateCreation()) {
                    $piece->setDateCreation(new \DateTimeImmutable());
                }
            }

            $em->persist($dossier);
            $em->flush();

            $this->addFlash('success', 'Dossier enregistré.');

            return $this->redirectToRoute('app_administrateur_inscription_show', [
                'id' => $id->getId(),
                'entite' => $entite->getId(),
            ]);
        }

        return $this->render('administrateur/dossier/form.html.twig', [
            'form' => $form,
            'title' => 'Dossier d’inscription',
            'inscription' => $id,
            'entite' => $entite,
        ]);
    }

    #[Route('/piece/{piece}/download', name: 'piece_download', methods: ['GET'])]
    public function download(Entite $entite, PieceDossier $piece): Response
    {
        // (Optionnel) sécuriser en vérifiant que la pièce appartient bien à l’entité
        // $inscription = $piece->getDossier()->getInscription();
        // if ($inscription->getSession()->getEntite() !== $entite) {
        //     throw $this->createAccessDeniedException();
        // }

        $filePath = $this->uploadDir . '/' . $piece->getFilename();

        if (!is_file($filePath)) {
            throw $this->createNotFoundException('Fichier introuvable.');
        }

        $response = new BinaryFileResponse($filePath);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $piece->getFilename()
        );

        return $response;
    }

    #[Route('/piece/{piece}/toggle-valid', name: 'piece_toggle_valid', methods: ['POST'])]
    public function toggleValid(Entite $entite, PieceDossier $piece, Request $request, EM $em): Response
    {
        if (!$this->isCsrfTokenValid('toggle_piece' . $piece->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF invalide.');
        }

        $dossier = $piece->getDossier();
        $inscription = $dossier->getInscription();

        // Sécurité optionnelle comme plus haut :
        // if ($inscription->getSession()->getEntite() !== $entite) {
        //     throw $this->createAccessDeniedException();
        // }

        $piece->setValide(!$piece->isValide());
        $em->flush();

        $this->addFlash(
            'success',
            $piece->isValide()
                ? 'Pièce marquée comme valide.'
                : 'Pièce marquée comme non valide.'
        );

        /** @var Utilisateur $user */
        $user = $this->getUser();

        return $this->redirectToRoute('app_administrateur_inscription_show', [
            'id' => $inscription->getId(),
            'entite' => $entite->getId(),
        ]);
    }

    #[Route('/piece/{piece}/delete', name: 'piece_delete', methods: ['POST'])]
    public function delete(Entite $entite, PieceDossier $piece, Request $request, EM $em): Response
    {
        if (!$this->isCsrfTokenValid('del_piece' . $piece->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF invalide.');
        }

        $dossier = $piece->getDossier();
        $inscription = $dossier->getInscription();

        $filename = $piece->getFilename();
        $filePath = $this->uploadDir . '/' . $filename;

        $em->remove($piece);
        $em->flush();

        // on peut aussi supprimer physiquement le fichier si souhaité
        if (is_file($filePath)) {
            @unlink($filePath);
        }

        $this->addFlash('success', 'Pièce supprimée du dossier.');

        /** @var Utilisateur $user */
        $user = $this->getUser();

        return $this->redirectToRoute('app_administrateur_inscription_show', [
            'id' => $inscription->getId(),
            'entite' => $entite->getId(),
        ]);
    }
}
