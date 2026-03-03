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




#[Route('/stagiaire/{entite}', name: 'app_stagiaire_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::STAGIAIRE_CONVENTION_SIGNATURE_MANAGE, subject: 'entite')]
class ConventionSignatureStagiaireController extends AbstractController
{
  public function __construct(
    private readonly EM $em,
    private readonly ConventionContratRepository $conventionRepo,
  ) {}

  #[Route('/inscription/{id}/convention/signature-stagiaire', name: 'convention_sign', methods: ['POST'])]
  public function signByStagiaire(Entite $entite, Inscription $inscription, Request $request): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

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

    if ($sessionEntite->getId() !== (int) $entite) {
      throw $this->createAccessDeniedException('Accès invalide pour cette entité.');
    }

    // Retrouver la convention via la contrainte unique (session + entreprise + entite)
    $convention = $this->conventionRepo->findOneForInscription($inscription);

    if (!$convention) {
      // cause la plus fréquente chez toi : entreprise = null
      if (!$inscription->getEntreprise()) {
        $this->addFlash('danger', "Aucune entreprise n'est associée à votre inscription : impossible de retrouver la convention.");
      } else {
        $this->addFlash('danger', "Aucune convention n'a été trouvée pour cette inscription.");
      }

      return $this->redirectToRoute('app_stagiaire_inscription_show', [
        'entite' => $entite->getId(),
        'id'     => $inscription->getId(),
      ]);
    }

    // Déjà signée => on ne touche pas (trace Qualiopi)
    if ($convention->getDateSignatureStagiaire() !== null) {
      $this->addFlash('info', 'La convention est déjà signée par le stagiaire.');
      return $this->redirectToRoute('app_stagiaire_inscription_show', [
        'entite' => $entite->getId(),
        'id'     => $inscription->getId(),
      ]);
    }

    $convention->setDateSignatureStagiaire(new \DateTimeImmutable());
    $this->em->flush();

    $this->addFlash('success', 'Vous avez signé la convention.');

    return $this->redirectToRoute('app_stagiaire_inscription_show', [
      'entite' => $entite->getId(),
      'id'     => $inscription->getId(),
    ]);
  }


  #[Route('/inscription/{id}/convention/view', name: 'convention_view', methods: ['GET'])]
  public function view(int $entite, Inscription $inscription): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    // mêmes sécurités que dans signByStagiaire()
    if ($inscription->getStagiaire() !== $user) {
      throw $this->createAccessDeniedException();
    }
    $session = $inscription->getSession();
    if (!$session || !$session->getEntite() || $session->getEntite()->getId() !== (int)$entite) {
      throw $this->createAccessDeniedException();
    }

    $convention = $this->conventionRepo->findOneForInscription($inscription);
    if (!$convention || !$convention->getPdfPath()) {
      return new Response('PDF introuvable', 404);
    }

    $abs = $this->getParameter('kernel.project_dir') . '/public/' . ltrim($convention->getPdfPath(), '/');
    if (!is_file($abs)) {
      return new Response('PDF introuvable sur le serveur', 404);
    }

    return new \Symfony\Component\HttpFoundation\BinaryFileResponse($abs);
  }

  #[Route('/inscription/{id}/convention/esign', name: 'convention_esign', methods: ['POST'])]
  public function esignByStagiaire(int $entite, Inscription $inscription, Request $request): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    if (!$this->isCsrfTokenValid('esign_convention_' . $inscription->getId(), (string)$request->request->get('_token'))) {
      return new JsonResponse(['success' => false, 'message' => 'CSRF invalide'], 403);
    }

    if ($inscription->getStagiaire() !== $user) {
      return new JsonResponse(['success' => false, 'message' => 'Accès refusé'], 403);
    }

    $session = $inscription->getSession();
    if (!$session || !$session->getEntite() || $session->getEntite()->getId() !== (int)$entite) {
      return new JsonResponse(['success' => false, 'message' => 'Entité invalide'], 403);
    }

    $convention = $this->conventionRepo->findOneForInscription($inscription);
    if (!$convention) {
      return new JsonResponse(['success' => false, 'message' => 'Convention introuvable'], 404);
    }

    if ($convention->getDateSignatureStagiaire() !== null) {
      return new JsonResponse(['success' => true, 'alreadySigned' => true]);
    }

    $sig = (string)$request->request->get('signatureData', '');
    if (!str_starts_with($sig, 'data:image/png;base64,')) {
      return new JsonResponse(['success' => false, 'message' => 'Signature invalide'], 400);
    }

    // 👉 il te faut un champ pour stocker l’image (dans ConventionContrat)
    $convention->setSignatureDataUrlStagiaire($sig);
    $convention->setDateSignatureStagiaire(new \DateTimeImmutable());

    $this->em->flush();

    return new JsonResponse(['success' => true]);
  }
}
