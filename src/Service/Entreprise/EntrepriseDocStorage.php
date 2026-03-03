<?php

declare(strict_types=1);

namespace App\Service\Entreprise;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class EntrepriseDocStorage
{
  public function __construct(private string $projectDir) {}

  public function store(UploadedFile $file): array
  {
    $slugger = new AsciiSlugger();
    $orig = $file->getClientOriginalName();
    $base = pathinfo($orig, PATHINFO_FILENAME);
    $safe = strtolower($slugger->slug((string)$base)->toString());

    $ext = $file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin';
    $name = $safe . '-' . bin2hex(random_bytes(6)) . '.' . $ext;

    $dir = $this->projectDir . '/public/uploads/entreprise/docs';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);

    $file->move($dir, $name);

    return [
      'filename' => $name,
      'originalName' => $orig,
      'mimeType' => $file->getClientMimeType(),
      'size' => (int)$file->getSize(),
      'publicUrl' => '/uploads/entreprise/docs/' . $name,
    ];
  }
}
