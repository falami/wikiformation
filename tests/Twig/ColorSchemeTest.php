<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class ColorSchemeTest extends TestCase
{
    public function testLegacyPalettesAdaptWhileNativeDarkPagesOptOut(): void
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2).'/templates'));
        $twig->addFunction(new \Twig\TwigFunction('asset', static fn (string $path): string => '/'.$path));
        $html = $twig->render('_color_scheme.html.twig');
        self::assertStringContainsString('name="color-scheme" content="light dark"', $html);
        self::assertStringContainsString('color-scheme: light dark', $html);
        self::assertStringNotContainsString('forced-color-adjust', $html);
        self::assertStringContainsString('color-scheme.js', $html);
        $adaptive = $twig->render('_color_scheme.html.twig', ['color_scheme' => 'light dark']);
        self::assertStringContainsString('name="color-scheme" content="light dark"', $adaptive);
        self::assertStringContainsString('color-scheme: light dark', $adaptive);
        self::assertStringNotContainsString('color-scheme.js', $adaptive);
    }

    public function testEveryStandaloneBrowserDocumentDeclaresItsPalette(): void
    {
        $root = dirname(__DIR__, 2).'/templates/';
        $checked = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.twig')) continue;
            $name = substr($file->getPathname(), strlen($root));
            // Documents for email clients / PDF renderers are not browser interfaces.
            if (preg_match('~(^emails/|^pdf/|/emails/|/email/|/pdf/|(^|/)(email|pdf|attestation|titre)\.html\.twig$|\.pdf\.html\.twig$)~', $name)) continue;
            $source = preg_replace('/\{#[\s\S]*?#\}/', '', file_get_contents($file->getPathname()));
            if (!preg_match('/<html\b/i', $source)) continue;
            $checked[] = $name;
            self::assertStringContainsString('_color_scheme.html.twig', $source, $name.' must explicitly declare its color palette.');
        }
        self::assertGreaterThanOrEqual(13, count($checked));
    }
}
