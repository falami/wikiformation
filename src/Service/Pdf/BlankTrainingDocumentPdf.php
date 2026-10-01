<?php

declare(strict_types=1);

namespace App\Service\Pdf;

use App\Entity\Entite;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/** Printable fallback forms: intentionally contain no session or participant data. */
final class BlankTrainingDocumentPdf
{
    public function __construct(
        private readonly Environment $twig,
        private readonly PdfManager $pdf,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {}

    public static function catalogue(): array
    {
        return [
            'emargement' => [
                'title' => 'Feuille d’émargement vierge',
                'description' => 'Une feuille par journée, avec signatures du matin et de l’après-midi.',
                'icon' => 'bi-pen',
            ],
            'appreciation-formateur' => [
                'title' => 'Compte rendu du formateur',
                'description' => 'Bilan de l’intervention, suivi du groupe et pistes d’amélioration.',
                'icon' => 'bi-person-check',
            ],
            'appreciation-stagiaire' => [
                'title' => 'Appréciation du stagiaire',
                'description' => 'Questionnaire de satisfaction à compléter en fin de formation.',
                'icon' => 'bi-chat-square-heart',
            ],
        ];
    }

    public function download(Entite $entite, string $type): Response
    {
        $model = self::catalogue()[$type] ?? throw new \InvalidArgumentException('Modèle de document inconnu.');
        $html = $this->twig->render('pdf/modeles/' . $type . '.html.twig', [
            'entite' => $entite,
            'documentTitle' => $model['title'],
            'logoDataUri' => $this->logoDataUri($entite),
        ]);
        $filename = 'modele-' . $type . '-organisme-' . $entite->getId();
        $response = $type === 'emargement'
            ? $this->pdf->createLandscape($html, $filename)
            : $this->pdf->createPortrait($html, $filename);

        // Logo and organisation identity are tenant-specific, never shared by a cache.
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    private function logoDataUri(Entite $entite): ?string
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
