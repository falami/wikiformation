<?php
// src/Service/Depense/NullReceiptOcrProvider.php
namespace App\Service\Depense;

use Symfony\Component\HttpFoundation\File\UploadedFile;

final class NullReceiptOcrProvider implements ReceiptOcrProviderInterface
{
  public function extractText(UploadedFile $file): array
  {
    throw new \RuntimeException("OCR non configuré (choisir Google Vision / AWS Textract / Azure / Tesseract).");
  }
}
