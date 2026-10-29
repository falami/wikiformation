<?php
declare(strict_types=1);

namespace App\Service\Convention;

use App\Entity\{ConventionContrat, Utilisateur};
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
use Smalot\PdfParser\Parser;

/** Places the image in the existing client box; contractual pages are never re-rendered. */
final class ConventionSignaturePdf
{
    public function append(string $original, ConventionContrat $convention, Utilisateur $actor, string $role, string $signature, \DateTimeImmutable $signedAt): string
    {
        ConventionPortalSignature::validateSignature($signature);
        try {
            $output = new Fpdi('P', 'pt');
            $count = $output->setSourceFile(StreamReader::createByString($original));
            $pages = (new Parser())->parseContent($original)->getPages();
            $targets = [];
            foreach ($pages as $index => $page) {
                foreach ($page->getDataTm() as $row) {
                    if (preg_match('/^Signature\s*:\s*$/u', trim($row[1] ?? ''))) {
                        $template = $output->importPage($index + 1);
                        $size = $output->getTemplateSize($template);
                        $x = (float)$row[0][4];
                        $y = $size['height'] - (float)$row[0][5];
                        if ($x > $size['width'] / 2 && $y + 78 < $size['height']) {
                            $targets[] = [$index + 1, $x, $y];
                        }
                    }
                }
            }
            if (count($targets) !== 1) throw new \DomainException('L’encart de signature client ne peut pas être identifié avec certitude.');
            [$targetPage, $x, $y] = $targets[0];
            $png = base64_decode(substr($signature, 22), true);
            $dimensions = getimagesizefromstring($png);
            $scale = min(205 / $dimensions[0], 56 / $dimensions[1]);
            $width = $dimensions[0] * $scale; $height = $dimensions[1] * $scale;
            $imageFile = tempnam(sys_get_temp_dir(), 'convention-signature-');
            if (!$imageFile) throw new \RuntimeException('Stockage temporaire indisponible.');
            try {
                file_put_contents($imageFile, $png);
                for ($page = 1; $page <= $count; ++$page) {
                    $template = $output->importPage($page);
                    $size = $output->getTemplateSize($template);
                    $output->AddPage($size['orientation'], [$size['width'], $size['height']]);
                    $output->useTemplate($template);
                    if ($page === $targetPage) {
                        // Erase only the placeholder text inside the box.
                        $output->SetFillColor(255, 255, 255);
                        $output->Rect($x - 2, $y - 12, 80, 17, 'F');
                        $center = $x + 27;
                        $output->Image($imageFile, $center - $width / 2, $y + 3 + (56 - $height) / 2, $width, $height, 'PNG');
                        // Signature timestamps remain in the audit data, not on the document.
                    }
                }
                return $output->Output('S');
            } finally { unlink($imageFile); }
        } catch (\Throwable $error) {
            throw new \DomainException('Impossible de placer la signature dans l’encart client. Contactez l’organisme de formation.', 0, $error);
        }
    }
}
