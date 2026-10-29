<?php

declare(strict_types=1);

namespace App\Service\Pdf;

use App\Entity\Entreprise;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Captures the employer's identity in signed documents, independently of the training provider. */
final class CompanyBranding
{
    public function __construct(#[Autowire('%kernel.project_dir%')] private readonly string $projectDir) {}

    public function snapshot(Entreprise $company): array
    {
        return [
            'name' => (string) $company->getRaisonSociale(),
            'address' => implode("\n", array_filter([
                trim((string) $company->getAdresse()),
                trim((string) $company->getComplement()),
                trim($company->getCodePostal().' '.$company->getVille()),
                trim((string) $company->getPays()),
            ])),
            'siret' => (string) $company->getSiret(),
            'logoDataUri' => $this->logoDataUri($company->getLogo()),
        ];
    }

    private function logoDataUri(?string $name): ?string
    {
        if (!$name || str_contains($name, "\0") || basename($name) !== $name || str_contains($name, '\\')) return null;
        $root = realpath($this->projectDir.'/public/uploads/entreprises/logo');
        $path = $root ? realpath($root.'/'.$name) : false;
        if (!$path || !str_starts_with($path, $root.DIRECTORY_SEPARATOR) || !is_file($path) || !is_readable($path)) return null;
        if (filesize($path) > 4 * 1024 * 1024) return null;
        $bytes = file_get_contents($path);
        if ($bytes === false) return null;
        $info = @getimagesizefromstring($bytes);
        if (!$info || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 8_000_000
            || !in_array($info['mime'] ?? '', ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)
            || !function_exists('imagecreatefromstring')) return null;

        // Re-encode locally: no URL fetch, SVG, metadata or file reference survives in the snapshot.
        $source = @imagecreatefromstring($bytes);
        if (!$source) return null;
        $ratio = min(1, 800 / max($info[0], $info[1]));
        $width = max(1, (int) round($info[0] * $ratio));
        $height = max(1, (int) round($info[1] * $ratio));
        $target = imagecreatetruecolor($width, $height);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, imagecolorallocatealpha($target, 255, 255, 255, 127));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, $info[0], $info[1]);
        ob_start();
        imagepng($target);
        $png = ob_get_clean();
        imagedestroy($target);
        imagedestroy($source);
        return is_string($png) ? 'data:image/png;base64,'.base64_encode($png) : null;
    }

    /** Only embedded raster data is accepted at rendering; never resolve a path from a snapshot. */
    public static function snapshotLogo(mixed $uri): ?string
    {
        if (!is_string($uri) || strlen($uri) > 6 * 1024 * 1024 || !str_starts_with($uri, 'data:image/png;base64,')) return null;
        $bytes = base64_decode(substr($uri, 22), true);
        $info = $bytes === false ? false : @getimagesizefromstring($bytes);
        return $info && ($info['mime'] ?? '') === 'image/png' && $info[0] > 0 && $info[1] > 0
            && $info[0] * $info[1] <= 8_000_000 ? $uri : null;
    }
}
