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
    return $this->uploader->upload($file, $this->uploadDepenseDir);
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

    $path = rtrim($this->uploadDepenseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
    if (is_file($path)) {
      @unlink($path);
    }
  }

  /**
   * Chemin absolu disque à partir d'un filename stocké en base
   */
  public function absolutePath(?string $filename): ?string
  {
    if (!$filename) return null;

    return rtrim($this->uploadDepenseDir, DIRECTORY_SEPARATOR)
      . DIRECTORY_SEPARATOR
      . ltrim($filename, DIRECTORY_SEPARATOR);
  }
}
