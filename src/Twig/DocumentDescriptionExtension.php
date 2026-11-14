<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class DocumentDescriptionExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('document_description', [self::class, 'parse']),
            new TwigFilter('document_text_chunks', [self::class, 'chunks']),
        ];
    }

    /**
     * Bound PDF table-row height without losing any character. Prefer a word
     * boundary; split an oversized unbroken token only when necessary.
     *
     * @return list<string>
     */
    public static function chunks(?string $text, int $limit = 500): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('The chunk limit must be positive.');
        }
        if ($text === null || $text === '') {
            return [];
        }
        preg_match_all('/\X/u', $text, $matches);
        $characters = $matches[0];
        $length = count($characters);
        $chunks = [];
        for ($offset = 0; $offset < $length;) {
            $end = min($offset + $limit, $length);
            if ($end < $length) {
                for ($i = $end - 1; $i >= $offset; --$i) {
                    if (preg_match('/^\s$/u', $characters[$i])) {
                        $end = $i + 1;
                        break;
                    }
                }
            }
            $chunks[] = implode('', array_slice($characters, $offset, $end - $offset));
            $offset = $end;
        }

        return $chunks;
    }

    /**
     * Display-only formatting: the saved description stays untouched.
     *
     * @return array{main: string, details: list<string>}
     */
    public static function parse(?string $description): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $description ?? '') ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), static fn(string $line): bool => $line !== ''));
        $main = array_shift($lines) ?? '';
        $details = [];
        foreach ($lines as $line) {
            // Strip existing list markers, including accidental "- -", but
            // preserve hyphenated words, numbered lists and negative amounts.
            $line = trim(preg_replace('/^(?:(?:[-–—*][\t ]+|[•●▪◦][\t ]*))+/u', '', $line) ?? $line);
            if ($line !== '' && !preg_match('/^[-–—*•●▪◦]+$/u', $line)) {
                $details[] = $line;
            }
        }

        return ['main' => $main, 'details' => $details];
    }
}
