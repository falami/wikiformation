<?php

namespace App\Service\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment as Twig;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class PdfManager
{
    private $options;

    public function __construct(private Twig $twig, private string $pdfOutputDir)
    {
        $this->options = new Options();
        $this->options->set('defaultFont', 'Arial');
        $this->options->set('isRemoteEnabled', true);
    }

    private function createPdf(string $html, string $orientation): Dompdf
    {
        $dompdf = new Dompdf($this->options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', $orientation);
        $dompdf->render();

        return $dompdf;
    }

    public function createPortrait(string $html, string $nameFile): Response
    {
        $dompdf = $this->createPdf($html, 'portrait');
        return $this->buildResponse($dompdf, $nameFile);
    }

    public function createLandscape(string $html, string $nameFile): Response
    {
        $dompdf = $this->createPdf($html, 'landscape');
        return $this->buildResponse($dompdf, $nameFile);
    }

    private function buildResponse(Dompdf $dompdf, string $nameFile): Response
    {
        $pdfOutput = $dompdf->output();
        return new Response($pdfOutput, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $nameFile . '.pdf"',
            'Content-Transfer-Encoding' => 'binary',
            'Accept-Ranges' => 'bytes',
        ]);
    }



    /** @return string chemin absolu du PDF généré */
    public function renderToFile(string $template, array $vars, string $filename): string
    {
        $html = $this->twig->render($template, $vars);

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        (new Filesystem())->mkdir($this->pdfOutputDir);
        $path = rtrim($this->pdfOutputDir, '/') . '/' . $filename;
        file_put_contents($path, $dompdf->output());
        return $path;
    }

    public function convocation(array $vars, string $file): string
    {
        return $this->renderToFile('pdf/convocation.html.twig', $vars, $file);
    }

    public function attestation(array $vars, string $file): string
    {
        return $this->renderToFile('pdf/attestation.html.twig', $vars, $file);
    }

    public function feuilleEmargementsSynthese(array $vars, string $file): string
    {
        return $this->renderToFile('pdf/feuille_emargements_synthese.html.twig', $vars, $file);
    }

    public function conventionContrat(array $vars, string $file): string
    {
        return $this->renderToFile('pdf/convention_contrat.html.twig', $vars, $file);
    }

    // src/Service/Pdf/PdfManager.php

    public function contratFormateur(array $vars, string $file): string
    {
        return $this->renderToFile('pdf/contrat_formateur.html.twig', $vars, $file);
    }

    public function outputPortrait(string $html): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        return $dompdf->output(); // ✅ bytes PDF
    }
    public function createPortraitBytes(string $html): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    public function createLandscapeBytes(string $html): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return $dompdf->output();
    }



    public function streamPdfFromHtml(string $html, string $filename, string $orientation = 'portrait'): Response
    {
        // filename attendu déjà avec .pdf (ex: RESULTAT_XXX.pdf)
        $dompdf = $this->createPdf($html, $orientation);

        $pdfOutput = $dompdf->output();

        $response = new Response($pdfOutput);
        $disposition = $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $filename
        );

        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', $disposition);
        $response->headers->set('Content-Transfer-Encoding', 'binary');
        $response->headers->set('Accept-Ranges', 'bytes');

        return $response;
    }
}
