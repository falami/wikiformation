<?php

declare(strict_types=1);

namespace App\Service\Delegation;

use App\Entity\Entite;
use App\Service\Pdf\{PdfManager, ContratFormateurDocument};
use App\Service\Convention\ConventionDocument;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/** Shared PDF rendering; callers must first authorize the record and its tenant. */
final class DossierDocumentResponse
{
    public function __construct(
        private readonly PdfManager $pdf,
        private readonly ConventionDocument $conventions,
        private readonly ContratFormateurDocument $trainerDocument,
        private readonly Environment $twig,
    ) {}

    public function response(Entite $entite, string $module, object $record): Response
    {
        if ($module === 'contrats-formateurs') {
            if ($this->trainerDocument->isFrozen($record)) {
                $path = $this->trainerDocument->storedPath($record);
                if (!$path) {
                    throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Le document signé doit être restauré par un administrateur.');
                }
                return new \Symfony\Component\HttpFoundation\BinaryFileResponse($path, 200, ['Cache-Control' => 'private, no-store']);
            }
            return $this->pdf->createPortrait($this->twig->render('pdf/contrat_formateur.html.twig', $this->trainerDocument->templateData($record)), 'Contrat-' . $record->getNumero());
        }
        if ($module === 'conventions') {
            return $this->conventions->response($record);
        }
        $name = $module === 'devis' ? 'devis' : 'facture';
        $response = $this->pdf->createPortrait($this->twig->render('pdf/' . $name . '.html.twig', ['entite' => $entite, $name => $record]), $name . '-' . $record->getNumero());
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }
}
