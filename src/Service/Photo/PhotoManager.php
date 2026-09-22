<?php

namespace App\Service\Photo;

use App\Service\FileUploader;
use Imagine\Gd\Imagine;
use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class PhotoManager
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function deleteImageIfExists(?string $filename, string $uploadPath): void
    {
        if ($filename !== null && $filename !== '') {
            $this->deleteIfExists($uploadPath, $filename);
        }
    }

    public function handleImageUpload(FormInterface $form, string $fieldName, callable $setter, FileUploader $fileUploader, string $uploadPath, int $sizeW, int $sizeH, ?string $oldFilename = null): void
    {
        $imageFile = $form->get($fieldName)->getData();
        if (!$imageFile instanceof UploadedFile) {
            return;
        }

        $fileName = $this->uploadImage($imageFile, $fileUploader, $uploadPath, $sizeW, $sizeH);
        try {
            $setter($fileName);
        } catch (\Throwable $exception) {
            $this->deleteIfExists($uploadPath, $fileName);
            throw new ImageUploadException('Cette image n’a pas pu être traitée. Essayez une image JPEG, PNG ou WebP plus petite.', 0, $exception);
        }

        if ($oldFilename !== $fileName) {
            $this->deleteImageIfExists($oldFilename, $uploadPath);
        }
    }

    /**
     * Process every image before replacing any existing photo. This also handles
     * gallery fields containing several UploadedFile instances.
     *
     * @param array<string, array{setter: callable, width: int, height: int, oldFilename?: ?string}> $fields
     */
    public function handleFormImageUploads(FormInterface $form, array $fields, FileUploader $fileUploader, string $uploadPath): bool
    {
        $jobs = [];
        $processed = [];
        $fieldName = '';
        try {
            foreach ($fields as $fieldName => $configuration) {
                $data = $form->get($fieldName)->getData();
                foreach (is_array($data) ? $data : [$data] as $file) {
                    if (!$file instanceof UploadedFile) {
                        continue;
                    }
                    $this->assertProcessable($file, $configuration['width'], $configuration['height']);
                    $jobs[] = ['field' => $fieldName, 'file' => $file, 'configuration' => $configuration];
                }
            }
            foreach ($jobs as $job) {
                $fieldName = $job['field'];
                $configuration = $job['configuration'];
                $filename = $this->uploadImage($job['file'], $fileUploader, $uploadPath, $configuration['width'], $configuration['height']);
                $processed[] = ['filename' => $filename, 'configuration' => $configuration];
            }
        } catch (ImageUploadException $exception) {
            foreach ($processed as $image) {
                $this->deleteIfExists($uploadPath, $image['filename']);
            }
            $form->get($fieldName)->addError(new FormError($exception->getMessage()));
            return false;
        }

        foreach ($processed as $image) {
            ($image['configuration']['setter'])($image['filename']);
        }
        foreach ($processed as $image) {
            $this->deleteImageIfExists($image['configuration']['oldFilename'] ?? null, $uploadPath);
        }
        return true;
    }

    private function uploadImage(UploadedFile $imageFile, FileUploader $fileUploader, string $uploadPath, int $sizeW, int $sizeH): string
    {
        // Read only the header before GD decodes the full bitmap. A 4 MB JPEG
        // may expand to hundreds of MB, regardless of its compressed file size.
        $this->assertProcessable($imageFile, $sizeW, $sizeH);
        $fileName = null;
        try {
            $fileName = $fileUploader->upload($imageFile, $uploadPath);
            $imagePath = rtrim($uploadPath, '/') . '/' . $fileName;
            (new Imagine())->open($imagePath)
                ->thumbnail(new Box($sizeW, $sizeH), ImageInterface::THUMBNAIL_OUTBOUND | ImageInterface::THUMBNAIL_FLAG_NOCLONE)
                ->save($imagePath);
        } catch (\Throwable $exception) {
            if ($fileName !== null) {
                $this->deleteIfExists($uploadPath, $fileName);
            }
            $this->logger->warning('Image upload could not be processed.', ['exception' => $exception]);
            throw new ImageUploadException('Cette image n’a pas pu être traitée. Essayez une image JPEG, PNG ou WebP plus petite.', 0, $exception);
        }

        return $fileName;
    }

    public function assertProcessable(UploadedFile $file, int $targetWidth, int $targetHeight): void
    {
        if ($targetWidth < 1 || $targetHeight < 1) {
            throw new \InvalidArgumentException('Image dimensions must be positive.');
        }
        $size = @getimagesize($file->getPathname());
        if ($size === false || !in_array($size[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
            throw new ImageUploadException('Choisissez une image JPEG, PNG, WebP ou GIF valide.');
        }

        [$width, $height] = $size;
        // OUTBOUND may first create a larger intermediate image before cropping
        // (e.g. a portrait photo used as a landscape banner). Account for it too.
        $ratio = min(1, max($targetWidth / $width, $targetHeight / $height));
        $intermediatePixels = ceil($width * $ratio) * ceil($height * $ratio);
        $estimatedBytes = ($width * $height * 6) + ($intermediatePixels * 8) + ($file->getSize() * 2) + (16 * 1024 * 1024);
        $limit = ini_parse_quantity((string) ini_get('memory_limit'));
        $available = $limit > 0 ? $limit - memory_get_usage(true) : 256 * 1024 * 1024;
        if ($width * $height > 24_000_000 || $estimatedBytes > $available) {
            throw new ImageUploadException('Cette image est trop grande pour être traitée. Réduisez-la à 2 400 pixels sur son côté le plus long, puis réessayez. Votre photo actuelle est conservée.');
        }
    }

    public function deleteIfExists(string $basePath, string $filename): void
    {
        // Stored filenames must never escape the configured upload directory.
        if ($filename === '' || basename($filename) !== $filename) {
            return;
        }
        $path = rtrim($basePath, '/') . '/' . $filename;
        if (is_file($path) && !@unlink($path)) {
            $this->logger->warning('An obsolete uploaded image could not be removed.', ['filename' => $filename]);
        }
    }
}
