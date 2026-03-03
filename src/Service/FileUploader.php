<?php

// src/Service/FileUploader.php
namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

class FileUploader
{
    /**
     * Dépose le fichier dans $uploadPath et renvoie le nom final.
     */
    public function upload(UploadedFile $file, string $uploadPath): string
    {
        if (!is_dir($uploadPath)) {
            @mkdir($uploadPath, 0775, true);
        }

        $ext = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin');
        $base = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safe = preg_replace('~[^a-z0-9-_]+~i', '-', $base) ?: 'file';
        $filename = sprintf('%s-%s.%s', $safe, bin2hex(random_bytes(6)), $ext);

        $file->move($uploadPath, $filename);

        return $filename;
    }
}
