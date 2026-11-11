<?php

declare(strict_types=1);

namespace App\Service\Email;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Resolves only the current organisation's local logo, without remote requests. */
final class ArchivedEmailPreview
{
    public function __construct(#[Autowire('%kernel.project_dir%')] private string $projectDir) {}

    public function render(string $html, ?string $logo): string
    {
        if (!$logo || basename($logo) !== $logo) return $html;
        $directory = realpath($this->projectDir.'/public/uploads/photos/entite/logo');
        $file = $directory ? realpath($directory.'/'.$logo) : false;
        if (!$file || !str_starts_with($file, $directory.DIRECTORY_SEPARATOR) || !is_file($file) || !is_readable($file) || filesize($file) > 2_000_000) return $html;
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file);
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) return $html;
        $data = file_get_contents($file);
        if ($data === false) return $html;
        $uri = 'data:'.$mime.';base64,'.base64_encode($data);
        $path = '/uploads/photos/entite/logo/'.$logo;
        return preg_replace_callback('~(<img\b[^>]*?\s src\s*=\s*)([\x22\x27])(.*?)\2~ixs', static function (array $match) use ($path, $uri): string {
            $source = html_entity_decode($match[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $sourcePath = parse_url($source, PHP_URL_PATH);
            return is_string($sourcePath) && rawurldecode($sourcePath) === $path
                ? $match[1].$match[2].$uri.$match[2] : $match[0];
        }, $html) ?? $html;
    }
}
