<?php

namespace App\Service\Depense;

use App\Service\Ocr\GoogleVisionOcrService;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class GoogleVisionReceiptOcrProvider implements ReceiptOcrProviderInterface
{
  public function __construct(
    private GoogleVisionOcrService $vision,
  ) {}

  public function extractText(UploadedFile $file): array
  {
    $res = $this->vision->ocr($file);

    $text = (string)($res['text'] ?? '');
    $lines = preg_split("/\R/u", $text) ?: [];

    // Normalise un minimum
    $lines = array_values(array_filter(array_map('trim', $lines), fn($l) => $l !== ''));

    return [
      'text'  => $text,
      'lines' => $lines,
    ];
  }
}
