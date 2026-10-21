<?php
declare(strict_types=1);
namespace App\Service\Pdf;

use Dompdf\Dompdf;

/** Adds a reserved bottom strip without replacing existing legal or banking footers. */
final class DocumentFooter
{
    public static function prepare(string $html): string
    {
        if (!str_contains($html, 'name="wf-document-footer"')) return $html;
        return preg_replace_callback('/@page\s*(?::[\w-]+)?\s*\{([^}]+)\}/i', static function (array $match): string {
            $bottom = 36.0;
            preg_match_all('/(?:^|;)\s*(margin(?:-bottom)?)\s*:\s*([^;]+)/i', $match[1], $rules, PREG_SET_ORDER);
            foreach ($rules as $rule) {
                $parts = preg_split('/\s+/', trim($rule[2]));
                $value = strtolower($rule[1]) === 'margin-bottom' ? $parts[0] : ($parts[2] ?? $parts[0]);
                if (preg_match('/^([\d.]+)(mm|cm|pt|px|in)?$/', $value, $number)) {
                    $bottom = (float) $number[1] * match ($number[2] ?? 'pt') {
                        'mm' => 72/25.4, 'cm' => 72/2.54, 'px' => .75, 'in' => 72, default => 1,
                    };
                }
            }
            return rtrim(substr($match[0], 0, -1)).'; margin-bottom: '.($bottom + 32).'pt; }';
        }, $html);
    }

    public static function draw(Dompdf $pdf, string $html): void
    {
        if (!preg_match('/<meta name="wf-document-footer" content="([^"]*)"\s*\/?\s*>/', $html, $match)) return;
        $data = json_decode(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
        if (!is_array($data)) return;
        $font = $pdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
        $lines = [
            implode(' · ', array_filter([$data['name'] ?? '', $data['reference'] ?? ''])),
            implode(' · ', array_filter([$data['email'] ?? '', $data['website'] ?? '', $data['phone'] ?? ''])),
        ];
        $canvas = $pdf->getCanvas();
        $width = $canvas->get_width();
        $height = $canvas->get_height();
        $canvas->page_script(static function ($page, $pages, $canvas, $metrics) use ($lines, $font, $width, $height): void {
            $canvas->line(28, $height-31, $width-28, $height-31, [.83, .87, .90], .5);
            foreach ($lines as $i => $line) {
                $line = preg_replace('/\s+/u', ' ', $line);
                $size = 7.0;
                while ($size > 5 && $metrics->getTextWidth($line, $font, $size) > $width-110) $size -= .25;
                $canvas->text(28, $height-27+$i*10, $line, $font, $size, [.30, .37, .43]);
            }
            $label = $page.' / '.$pages;
            $canvas->text($width-28-$metrics->getTextWidth($label, $font, 7), $height-17, $label, $font, 7, [.30, .37, .43]);
        });
    }
}
