<?php
declare(strict_types=1);
namespace App\Service\Habilitation;

use App\Entity\HabilitationRevision;
use App\Service\Pdf\OrganisationBranding;
use Dompdf\{Dompdf, Options};
use Twig\Environment;

final class HabilitationDocument
{
    public function __construct(private Environment $twig, private OrganisationBranding $branding) {}
    public function bytes(HabilitationRevision $r, bool $attestation = false): string
    {
        $dossier = $r->getDossier();
        $entite = $dossier->getEntite();
        $options = new Options(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
        $pdf = new Dompdf($options); $pdf->setPaper('A4', $attestation ? 'portrait' : 'landscape');
        $snapshot = $r->getSnapshot();
        if ($r->getKind() === 'titre') {
            foreach ($snapshot['schema']['sections'] as &$section) {
                $section['rows'] = array_values(array_filter($section['rows'], static function ($row) use ($snapshot) {
                    foreach ($row['fields'] as $field) if ($field['type'] === 'checkbox' && !empty($snapshot['data']['rows'][$row['id']][$field['id']])) return true;
                    return false;
                }));
            }
            unset($section);
            $snapshot['schema']['sections'] = array_values(array_filter($snapshot['schema']['sections'], static fn ($section) => $section['rows'] !== []));
        }
        $footerHtml = $this->twig->render($attestation ? 'habilitation/attestation.html.twig' : 'habilitation/pdf.html.twig', ['entite' => $entite, 'brand' => $this->branding->forEntity($entite), 'revision' => $r, 'snapshot' => $snapshot, 'archived' => !in_array($r->getToken(), [$dossier->getAvisToken(), $dossier->getTitreToken()], true), 'inactive' => !$dossier->getActive() || $dossier->getDeleted()]);
        $pdf->loadHtml(\App\Service\Pdf\DocumentFooter::prepare($footerHtml));
        $pdf->render();
        \App\Service\Pdf\DocumentFooter::draw($pdf, $footerHtml);
        return $pdf->output();
    }
}
