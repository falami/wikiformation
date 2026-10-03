<?php

declare(strict_types=1);

namespace App\Service\Automation;

use App\Entity\Automation\TrainingWorkflow;
use App\Service\Pdf\PdfManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Twig\Environment;

/** Freezes the reviewed document and the consent record in private storage. */
final class WorkflowSignature
{
    public function __construct(
        private readonly Environment $twig,
        private readonly PdfManager $pdf,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {}

    public function html(TrainingWorkflow $workflow): string
    {
        return $this->twig->render('pdf/convention_contrat.html.twig', [
            'entite' => $workflow->getEntite(), 'session' => $workflow->getSession(), 'convention' => $workflow->getConvention(),
        ]);
    }

    public function digest(TrainingWorkflow $workflow): string { return hash('sha256', $this->html($workflow)); }

    public function previewPath(TrainingWorkflow $workflow): string
    {
        $html = $this->html($workflow);
        $path = $this->directory().'/preview-'.$workflow->getId().'-'.hash('sha256', $html).'.pdf';
        if (!is_file($path)) {
            // Publish atomically and keep the first exact byte snapshot if two previews race.
            $temporary = tempnam($this->directory(), '.preview-');
            if (!$temporary) throw new \RuntimeException('Impossible de préparer le document.');
            try {
                $this->write($temporary, $this->pdf->createPortraitBytes($html));
                if (!@link($temporary, $path) && !is_file($path)) throw new \RuntimeException('Impossible de conserver le document présenté.');
            } finally { if (is_file($temporary)) unlink($temporary); }
        }
        return $path;
    }

    /** Caller must hold the workflow and convention database locks until flush. */
    public function sign(TrainingWorkflow $workflow, string $name, string $role, string $digest, ?string $ip, ?string $userAgent): string
    {
        $convention = $workflow->getConvention();
        if (!$convention || $convention->isSigned()) throw new \DomainException('Cette convention a déjà été signée.');
        $html = $this->html($workflow);
        if (!hash_equals(hash('sha256', $html), $digest)) throw new \DomainException('La convention a changé. Consultez le document actualisé avant de signer.');
        $preview = $this->previewPath($workflow);
        $sourceHash = hash_file('sha256', $preview);
        $now = new \DateTimeImmutable();
        $convention->setDateSignatureEntreprise($now)->setSignatureDataUrlEntreprise($this->typedSignature($name));
        $evidence = [
            'name' => $name, 'role' => $role, 'signedAt' => $now->format(DATE_ATOM),
            'consent' => 'Je confirme être habilité à représenter cette entreprise, avoir lu la convention et accepter de la signer électroniquement en saisissant mon nom.',
            'ip' => mb_substr((string) $ip, 0, 64), 'userAgent' => mb_substr((string) $userAgent, 0, 255),
            'sourceSha256' => $sourceHash, 'sourceFile' => basename($preview), 'contentSha256' => $digest,
        ];
        $appendix = $this->twig->render('workflow/consent_pdf.html.twig', ['workflow' => $workflow, 'evidence' => $evidence]);
        $signedHtml = str_replace('</body>', $appendix.'</body>', $this->html($workflow));
        $bytes = $this->pdf->createPortraitBytes($signedHtml);
        $filename = 'convention-'.$workflow->getId().'-'.bin2hex(random_bytes(16)).'.pdf';
        $path = $this->directory().'/'.$filename;
        $this->write($path, $bytes);
        $evidence['signedSha256'] = hash('sha256', $bytes);
        $options = $workflow->getOptions();
        $options['signatureEvidence'] = $evidence;
        $workflow->setOptions($options);
        $convention->setPdfPath('private:'.$filename);
        return $path;
    }

    private function typedSignature(string $name): string
    {
        if (!extension_loaded('gd')) throw new \RuntimeException('La génération de signature est indisponible. Contactez l’organisme.');
        $image = imagecreatetruecolor(900, 120);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        $ink = imagecolorallocate($image, 30, 45, 60);
        $font = $this->projectDir.'/vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf';
        if (is_file($font) && function_exists('imagettftext')) {
            $size = 30;
            do { $box = imagettfbbox($size, 0, $font, $name); if (($box[2] - $box[0]) <= 850) break; --$size; } while ($size > 10);
            imagettftext($image, $size, 0, 20, 65, $ink, $font, $name);
        } else {
            imagestring($image, 5, 20, 45, (string) iconv('UTF-8', 'ASCII//TRANSLIT', $name), $ink);
        }
        imagestring($image, 3, 20, 93, 'Signature par saisie du nom - consentement archive', $ink);
        ob_start(); imagepng($image); $bytes = (string) ob_get_clean(); imagedestroy($image);
        return 'data:image/png;base64,'.base64_encode($bytes);
    }

    private function directory(): string
    {
        $directory = $this->projectDir.'/var/storage/conventions';
        (new Filesystem())->mkdir($directory, 0770);
        return $directory;
    }

    private function write(string $path, string $bytes): void
    {
        (new Filesystem())->dumpFile($path, $bytes);
        @chmod($path, 0660);
    }
}
