<?php

namespace App\Service\Document;

use App\Entity\{DocumentFormateurVersion, Utilisateur};
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Private storage: files are served only through an authorized controller. */
class DocumentFormateurStorage
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/var/storage/documents-formateurs')]
        private readonly string $directory,
    ) {}

    public function store(UploadedFile $file, Utilisateur $user, int $numero, ?string $note): DocumentFormateurVersion
    {
        $filename = bin2hex(random_bytes(24)) . '.' . strtolower($file->getClientOriginalExtension());
        $version = (new DocumentFormateurVersion())
            ->setNumero($numero)->setFilename($filename)
            ->setOriginalName(mb_substr(basename($file->getClientOriginalName()), 0, 255))
            ->setMimeType($file->getMimeType() ?: 'application/octet-stream')
            ->setSizeBytes((int) $file->getSize())->setUploadedBy($user)->setNote($note);
        $file->move($this->directory, $filename);
        return $version;
    }

    public function path(DocumentFormateurVersion $version): string
    {
        return $this->directory . '/' . basename($version->getFilename());
    }

    /** Only used to clean up an upload whose database transaction failed. */
    public function discard(DocumentFormateurVersion $version): void
    {
        $path = $this->path($version);
        if (is_file($path)) unlink($path);
    }
}
