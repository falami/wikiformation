<?php

declare(strict_types=1);
namespace App\Service\Pdf;

use App\Entity\Entite;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class OrganisationBranding
{
    public function __construct(#[Autowire('%kernel.project_dir%')] private readonly string $projectDir) {}

    public function forEntity(Entite $entite): array
    {
        $color = static fn (?string $value, string $fallback): string => preg_match('/^#[0-9a-fA-F]{6}$/D', $value ?? '') ? $value : $fallback;
        return ['logo' => $this->logoDataUri($entite),
            'primary' => $color($entite->getCouleurPrincipal(), '#233342'),
            'accent' => $color($entite->getCouleurSecondaire(), '#ffc107')];
    }

    public function logoDataUri(Entite $entite): ?string
    {
        $name = $entite->getLogo();
        if (!$name || basename($name) !== $name) return null;
        $root = realpath($this->projectDir . '/public/uploads/photos/entite/logo');
        $path = $root ? realpath($root . '/' . $name) : false;
        if (!$path || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path) || !is_readable($path)) return null;
        if (filesize($path) > 8 * 1024 * 1024) return null;
        $info = @getimagesize($path);
        if (!$info || $info[0] * $info[1] > 8_000_000) return null;
        $mime = $info['mime'] ?? '';
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) return null;
        $bytes = file_get_contents($path);
        if ($bytes === false) return null;

        // Dompdf does not support WebP on every host. Embed a bounded PNG instead.
        if ($mime === 'image/webp') {
            if (!function_exists('imagecreatefromwebp')) return null;
            $source = @imagecreatefromwebp($path);
            if (!$source) return null;
            ob_start();
            imagepng($source);
            $bytes = ob_get_clean();
            imagedestroy($source);
            if (!is_string($bytes)) return null;
            $mime = 'image/png';
        }

        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }
}
