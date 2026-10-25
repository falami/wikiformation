<?php
declare(strict_types=1);
namespace App\Tests\Twig;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class ExternalMapPrivacyTest extends TestCase
{
    public function testMapPreviewMakesNoExternalRequestBeforeAnExplicitAction(): void
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2).'/templates'), ['strict_variables' => true]);
        foreach ([[], ['map_src' => 'https://www.google.com/maps?q=Paris&output=embed', 'map_url' => 'https://www.google.com/maps/search/?api=1&query=Paris']] as $context) {
            $html = $twig->render('partials/_google_map.html.twig', $context);
            $dom = new \DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
            $xpath = new \DOMXPath($dom);
            self::assertSame(0, $xpath->query('//iframe|//img[@src]|//script[@src]')->length);
            self::assertSame(1, $xpath->query('//button[@data-load-google-map and @type="button"]')->length);
            self::assertStringContainsString('Afficher la carte Google Maps', $html);
            self::assertStringContainsString('service externe Google Maps', $html);
            self::assertSame(1, $xpath->query('//a[@data-map-link and @rel="noopener noreferrer"]')->length);
        }
    }
}
