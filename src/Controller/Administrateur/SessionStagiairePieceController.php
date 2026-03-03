<?php

namespace App\Controller\Administrateur;

use App\Entity\{Entite, Session, Inscription, DossierInscription, PieceDossier, Utilisateur};
use App\Enum\PieceType;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{
  Request,
  Response,
  JsonResponse,
  File\UploadedFile,
  BinaryFileResponse,
  ResponseHeaderBag
};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Security\Permission\TenantPermission;


#[Route('/administrateur/{entite}/session/{session}/stagiaire-piece', name: 'app_administrateur_session_stagiaire_piece_', requirements: ['entite' => '\d+', 'session' => '\d+'])]
#[IsGranted(TenantPermission::SESSION_STAGIAIRE_MANAGE, subject: 'entite')]
final class SessionStagiairePieceController extends AbstractController
{
  public function __construct(private string $uploadDir) {}

  #[Route('/upload', name: 'upload', methods: ['POST'])]
  public function upload(Entite $entite, Session $session, Request $request, EM $em): JsonResponse
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    if ($session->getEntite()?->getId() !== $entite->getId()) {
      return $this->json(['ok' => false, 'message' => 'Session invalide'], 404);
    }

    if (!$this->isCsrfTokenValid('stagiaire_piece_upload_' . $session->getId(), (string)$request->request->get('_token'))) {
      return $this->json(['ok' => false, 'message' => 'Token CSRF invalide'], 403);
    }

    $inscriptionId = (int) $request->request->get('inscriptionId', 0);
    $typeValue     = (string) $request->request->get('type', '');
    /** @var UploadedFile|null $file */
    $file          = $request->files->get('file');

    if (!$inscriptionId || !$typeValue || !$file) {
      return $this->json(['ok' => false, 'message' => 'Données manquantes'], 400);
    }

    $type = PieceType::tryFrom($typeValue);
    if (!$type) {
      return $this->json(['ok' => false, 'message' => 'Type de pièce invalide'], 400);
    }

    /** @var Inscription|null $ins */
    $ins = $em->getRepository(Inscription::class)->find($inscriptionId);
    if (!$ins || $ins->getSession()?->getId() !== $session->getId() || $ins->getEntite()?->getId() !== $entite->getId()) {
      return $this->json(['ok' => false, 'message' => 'Inscription invalide'], 404);
    }

    // ✅ dossier auto-create si absent (comme ton edit)
    $dossier = $ins->getDossier();
    if (!$dossier) {
      $dossier = new DossierInscription();
      $dossier->setCreateur($user);
      $dossier->setEntite($entite);
      $dossier->setInscription($ins);
      $ins->setDossier($dossier);
      $em->persist($dossier);
      $em->flush();
    } else {
      if (!$dossier->getCreateur()) $dossier->setCreateur($user);
      if (!$dossier->getEntite())   $dossier->setEntite($entite);
      if (!$dossier->getDateCreation()) $dossier->setDateCreation(new \DateTimeImmutable());
    }

    @mkdir($this->uploadDir, 0775, true);

    $ext  = $file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin';
    $name = uniqid('piece_', true) . '.' . $ext;
    $file->move($this->uploadDir, $name);

    $piece = new PieceDossier();
    $piece->setType($type);
    $piece->setFilename($name);
    $piece->setUploadedAt(new \DateTimeImmutable());
    $piece->setValide(false);
    $piece->setCommentaireControle(null);

    $piece->setDossier($dossier);
    $piece->setInscription($ins);

    if (method_exists($piece, 'setCreateur')) $piece->setCreateur($user);
    if (method_exists($piece, 'setEntite'))   $piece->setEntite($entite);
    if (method_exists($piece, 'setDateCreation')) $piece->setDateCreation(new \DateTimeImmutable());

    $em->persist($piece);
    $em->flush();

    return $this->json([
      'ok' => true,
      'piece' => [
        'id' => $piece->getId(),
        'type' => ['value' => $type->value, 'label' => $type->label()],
        'filename' => $piece->getFilename(),
        'uploadedAt' => $piece->getUploadedAt()?->format('d/m/Y H:i'),
        'valide' => $piece->isValide(),
        'commentaire' => $piece->getCommentaireControle() ?? '',
        'viewUrl' => $this->generateUrl('app_administrateur_session_stagiaire_piece_view', [
          'entite' => $entite->getId(),
          'session' => $session->getId(),
          'piece' => $piece->getId(),
        ]),
        'downloadUrl' => $this->generateUrl('app_administrateur_session_stagiaire_piece_download', [
          'entite' => $entite->getId(),
          'session' => $session->getId(),
          'piece' => $piece->getId(),
        ]),
        'updateUrl' => $this->generateUrl('app_administrateur_session_stagiaire_piece_update', [
          'entite' => $entite->getId(),
          'session' => $session->getId(),
          'piece' => $piece->getId(),
        ]),
        'csrfUpdate' => $this->container->get('security.csrf.token_manager')->getToken('upd_piece_' . $piece->getId())->getValue(),
      ],
    ]);
  }

  #[Route('/piece/{piece}/update', name: 'update', methods: ['POST'])]
  public function update(Entite $entite, Session $session, PieceDossier $piece, Request $request, EM $em): JsonResponse
  {
    if ($session->getEntite()?->getId() !== $entite->getId()) {
      return $this->json(['ok' => false, 'message' => 'Session invalide'], 404);
    }

    $ins = $piece->getInscription();
    if (!$ins || $ins->getSession()?->getId() !== $session->getId() || $ins->getEntite()?->getId() !== $entite->getId()) {
      return $this->json(['ok' => false, 'message' => 'Pièce invalide'], 404);
    }

    if (!$this->isCsrfTokenValid('upd_piece_' . $piece->getId(), (string)$request->request->get('_token'))) {
      return $this->json(['ok' => false, 'message' => 'Token CSRF invalide'], 403);
    }

    $valide = (string)$request->request->get('valide', '0') === '1';
    $comment = trim((string)$request->request->get('commentaire', ''));

    $piece->setValide($valide);
    $piece->setCommentaireControle($comment !== '' ? $comment : null);

    $em->flush();

    return $this->json([
      'ok' => true,
      'valide' => $piece->isValide(),
      'commentaire' => $piece->getCommentaireControle() ?? '',
    ]);
  }

  #[Route('/piece/{piece}/view', name: 'view', methods: ['GET'])]
  public function view(Entite $entite, Session $session, PieceDossier $piece): Response
  {
    $ins = $piece->getInscription();
    if (!$ins || $ins->getEntite()?->getId() !== $entite->getId() || $ins->getSession()?->getId() !== $session->getId()) {
      throw $this->createNotFoundException();
    }

    $filePath = rtrim($this->uploadDir, '/') . '/' . $piece->getFilename();
    if (!is_file($filePath)) throw $this->createNotFoundException('Fichier introuvable.');

    $resp = new BinaryFileResponse($filePath);
    // inline = preview dans iframe
    $resp->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $piece->getFilename());
    return $resp;
  }

  #[Route('/piece/{piece}/download', name: 'download', methods: ['GET'])]
  public function download(Entite $entite, Session $session, PieceDossier $piece): Response
  {
    $ins = $piece->getInscription();
    if (!$ins || $ins->getEntite()?->getId() !== $entite->getId() || $ins->getSession()?->getId() !== $session->getId()) {
      throw $this->createNotFoundException();
    }

    $filePath = rtrim($this->uploadDir, '/') . '/' . $piece->getFilename();
    if (!is_file($filePath)) throw $this->createNotFoundException('Fichier introuvable.');

    $resp = new BinaryFileResponse($filePath);
    $resp->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $piece->getFilename());
    return $resp;
  }
}
