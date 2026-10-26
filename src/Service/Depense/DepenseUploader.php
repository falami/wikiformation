<?php
// src/Service/Depense/DepenseUploader.php

namespace App\Service\Depense;

use App\Service\FileUploader;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class DepenseUploader
{
  public function __construct(
    private FileUploader $uploader,
    private string $uploadDepenseDir,   // ex: %upload_depense_dir%
    private string $publicPrefix,       // ex: %depense_public_prefix%
  ) {}

  /**
   * Upload un justificatif et renvoie le filename (à stocker en base)
   */
  public function uploadProof(UploadedFile $file): string
  {
    self::validateProof($file);
    return $this->uploader->upload($file, $this->uploadDepenseDir);
  }

  public static function validateProof(UploadedFile $file): void
  {
    if (!$file->isValid()) throw new \InvalidArgumentException('Le justificatif n’a pas été transmis correctement.');
    if (!$file->getSize() || $file->getSize() > 8 * 1024 * 1024) throw new \InvalidArgumentException('Le justificatif doit être non vide et inférieur à 8 Mo.');
    if (!in_array($file->getMimeType(), ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true)) {
      throw new \InvalidArgumentException('Justificatif invalide : PDF, JPG, PNG ou WebP attendu.');
    }
  }

  /**
   * Construit l'URL publique à partir d'un filename
   */
  public function publicUrl(?string $filename): ?string
  {
    if (!$filename) return null;

    return rtrim($this->publicPrefix, '/') . '/' . ltrim($filename, '/');
  }

  /**
   * Supprime le fichier disque si besoin
   */
  public function deleteIfExists(?string $filename): void
  {
    if (!$filename) return;

    $path = $this->absolutePath($filename);
    if (!$path) return;
    if (is_file($path)) {
      @unlink($path);
    }
  }

  /**
   * Chemin absolu disque à partir d'un filename stocké en base
   */
  public function absolutePath(?string $filename): ?string
  {
    if (!$filename || basename($filename) !== $filename || str_contains($filename, '\\')) return null;

    return rtrim($this->uploadDepenseDir, DIRECTORY_SEPARATOR)
      . DIRECTORY_SEPARATOR
      . ltrim($filename, DIRECTORY_SEPARATOR);
  }
}
