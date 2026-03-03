<?php
// src/Service/Depense/ReceiptScanService.php
namespace App\Service\Depense;

use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ReceiptScanService
{
  public function __construct(
    private ReceiptOcrProviderInterface $ocr,
    private ReceiptParser $parser,
  ) {}

  public function scan(UploadedFile $file): array
  {
    $ocr = $this->ocr->extractText($file);
    return $this->parser->parse($ocr);
  }
}
