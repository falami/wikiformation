<?php

namespace App\Controller\Stagiaire;

use App\Entity\{Inscription, Utilisateur, Entite};
use App\Repository\ConventionContratRepository;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Security\Permission\TenantPermission;
use App\Service\Convention\ConventionPortalSignature;
use App\Security\DossierDocumentAccess;




#[Route('/stagiaire/{entite}', name: 'app_stagiaire_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::STAGIAIRE_CONVENTION_SIGNATURE_MANAGE, subject: 'entite')]
class ConventionSignatureStagiaireController extends AbstractController
{
  public function __construct(
    private readonly EM $em,
    private readonly ConventionContratRepository $conventionRepo,
    private readonly ConventionPortalSignature $signatures,
  ) {}

  #[Route('/inscription/{id}/convention/signature-stagiaire', name: 'convention_sign', methods: ['POST'])]
  public function signByStagiaire(Entite $entite, Inscription $inscription, Request $request): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();
    DossierDocumentAccess::inscription($entite, $inscription);

    // CSRF (fortement recommandé)
    if (!$this->isCsrfTokenValid('sign_convention_' . $inscription->getId(), (string) $request->request->get('_token'))) {
      throw $this->createAccessDeniedException('Token CSRF invalide.');
    }

    // Sécurité : le stagiaire connecté doit être celui de l’inscription
    if ($inscription->getStagiaire() !== $user) {
      throw $this->createAccessDeniedException('Vous ne pouvez pas signer cette convention.');
    }

    // Sécurité : l’inscription doit appartenir à l’entité demandée (via session->entite)
    $session = $inscription->getSession();
    $sessionEntite = $session?->getEntite();

    if (!$session || !$sessionEntite) {
      throw $this->createNotFoundException('Session ou entité introuvable.');
    }

    if ($sessionEntite->getId() !== $entite->getId()) {
      throw $this->createAccessDeniedException('Accès invalide pour cette entité.');
    }

    // Retrouver la convention via la contrainte unique (session + entreprise + entite)
    $convention = $this->conventionRepo->findOneForInscription($inscription, $request->query->getInt('convention') ?: null);

    if (!$convention) {
      // cause la plus fréquente chez toi : entreprise = null
      if (!$inscription->getEntreprise()) {
        $this->addFlash('danger', "Aucune entreprise n'est associée à votre inscription : impossible de retrouver la convention.");
      } else {
        $this->addFlash('danger', "Aucune convention n'a été trouvée pour cette inscription.");
      }

      return $this->redirectToRoute('app_stagiaire_dossier_edit', [
        'entite' => $entite->getId(),
        'id'     => $inscription->getId(),
      ]);
    }

    if ($convention->getStagiaire() !== $user || !$convention->getPdfPath()) {
      throw $this->createAccessDeniedException('Seul le destinataire individuel peut signer sa convention après consultation du PDF.');
    }

    // Déjà signée => on ne touche pas (trace Qualiopi)
    if ($convention->getDateSignatureStagiaire() !== null) {
      $this->addFlash('info', 'La convention est déjà signée par le stagiaire.');
      return $this->redirectToRoute('app_stagiaire_dossier_edit', [
        'entite' => $entite->getId(),
        'id'     => $inscription->getId(),
      ]);
    }

    try {
      $this->signatures->sign($convention, $user, 'stagiaire', null, $request->getSession()->get('convention_reviewed_'.$convention->getId()));
    } catch (\DomainException $error) {
      $this->addFlash('danger', $error->getMessage());
      return $this->redirectToRoute('app_stagiaire_dossier_edit', ['entite' => $entite->getId(), 'id' => $inscription->getId()]);
    }

    $this->addFlash('success', 'Vous avez signé la convention.');

    return $this->redirectToRoute('app_stagiaire_dossier_edit', [
      'entite' => $entite->getId(),
      'id'     => $inscription->getId(),
    ]);
  }


  #[Route('/inscription/{id}/convention/view', name: 'convention_view', methods: ['GET'])]
  public function view(Entite $entite, Inscription $inscription, Request $request, \App\Service\Convention\ConventionDocument $document): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();
    DossierDocumentAccess::inscription($entite, $inscription);

    // mêmes sécurités que dans signByStagiaire()
    if ($inscription->getStagiaire() !== $user) {
      throw $this->createAccessDeniedException();
    }
    $session = $inscription->getSession();
    if (!$session || !$session->getEntite() || $session->getEntite()->getId() !== $entite->getId()) {
      throw $this->createAccessDeniedException();
    }

    $convention = $this->conventionRepo->findOneForInscription($inscription, $request->query->getInt('convention') ?: null);
    if (!$convention || !$convention->getPdfPath()) {
      return new Response('PDF introuvable', 404);
    }

    return $this->signatures->preview($convention, $request);
  }

  #[Route('/inscription/{id}/convention/esign', name: 'convention_esign', methods: ['POST'])]
  public function esignByStagiaire(Entite $entite, Inscription $inscription, Request $request): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();
    DossierDocumentAccess::inscription($entite, $inscription);

    if (!$this->isCsrfTokenValid('esign_convention_' . $inscription->getId(), (string)$request->request->get('_token'))) {
      return new JsonResponse(['success' => false, 'message' => 'CSRF invalide'], 403);
    }

    if ($inscription->getStagiaire() !== $user) {
      return new JsonResponse(['success' => false, 'message' => 'Accès refusé'], 403);
    }

    $session = $inscription->getSession();
    if (!$session || !$session->getEntite() || $session->getEntite()->getId() !== $entite->getId()) {
      return new JsonResponse(['success' => false, 'message' => 'Entité invalide'], 403);
    }

    $convention = $this->conventionRepo->findOneForInscription($inscription, $request->query->getInt('convention') ?: null);
    if (!$convention) {
      return new JsonResponse(['success' => false, 'message' => 'Convention introuvable'], 404);
    }

    if ($convention->getStagiaire() !== $user) {
      return new JsonResponse(['success' => false, 'message' => 'La signature appartient au destinataire de la convention.'], 403);
    }

    if ($convention->getDateSignatureStagiaire() !== null) {
      return new JsonResponse(['success' => true, 'alreadySigned' => true]);
    }

    if (!$convention->getPdfPath()) return new JsonResponse(['success' => false, 'message' => 'Générez le document avant signature.'], 400);

    $sig = (string)$request->request->get('signatureData', '');
    try {
      ConventionPortalSignature::validateSignature($sig);
    } catch (\DomainException $error) {
      return new JsonResponse(['success' => false, 'message' => $error->getMessage()], 400);
    }

    try {
      $this->signatures->sign($convention, $user, 'stagiaire', $sig, $request->getSession()->get('convention_reviewed_'.$convention->getId()));
    } catch (\DomainException $error) {
      return new JsonResponse(['success' => false, 'message' => $error->getMessage()], 409);
    }

    return new JsonResponse(['success' => true]);
  }
}
