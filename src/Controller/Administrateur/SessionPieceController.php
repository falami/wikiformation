<?php
// src/Controller/Administrateur/SessionPieceController.php

declare(strict_types=1);

namespace App\Controller\Administrateur;

use App\Entity\{Entite, Session, SessionPiece, Utilisateur};
use App\Form\Administrateur\SessionPieceUploadType;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\{
  Request,
  Response,
  File\UploadedFile,
  BinaryFileResponse,
  ResponseHeaderBag
};
use Symfony\Component\Routing\Attribute\Route;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\Security\Permission\TenantPermission;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Doctrine\DBAL\Exception\NotNullConstraintViolationException;


#[Route('/administrateur/{entite}/session/{session}/pieces', name: 'app_administrateur_session_pieces_', requirements: ['entite' => '\d+', 'session' => '\d+'])]
#[IsGranted(TenantPermission::SESSION_PIECE_MANAGE, subject: 'entite')]
final class SessionPieceController extends AbstractController
{
  public function __construct(
    #[Autowire('%session_piece_dir%')]
    private readonly string $uploadDir,
    private UtilisateurEntiteManager $utilisateurEntiteManager,
  ) {}

  private function assertEntiteSession(Entite $entite, Session $session): void
  {
    if ($session->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException();
    }
  }

  #[Route('', name: 'index', methods: ['GET', 'POST'])]
  public function index(Entite $entite, Session $session, Request $request, EM $em): Response
  {

    $this->assertEntiteSession($entite, $session);

    /** @var Utilisateur $user */
    $user = $this->getUser();

    $piece = new SessionPiece();
    $piece->setCreateur($user)
      ->setEntite($entite);
    $form = $this->createForm(SessionPieceUploadType::class, $piece)->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      /** @var UploadedFile|null $file */
      $file = $form->get('file')->getData();

      if (!$file instanceof UploadedFile) {
        $this->addFlash('danger', 'Aucun fichier reçu.');
        return $this->redirectToRoute('app_administrateur_session_pieces_index', [
          'entite' => $entite->getId(),
          'session' => $session->getId(),
        ]);
      }

      @mkdir($this->uploadDir, 0775, true);

      // ✅ IMPORTANT : lire infos AVANT move()
      $mime = $file->getMimeType() ?: $file->getClientMimeType() ?: 'application/octet-stream';

      $ext = $file->guessExtension()
        ?: $file->getClientOriginalExtension()
        ?: 'bin';

      $name = uniqid('sess_', true) . '.' . strtolower($ext);

      // ✅ move après
      $file->move($this->uploadDir, $name);

      $finalPath = $this->uploadDir . '/' . $name;
      $mime = @mime_content_type($finalPath) ?: 'application/octet-stream';


      $piece->setFilename($name);
      $piece->setMimeType($mime);
      $piece->setUploadedAt(new \DateTimeImmutable());
      $piece->setSession($session);
      $piece->setEntite($entite);
      $piece->setCreateur($user);

      try {
        $em->persist($piece);
        $em->flush();


        $this->addFlash('success', 'Document ajouté à la session.');
      } catch (NotNullConstraintViolationException $e) {
        // fallback UX
        $this->addFlash('danger', "Veuillez choisir un type de pièce.");
        // Option 1: rester sur la page (pas de redirect) et laisser Symfony afficher les erreurs
        // Option 2: redirect (si tu veux) :
        return $this->redirectToRoute('app_administrateur_session_pieces_index', [
          'entite' => $entite->getId(),
          'session' => $session->getId(),
        ]);
      }


      return $this->redirectToRoute('app_administrateur_session_pieces_index', [
        'entite' => $entite->getId(),
        'session' => $session->getId(),
      ]);
    }


    return $this->render('administrateur/session/pieces/index.html.twig', [
      'entite' => $entite,
      'session' => $session,
      'form' => $form->createView(),
      'pieces' => $session->getPieces(),
    ]);
  }

  #[Route('/{piece}/download', name: 'download', methods: ['GET'], requirements: ['piece' => '\d+'])]
  public function download(Entite $entite, Session $session, SessionPiece $piece): Response
  {

    $this->assertEntiteSession($entite, $session);

    if ($piece->getSession()?->getId() !== $session->getId()) {
      throw $this->createNotFoundException();
    }
    /** @var Utilisateur $user */
    $user = $this->getUser();

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

  #[Route('/{piece}/delete', name: 'delete', methods: ['POST'], requirements: ['piece' => '\d+'])]
  public function delete(Entite $entite, Session $session, SessionPiece $piece, Request $request, EM $em): Response
  {

    $this->assertEntiteSession($entite, $session);

    if ($piece->getSession()?->getId() !== $session->getId()) {
      throw $this->createNotFoundException();
    }

    if (!$this->isCsrfTokenValid('del_session_piece_' . $piece->getId(), (string)$request->request->get('_token'))) {
      throw $this->createAccessDeniedException('CSRF invalide.');
    }
    /** @var Utilisateur $user */
    $user = $this->getUser();

    $filePath = $this->uploadDir . '/' . $piece->getFilename();

    $em->remove($piece);
    $em->flush();

    if (is_file($filePath)) {
      @unlink($filePath);
    }

    $this->addFlash('success', 'Document supprimé.');
    return $this->redirectToRoute('app_administrateur_session_pieces_index', [
      'entite' => $entite->getId(),
      'session' => $session->getId(),

    ]);
  }

  #[Route('/{piece}/validate', name: 'validate', methods: ['POST'], requirements: ['piece' => '\d+'])]
  public function validatePiece(Entite $entite, Session $session, SessionPiece $piece, Request $request, EM $em): Response
  {

    $this->assertEntiteSession($entite, $session);

    if ($piece->getSession()?->getId() !== $session->getId()) {
      throw $this->createNotFoundException();
    }

    if (!$this->isCsrfTokenValid('val_session_piece_' . $piece->getId(), (string)$request->request->get('_token'))) {
      throw $this->createAccessDeniedException('CSRF invalide.');
    }
    /** @var Utilisateur $user */
    $user = $this->getUser();

    $piece->setValide(true);
    $piece->setCommentaireControle(null);
    $em->flush();

    $this->addFlash('success', 'Document validé.');
    return $this->redirectToRoute('app_administrateur_session_pieces_index', [
      'entite' => $entite->getId(),
      'session' => $session->getId(),

    ]);
  }

  #[Route('/{piece}/reject', name: 'reject', methods: ['POST'], requirements: ['piece' => '\d+'])]
  public function rejectPiece(Entite $entite, Session $session, SessionPiece $piece, Request $request, EM $em): Response
  {

    $this->assertEntiteSession($entite, $session);

    if ($piece->getSession()?->getId() !== $session->getId()) {
      throw $this->createNotFoundException();
    }

    if (!$this->isCsrfTokenValid('rej_session_piece_' . $piece->getId(), (string)$request->request->get('_token'))) {
      throw $this->createAccessDeniedException('CSRF invalide.');
    }
    /** @var Utilisateur $user */
    $user = $this->getUser();

    $comment = trim((string)$request->request->get('comment', ''));
    $piece->setValide(false);
    $piece->setCommentaireControle($comment !== '' ? $comment : 'Document refusé : merci de le remplacer.');
    $em->flush();

    $this->addFlash('warning', 'Document refusé (commentaire enregistré).');
    return $this->redirectToRoute('app_administrateur_session_pieces_index', [
      'entite' => $entite->getId(),
      'session' => $session->getId(),

    ]);
  }

  #[Route('/{piece}/view', name: 'view', methods: ['GET'], requirements: ['piece' => '\d+'])]
  public function view(Entite $entite, Session $session, SessionPiece $piece): Response
  {

    $this->assertEntiteSession($entite, $session);

    if ($piece->getSession()?->getId() !== $session->getId()) {
      throw $this->createNotFoundException();
    }

    $filePath = $this->uploadDir . '/' . $piece->getFilename();
    if (!is_file($filePath) || !is_readable($filePath)) {
      throw $this->createNotFoundException('Fichier introuvable.');
    }

    // mime fiable (stocké ou recalculé)
    $mime = $piece->getMimeType() ?: @mime_content_type($filePath) ?: 'application/octet-stream';

    $response = new BinaryFileResponse($filePath);
    $response->headers->set('Content-Type', $mime);

    // inline pour affichage dans iframe
    $response->setContentDisposition(
      ResponseHeaderBag::DISPOSITION_INLINE,
      $piece->getFilename()
    );

    // (optionnel) cache léger
    $response->headers->set('X-Content-Type-Options', 'nosniff');

    return $response;
  }

  #[Route('/{piece}/update', name: 'update', methods: ['POST'], requirements: ['piece' => '\d+'])]
  public function update(Entite $entite, Session $session, SessionPiece $piece, Request $request, EM $em): JsonResponse
  {

    $this->assertEntiteSession($entite, $session);

    if ($piece->getSession()?->getId() !== $session->getId()) {
      throw $this->createNotFoundException();
    }

    if (!$request->isXmlHttpRequest()) {
      return new JsonResponse(['ok' => false, 'message' => 'Requête invalide.'], 400);
    }

    $token = (string) $request->request->get('_token');
    if (!$this->isCsrfTokenValid('upd_session_piece_' . $piece->getId(), $token)) {
      return new JsonResponse(['ok' => false, 'message' => 'CSRF invalide.'], 403);
    }

    // champs
    $comment = trim((string) $request->request->get('commentaire', ''));
    $valideRaw = $request->request->get('valide', '0');
    $valide = in_array((string)$valideRaw, ['1', 'true', 'on'], true);

    $piece->setCommentaireControle($comment !== '' ? $comment : null);
    $piece->setValide($valide);

    $em->flush();

    return new JsonResponse([
      'ok' => true,
      'valide' => $piece->isValide(),
      'commentaire' => $piece->getCommentaireControle() ?: '—',
      'uploadedAt' => $piece->getUploadedAt()->format('d/m/Y H:i'),
      'type' => $piece->getType()->label(),
    ]);
  }
}
