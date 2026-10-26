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
    if (!$file->isValid() || !is_readable($file->getPathname())) {
      throw new \InvalidArgumentException('Le fichier n’a pas été transféré correctement. Sélectionnez-le à nouveau.');
    }
    $size = $file->getSize();
    if ($size === false || $size === 0 || $size > 8 * 1024 * 1024) {
      throw new \InvalidArgumentException('Le justificatif doit contenir des données et ne pas dépasser 8 Mo.');
    }
    $mime = $file->getMimeType();
    if (!in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true)) {
      throw new \InvalidArgumentException('Format non pris en charge. Utilisez un justificatif PDF, JPG, PNG ou WebP.');
    }
    if ($mime !== 'application/pdf') {
      $dimensions = @getimagesize($file->getPathname());
      if ($dimensions === false || $dimensions[0] <= 0 || $dimensions[1] <= 0 || $dimensions[0] * $dimensions[1] > 40000000) {
        throw new \InvalidArgumentException('L’image est illisible ou trop grande. Utilisez une photo lisible de moins de 40 mégapixels.');
      }
    }
    try {
      $ocr = $this->ocr->extractText($file);
    } catch (\Throwable $error) {
      // Provider/configuration exceptions must never be returned as user input errors.
      throw new \RuntimeException('OCR provider failed.', 0, $error);
    }
    $text = is_string($ocr['text'] ?? null) ? trim($ocr['text']) : '';
    $lines = is_array($ocr['lines'] ?? null) ? array_filter($ocr['lines'], static fn ($line) => is_string($line) && trim($line) !== '') : [];
    if ($text === '' && $lines === []) {
      throw new \InvalidArgumentException('Aucun texte lisible n’a été détecté. Reprenez une photo nette et bien éclairée, ou saisissez la dépense manuellement.');
    }
    return $this->parser->parse($ocr);
  }
}
