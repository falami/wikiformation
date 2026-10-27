<?php

namespace App\Controller\Administrateur;

use App\Service\Ocr\GoogleVisionOcrService;
use App\Entity\Entite;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Security\Permission\TenantPermission;


#[IsGranted(TenantPermission::OCR_MANAGE, subject: 'entite')]
final class OcrController extends AbstractController
{
  #[Route('/administrateur/{entite}/ocr/extract', name: 'app_administrateur_ocr_extract', methods: ['POST'], requirements: ['entite' => '\d+'])]
  public function extract(Entite $entite, Request $request, GoogleVisionOcrService $ocr): JsonResponse
  {
    if (!$this->isCsrfTokenValid('ocr_extract_' . $entite->getId(), $request->request->get('_token'))) {
      return $this->json(['ok' => false, 'error' => 'Votre page a expiré. Actualisez-la avant de réessayer.'], 403);
    }
    $file = $request->files->get('file');

    if (!$file instanceof UploadedFile || !$file->isValid()) {
      return $this->json(['ok' => false, 'error' => 'Aucun fichier reçu.'], 400);
    }

    // Sécurité: taille/mime
    $maxMb = 12;
    if ($file->getSize() && $file->getSize() > ($maxMb * 1024 * 1024)) {
      return $this->json(['ok' => false, 'error' => "Fichier trop lourd (max {$maxMb}MB)."], 400);
    }

    $mime = (string) $file->getMimeType();
    $allowed = [
      'image/jpeg',
      'image/png',
      'image/webp',
      'image/gif',
      'application/pdf',
      'image/tiff'
    ];

    // Attention iPhone: HEIC/HEIF souvent non supporté par Vision.
    // Si tu veux le gérer: conversion serveur (Imagick) ou forcer "Most compatible" côté iPhone.
    if (!in_array($mime, $allowed, true)) {
      return $this->json([
        'ok' => false,
        'error' => "Format non supporté ({$mime}). Utilise JPG/PNG/WebP/PDF."
      ], 400);
    }

    try {
      $res = $ocr->ocr($file);

      return $this->json([
        'ok' => true,
        'mode' => $res['mode'] ?? null,
        'text' => $res['text'] ?? '',
      ]);
    } catch (\Throwable $e) {
      return $this->json([
        'ok' => false,
        'error' => 'L’analyse du document est temporairement indisponible.',
      ], 500);
    }
  }
}
