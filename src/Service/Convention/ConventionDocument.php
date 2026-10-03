<?php

declare(strict_types=1);

namespace App\Service\Convention;

use App\Entity\ConventionContrat;
use App\Service\Pdf\PdfManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\{BinaryFileResponse, Response};
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;

/** Même aperçu dans les espaces organisme, entreprise et stagiaire. */
final class ConventionDocument
{
    public function __construct(
        private readonly Environment $twig,
        private readonly PdfManager $pdf,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {}

    public function response(ConventionContrat $convention): Response
    {
        if ($convention->isSigned()) {
            // Une signature fige le document : jamais de recalcul silencieux.
            $stored = $convention->getPdfPath() ?? '';
            $private = str_starts_with($stored, 'private:');
            $root = realpath($this->projectDir . ($private ? '/var/storage/conventions' : '/public'));
            $relative = $private ? substr($stored, 8) : ltrim($stored, '/');
            $path = $root && $relative !== '' ? realpath($root . '/' . $relative) : false;
            if (!$path || !is_file($path) || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
                throw new NotFoundHttpException('Le PDF signé est introuvable. Son original doit être restauré.');
            }
            $response = new BinaryFileResponse($path);
        } else {
            // Les anciens PDF non signés peuvent encore contenir l’amplitude
            // incluant le déjeuner. Recalculer l’aperçu ne modifie aucun fichier.
            $html = $this->twig->render('pdf/convention_contrat.html.twig', [
                'entite' => $convention->getEntite(), 'convention' => $convention,
                'session' => $convention->getSession(),
            ]);
            $response = $this->pdf->streamPdfFromHtml($html, 'Convention-' . $convention->getNumero() . '.pdf');
        }
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        return $response;
    }
}
