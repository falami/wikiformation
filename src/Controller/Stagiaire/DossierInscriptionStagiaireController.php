<?php
// src/Controller/Stagiaire/DossierInscriptionStagiaireController.php
declare(strict_types=1);

namespace App\Controller\Stagiaire;

use App\Entity\{DossierInscription, Entite, Utilisateur, Inscription, PieceDossier};

use App\Form\Stagiaire\DossierInscriptionStagiaireType;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
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
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use App\Repository\ConventionContratRepository;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Security\Permission\TenantPermission;


#[Route('/stagiaire/{entite}/dossier', name: 'app_stagiaire_dossier_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::STAGIAIRE_DOSSIER_INSCRIPTION_MANAGE, subject: 'entite')]
class DossierInscriptionStagiaireController extends AbstractController
{
  public function __construct(

    private UtilisateurEntiteManager $utilisateurEntiteManager,
    private ConventionContratRepository $conventionRepo,
    #[Autowire('%piece_dossier_dir%')]
    private string $uploadDir,
  ) {}

  #[Route('/inscription/{id}', name: 'edit', methods: ['GET', 'POST'])]
  public function edit(Entite $entite, Inscription $id, Request $req, EM $em): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    // 🔐 Sécurité : l’inscription doit être celle du stagiaire connecté
    if ($id->getStagiaire() !== $user) {
      throw $this->createAccessDeniedException('Vous ne pouvez modifier que votre propre dossier.');
    }

    // (optionnel) Vérifier que l’inscription appartient à la bonne entité
    if ($id->getSession()->getEntite() !== $entite) {
      throw $this->createAccessDeniedException('Cette inscription ne correspond pas à cette entité.');
    }

    $dossier = $id->getDossier() ?? (new DossierInscription())->setCreateur($user)->setEntite($entite);
    // 👉 Tu peux créer un DossierInscriptionStagiaireType si tu veux cacher certains champs (ex: "valide")
    $form = $this->createForm(DossierInscriptionStagiaireType::class, $dossier)->handleRequest($req);

    if ($form->isSubmitted() && $form->isValid()) {

      foreach ($form->get('pieces') as $pieceForm) {
        /** @var PieceDossier $piece */
        $piece = $pieceForm->getData();

        /** @var UploadedFile|null $file */
        $file = $pieceForm->get('filename')->getData();

        if ($file instanceof UploadedFile) {
          @mkdir($this->uploadDir, 0775, true);

          $name = uniqid('piece_') . '.' . ($file->guessExtension() ?: 'bin');
          $file->move($this->uploadDir, $name);

          $piece->setFilename($name);
          $piece->setUploadedAt(new \DateTimeImmutable());

          // Forcer "valide" à false pour un stagiaire
          if (method_exists($piece, 'setValide')) {
            $piece->setValide(false);
          }
        }

        // Assure le lien inverse
        $piece->setDossier($dossier);
      }

      $em->persist($dossier);
      $em->flush();

      $this->addFlash('success', 'Votre dossier a été enregistré.');

      return $this->redirectToRoute('app_stagiaire_dashboard', [
        'entite' => $entite->getId(),
      ]);
    }


    $convention = $this->conventionRepo->findOneForInscription($id);

    $conventionPdfUrl = null;
    $conventionSignedAt = null;
    $conventionCanESign = false;

    if ($convention) {
      // URL de lecture du PDF (via ton ConventionSignatureStagiaireController::view)
      if ($convention->getPdfPath()) {
        $conventionPdfUrl = $this->generateUrl('app_stagiaire_convention_view', [
          'entite' => $entite->getId(),
          'id'     => $id->getId(),
        ]);
      }

      // badge "signée le ..."
      if ($convention->getDateSignatureStagiaire()) {
        $conventionSignedAt = $convention->getDateSignatureStagiaire()->format('d/m/Y à H:i');
      }

      // autoriser e-sign seulement si PDF dispo et pas déjà signé
      $conventionCanESign = (bool) $conventionPdfUrl && $conventionSignedAt === null;
    }

    $conventionSignUrl = $this->generateUrl('app_stagiaire_convention_esign', [
      'entite' => $entite->getId(),
      'id'     => $id->getId(),
    ]);

    $csrfConventionToken = $this->container->get('security.csrf.token_manager')
      ->getToken('esign_convention_' . $id->getId())
      ->getValue();


    return $this->render('stagiaire/dossier/form.html.twig', [
      'form' => $form->createView(),
      'title' => 'Mon dossier d’inscription',
      'inscription' => $id,
      'entite' => $entite,
      'utilisateurEntite' => $this->utilisateurEntiteManager->getRepository()->findOneBy([
        'entite'     => $entite->getId(),
        'utilisateur' => $user->getId(),
      ]),

      // ✅ Convention / e-sign
      'conventionPdfUrl' => $conventionPdfUrl,
      'conventionSignedAt' => $conventionSignedAt,
      'conventionCanESign' => $conventionCanESign,
      'conventionSignUrl' => $conventionSignUrl,
      'csrfConventionToken' => $csrfConventionToken,
      'conventionUploadHelp' => null,
    ]);
  }

  #[Route('/piece/{piece}/download', name: 'piece_download', methods: ['GET'])]
  public function download(Entite $entite, PieceDossier $piece): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    $inscription = $piece->getDossier()->getInscription();

    // 🔐 La pièce doit appartenir à l’inscription du stagiaire
    if ($inscription->getStagiaire() !== $user) {
      throw $this->createAccessDeniedException();
    }

    if ($inscription->getSession()->getEntite() !== $entite) {
      throw $this->createAccessDeniedException();
    }

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

  #[Route('/piece/{piece}/delete', name: 'piece_delete', methods: ['POST'])]
  public function delete(Entite $entite, PieceDossier $piece, Request $request, EM $em): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    if (!$this->isCsrfTokenValid('del_piece' . $piece->getId(), $request->request->get('_token'))) {
      throw $this->createAccessDeniedException('Token CSRF invalide.');
    }

    $dossier     = $piece->getDossier();
    $inscription = $dossier->getInscription();

    // 🔐 Toujours les mêmes vérifications
    if ($inscription->getStagiaire() !== $user) {
      throw $this->createAccessDeniedException();
    }
    if ($inscription->getSession()->getEntite() !== $entite) {
      throw $this->createAccessDeniedException();
    }

    // (optionnel) ne pas autoriser la suppression si la pièce est déjà "validée" par l’admin
    if (method_exists($piece, 'isValide') && $piece->isValide()) {
      $this->addFlash('danger', 'Vous ne pouvez pas supprimer une pièce déjà validée.');
      return $this->redirectToRoute('app_stagiaire_dossier_edit', [
        'entite' => $entite->getId(),
        'id'     => $inscription->getId(),
      ]);
    }

    $filename = $piece->getFilename();
    $filePath = $this->uploadDir . '/' . $filename;

    $em->remove($piece);
    $em->flush();

    if (is_file($filePath)) {
      @unlink($filePath);
    }

    $this->addFlash('success', 'Pièce supprimée de votre dossier.');

    return $this->redirectToRoute('app_stagiaire_dossier_edit', [
      'entite' => $entite->getId(),
      'id'     => $inscription->getId(),
    ]);
  }
}
