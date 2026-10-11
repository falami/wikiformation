<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Embeds private organization marks in PDFs without anonymous HTTP requests. */
final class PrivateDocumentImageExtension extends AbstractExtension
{
    public function __construct(#[Autowire('%kernel.project_dir%')] private readonly string $projectDir) {}

    public function getFunctions(): array
    {
        return [new TwigFunction('private_document_image', [$this, 'dataUri'])];
    }

    public function dataUri(?string $relative): ?string
    {
        if (!$relative || str_contains($relative, "\0") || str_contains($relative, '\\')) return null;
        $relative = ltrim($relative, '/');
        if (!preg_match('~^uploads/(signatures|tampons)/organisme/[^/]+$~D', $relative)) return null;
        $root = realpath($this->projectDir.'/public/'.dirname($relative));
        $path = realpath($this->projectDir.'/public/'.$relative);
        if (!$root || !$path || !str_starts_with($path, $root.DIRECTORY_SEPARATOR) || !is_file($path)
            || filesize($path) > 5 * 1024 * 1024) return null;
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) return null;
        return 'data:'.$mime.';base64,'.base64_encode(file_get_contents($path));
    }
}
