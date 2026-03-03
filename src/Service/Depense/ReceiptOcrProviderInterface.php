<?php
// src/Service/Depense/ReceiptOcrProviderInterface.php
namespace App\Service\Depense;

use Symfony\Component\HttpFoundation\File\UploadedFile;

interface ReceiptOcrProviderInterface
{
  /** @return array{text:string, lines:string[]} */
  public function extractText(UploadedFile $file): array;
}
