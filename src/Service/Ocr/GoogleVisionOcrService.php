<?php

namespace App\Service\Ocr;

use Google\Cloud\Vision\V1\Client\ImageAnnotatorClient;
use Google\Cloud\Vision\V1\Feature\Type as FeatureType;
use Google\Cloud\Storage\StorageClient;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use Google\Cloud\Vision\V1\AnnotateImageRequest;
use Google\Cloud\Vision\V1\BatchAnnotateImagesRequest;
use Google\Cloud\Vision\V1\Feature;
use Google\Cloud\Vision\V1\Image;

use Google\Cloud\Vision\V1\AsyncAnnotateFileRequest;
use Google\Cloud\Vision\V1\AsyncBatchAnnotateFilesRequest;
use Google\Cloud\Vision\V1\GcsDestination;
use Google\Cloud\Vision\V1\GcsSource;
use Google\Cloud\Vision\V1\InputConfig;
use Google\Cloud\Vision\V1\OutputConfig;
use Psr\Log\LoggerInterface;

final class GoogleVisionOcrService
{
  public function __construct(
    private readonly string $gcpProjectId,
    private readonly string $credentialsPath,
    private readonly ?string $gcsBucket = null,
    private readonly string $gcsPrefix = 'ocr-output',
    private readonly ?LoggerInterface $logger = null,
  ) {}

  public function ocr(UploadedFile $file): array
  {
    $mime = (string) $file->getMimeType();
    $path = $file->getPathname();

    $isPdf  = $mime === 'application/pdf';
    $isTiff = $mime === 'image/tiff';

    if ($isPdf || $isTiff) {
      return $this->ocrPdfOrTiffViaGcs($path, $mime ?: ($isPdf ? 'application/pdf' : 'image/tiff'));
    }

    return $this->ocrImageSync($path);
  }

  private function assertCreds(): void
  {
    if (!is_file($this->credentialsPath) || !is_readable($this->credentialsPath)) {
      throw new \RuntimeException('Le service de lecture des justificatifs n’est pas configuré.');
    }
  }

  private function visionClient(): ImageAnnotatorClient
  {
    $this->assertCreds();
    return new ImageAnnotatorClient([
      'credentials' => $this->credentialsPath,
    ]);
  }

  private function storageClient(): StorageClient
  {
    $this->assertCreds();
    return new StorageClient([
      'projectId' => $this->gcpProjectId,
      'keyFilePath' => $this->credentialsPath,
      'requestTimeout' => 15,
      'retries' => 1,
    ]);
  }

  private function ocrImageSync(string $localPath): array
  {
    $client = $this->visionClient();

    try {
      $imageData = file_get_contents($localPath);
      if ($imageData === false) {
        throw new \RuntimeException('Impossible de lire le fichier image.');
      }

      $image = new Image();
      $image->setContent($imageData);

      $feature = new Feature();
      $feature->setType(FeatureType::DOCUMENT_TEXT_DETECTION);

      $req = new AnnotateImageRequest();
      $req->setImage($image);
      $req->setFeatures([$feature]);

      $batch = new BatchAnnotateImagesRequest();
      $batch->setRequests([$req]);

      $resp = $client->batchAnnotateImages($batch, ['retrySettings' => ['retriesEnabled' => false, 'noRetriesRpcTimeoutMillis' => 20000]]);
      $responses = $resp->getResponses();

      if (count($responses) < 1) {
        throw new \RuntimeException('Vision: réponse vide.');
      }

      $r0 = $responses[0];
      if ($r0->hasError()) {
        throw new \RuntimeException('Vision API error: ' . $r0->getError()->getMessage());
      }

      $fullText = '';
      $doc = $r0->getFullTextAnnotation();

      if ($doc && $doc->getText()) {
        $fullText = $doc->getText();
      } else {
        $ann = $r0->getTextAnnotations();
        if (count($ann) > 0) {
          $fullText = (string) $ann[0]->getDescription();
        }
      }

      $lines = preg_split("/\R/u", (string) $fullText) ?: [];

      return [
        'engine' => 'google_vision',
        'mode' => 'image_sync',
        'text' => trim((string) $fullText),
        'lines' => array_values(array_filter(array_map('trim', $lines), fn($l) => $l !== '')),
      ];
    } finally {
      $client->close();
    }
  }

  private function ocrPdfOrTiffViaGcs(string $localPath, string $mimeType): array
  {
    if (!$this->gcsBucket) {
      throw new \RuntimeException('GCP_GCS_BUCKET manquant : requis pour OCR PDF/TIFF via Vision (GCS).');
    }

    $storage = $this->storageClient();
    $bucket = $storage->bucket($this->gcsBucket);

    $inputObject = sprintf('ocr-input/%s-%s', date('Ymd-His'), bin2hex(random_bytes(6)));
    $gcsInputUri = sprintf('gs://%s/%s', $this->gcsBucket, $inputObject);

    $outputPrefix = trim($this->gcsPrefix, '/');
    $outputDir = sprintf('%s/%s-%s/', $outputPrefix, date('Ymd-His'), bin2hex(random_bytes(4)));
    $gcsOutputUri = sprintf('gs://%s/%s', $this->gcsBucket, $outputDir);

    $client = null;
    $stream = null;

    try {
      $stream = fopen($localPath, 'rb');
      if ($stream === false) {
        throw new \RuntimeException('Impossible de lire le justificatif.');
      }
      $bucket->upload($stream, ['name' => $inputObject, 'contentType' => $mimeType]);
      $client = $this->visionClient();
      $batchSize = 10;

      $gcsSource = new GcsSource();
      $gcsSource->setUri($gcsInputUri);

      $inputConfig = new InputConfig();
      $inputConfig->setGcsSource($gcsSource);
      $inputConfig->setMimeType($mimeType);

      $feature = new Feature();
      $feature->setType(FeatureType::DOCUMENT_TEXT_DETECTION);

      $gcsDest = new GcsDestination();
      $gcsDest->setUri($gcsOutputUri);

      $outputConfig = new OutputConfig();
      $outputConfig->setGcsDestination($gcsDest);
      $outputConfig->setBatchSize($batchSize);

      $fileRequest = new AsyncAnnotateFileRequest();
      $fileRequest->setInputConfig($inputConfig);
      $fileRequest->setFeatures([$feature]);
      $fileRequest->setOutputConfig($outputConfig);

      $batchRequest = new AsyncBatchAnnotateFilesRequest();
      $batchRequest->setRequests([$fileRequest]);

      $operation = $client->asyncBatchAnnotateFiles($batchRequest, ['retrySettings' => ['retriesEnabled' => false, 'noRetriesRpcTimeoutMillis' => 15000]]);

      if (!$operation->pollUntilComplete(['initialPollDelayMillis' => 500, 'maxPollDelayMillis' => 2000, 'totalPollTimeoutMillis' => 45000])) {
        try {
          $operation->cancel();
        } catch (\Throwable $cancelError) {
          $this->logger?->warning('Impossible d’annuler une lecture OCR expirée.', ['exception' => $cancelError]);
        }
        throw new \RuntimeException('La lecture du PDF a pris trop de temps. Réessayez avec une photo ou un document plus court.');
      }

      if (!$operation->operationSucceeded()) {
        $status = $operation->getError();
        $msg = $status ? $status->getMessage() : 'Erreur OCR PDF/TIFF (operation failed).';
        throw new \RuntimeException($msg);
      }

      $text = $this->readVisionOutputJsonFromPrefix($bucket, $outputDir);
      $lines = preg_split("/\R/u", (string) $text) ?: [];

      return [
        'engine' => 'google_vision',
        'mode' => 'pdf_async_gcs',
        'gcsInputUri' => $gcsInputUri,
        'gcsOutputUri' => $gcsOutputUri,
        'text' => trim((string) $text),
        'lines' => array_values(array_filter(array_map('trim', $lines), fn($l) => $l !== '')),
      ];
    } finally {
      $client?->close();
      if (is_resource($stream)) {
        fclose($stream);
      }
      // Documents and recognized text are temporary working files, not archives.
      try {
        $bucket->object($inputObject)->delete();
      } catch (\Throwable $cleanupError) {
        $this->logger?->warning('Nettoyage du justificatif OCR temporaire impossible.', ['exception' => $cleanupError]);
      }
      try {
        foreach ($bucket->objects(['prefix' => $outputDir]) as $object) {
          $object->delete();
        }
      } catch (\Throwable $cleanupError) {
        $this->logger?->warning('Nettoyage du texte OCR temporaire impossible.', ['exception' => $cleanupError]);
      }
    }
  }

  private function readVisionOutputJsonFromPrefix(\Google\Cloud\Storage\Bucket $bucket, string $prefix): string
  {
    $fullText = '';
    $jsonObjects = [];

    foreach ($bucket->objects(['prefix' => $prefix]) as $object) {
      if (!str_ends_with($object->name(), '.json')) continue;
      $jsonObjects[] = $object;
    }

    if ($jsonObjects === []) {
      throw new \RuntimeException('La lecture du document n’a produit aucun résultat.');
    }
    usort($jsonObjects, fn($a, $b) => strnatcmp($a->name(), $b->name()));

    foreach ($jsonObjects as $object) {
      $json = $object->downloadAsString();
      $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

      if (!is_array($data) || !isset($data['responses']) || !is_array($data['responses']) || $data['responses'] === []) {
        throw new \RuntimeException('La lecture du document a renvoyé un résultat incomplet.');
      }

      foreach ($data['responses'] as $resp) {
        if (!is_array($resp) || !empty($resp['error']['code'])) {
          throw new \RuntimeException('Une page du document n’a pas pu être lue. Aucun montant n’a été prérempli.');
        }
        if (isset($resp['fullTextAnnotation']['text'])) {
          $fullText .= $resp['fullTextAnnotation']['text'] . "\n";
        } elseif (isset($resp['textAnnotations'][0]['description'])) {
          $fullText .= $resp['textAnnotations'][0]['description'] . "\n";
        }
      }
    }

    return $fullText;
  }
}
