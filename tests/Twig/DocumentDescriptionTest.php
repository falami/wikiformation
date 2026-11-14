<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Twig\DocumentDescriptionExtension;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class DocumentDescriptionTest extends TestCase
{
    public function testNormalizesPastedListMarkersWithoutChangingTextOrSource(): void
    {
        $source = "Formation\r\n- - Vérification administrative\r• Analyse\n– Contrôle\n- Sous-traitance\n-5 % sur le forfait\n1. Premier module\n\n-\n";
        $parsed = DocumentDescriptionExtension::parse($source);
        self::assertSame('Formation', $parsed['main']);
        self::assertSame([
            'Vérification administrative', 'Analyse', 'Contrôle', 'Sous-traitance',
            '-5 % sur le forfait', '1. Premier module',
        ], $parsed['details']);
        self::assertStringContainsString('- - Vérification', $source);
    }

    public function testSupportsOrdinaryNewlinesAndEmptyDescriptions(): void
    {
        self::assertSame(['main' => '', 'details' => []], DocumentDescriptionExtension::parse(null));
        self::assertSame(['main' => 'Formation', 'details' => ['Premier module', 'Second module']],
            DocumentDescriptionExtension::parse("\n Formation \n Premier module\n\nSecond module\n"));
    }

    public function testFilterKeepsHtmlEscaped(): void
    {
        $twig = new Environment(new ArrayLoader([
            'description' => '{% set d = text|document_description %}{{ d.main }}{% for line in d.details %}|{{ line }}{% endfor %}',
        ]), ['autoescape' => 'html']);
        $twig->addExtension(new DocumentDescriptionExtension());
        self::assertSame('&lt;script&gt;|&lt;img src=x&gt;', $twig->render('description', ['text' => "<script>\n- <img src=x>"]));
    }

    public function testLongDescriptionsBreakAtWordsWithoutLosingText(): void
    {
        $text = str_repeat("Vérification des éléments administratifs. ", 100);
        $chunks = DocumentDescriptionExtension::chunks($text);
        self::assertGreaterThan(1, count($chunks));
        self::assertSame($text, implode('', $chunks));
        foreach ($chunks as $index => $chunk) {
            self::assertLessThanOrEqual(500, mb_strlen($chunk));
            if ($index < count($chunks) - 1) self::assertStringEndsWith(' ', $chunk);
        }
    }

    public function testUnbrokenUnicodeTextAndCombiningAccentsArePreserved(): void
    {
        $text = str_repeat("éÀe\u{0301}", 200);
        $chunks = DocumentDescriptionExtension::chunks($text, 180);
        self::assertSame($text, implode('', $chunks));
        self::assertCount(4, $chunks);
        foreach ($chunks as $chunk) {
            self::assertSame(1, preg_match('//u', $chunk));
            self::assertDoesNotMatchRegularExpression('/^\p{M}/u', $chunk);
        }
        self::assertSame([], DocumentDescriptionExtension::chunks(null));
        self::assertSame(['abc'], DocumentDescriptionExtension::chunks('abc'));
    }
}
