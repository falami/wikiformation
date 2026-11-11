<?php

declare(strict_types=1);
namespace App\Tests\Service\Email;

use App\Service\Email\ArchivedEmailPreview;
use PHPUnit\Framework\TestCase;

final class ArchivedEmailPreviewTest extends TestCase
{
    public function testOnlyLocalOrganisationLogoIsEmbedded(): void
    {
        $root = sys_get_temp_dir().'/email-preview-'.bin2hex(random_bytes(6));
        $dir = $root.'/public/uploads/photos/entite/logo';
        mkdir($dir, 0700, true);
        file_put_contents($dir.'/logo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
        try {
            $preview = new ArchivedEmailPreview($root);
            $html = '<img class="logo" src="https://example.test/uploads/photos/entite/logo/logo.png"><img src="https://external.test/tracker.png">';
            $result = $preview->render($html, 'logo.png');
            self::assertStringContainsString('src="data:image/png;base64,', $result);
            self::assertStringContainsString('src="https://external.test/tracker.png"', $result);
            self::assertSame($html, $preview->render($html, '../logo.png'));
            self::assertSame($html, $preview->render($html, 'missing.png'));
        } finally {
            unlink($dir.'/logo.png');
            rmdir($dir); rmdir(dirname($dir)); rmdir(dirname($dir, 2)); rmdir(dirname($dir, 3)); rmdir(dirname($dir, 4)); rmdir($root);
        }
    }
}
