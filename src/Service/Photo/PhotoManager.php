<?php

namespace App\Service\Photo;


use Psr\Log\LoggerInterface;
use App\Service\FileUploader;
use Imagine\Gd\Imagine;
use Imagine\Image\Box;
use Imagine\Image\ImageInterface;

class PhotoManager {

    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }


    public function deleteImageIfExists(?string $filename, string $uploadPath): void
    {
        if ($filename) {
            $filepath = $uploadPath . '/' . $filename;
            if (file_exists($filepath)) {
                unlink($filepath);
            }
        }
    }



    public function handleImageUpload($form, string $fieldName, callable $setter, FileUploader $fileUploader, string $uploadPath, int $sizeW, int $sizeH, ?string $oldFilename = null): void 
    {
        $imageFile = $form->get($fieldName)->getData();
        if ($imageFile) {
            // Supprimer l'ancien fichier s'il existe
            if ($oldFilename) {
                $oldFilePath = $uploadPath . '/' . $oldFilename;
                if (file_exists($oldFilePath)) {
                    unlink($oldFilePath);
                }
            }
    
            // Upload du nouveau fichier
            $fileName = $fileUploader->upload($imageFile, $uploadPath);
    
            // Redimensionnement
            $imagine = new Imagine();
            $imagePath = $uploadPath . '/' . $fileName;
            $size = new Box($sizeW, $sizeH);
            $mode = ImageInterface::THUMBNAIL_OUTBOUND;
    
            $imagine->open($imagePath)
                ->thumbnail($size, $mode)
                ->save($imagePath);
    
            $setter($fileName);
        }
    }


    public function deleteIfExists(string $basePath, string $filename): void
    {
        $path = rtrim($basePath, '/').'/'.$filename;
        if (is_file($path)) @unlink($path);
    }



}