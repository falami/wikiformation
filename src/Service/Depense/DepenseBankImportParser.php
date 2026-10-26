<?php

declare(strict_types=1);

namespace App\Service\Depense;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

final class DepenseBankImportParser
{
    private const MAX_FILE_BYTES = 10 * 1024 * 1024;
    private const MAX_ROWS = 10000;
    private const MAX_HEADER_ROWS = 50;
    private const MAX_COLUMNS = 100;
    private const MAX_CENTS = 2147483647;

    /** @return array<int, array{date:\DateTimeImmutable, libelle:string, debitCents:int, raw:array<string,mixed>}> */
    public function parse(string $filepath, string $originalName): array
    {
        if (!is_file($filepath) || !is_readable($filepath)) {
            throw new \RuntimeException('Impossible de lire le fichier importé.');
        }
        if (filesize($filepath) > self::MAX_FILE_BYTES) {
            throw new \RuntimeException('Le fichier dépasse la taille maximale de 10 Mo.');
        }

        return match (strtolower(pathinfo($originalName, PATHINFO_EXTENSION))) {
            'csv' => $this->parseCsv($filepath),
            'xlsx', 'xls' => $this->parseSpreadsheet($filepath),
            default => throw new \RuntimeException('Format non supporté. Utilisez un fichier CSV, XLS ou XLSX.'),
        };
    }

    private function parseCsv(string $filepath): array
    {
        $contents = file_get_contents($filepath);
        if ($contents === false || $contents === '') {
            throw new \RuntimeException('Le fichier CSV est vide ou illisible.');
        }
        if (str_starts_with($contents, "\xFF\xFE") || str_starts_with($contents, "\xFE\xFF")) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'UTF-16');
        } elseif (!mb_check_encoding($contents, 'UTF-8')) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
        }
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $contents = str_replace(["\r\n", "\r"], "\n", $contents);
        if (str_contains($contents, "\0")) {
            throw new \RuntimeException('Ce fichier ne contient pas un CSV valide.');
        }

        // Bank exports often include account metadata before the actual column headings.
        $delimiter = null;
        foreach (array_slice(explode("\n", $contents), 0, self::MAX_HEADER_ROWS) as $line) {
            foreach ([';', ',', "\t"] as $candidate) {
                if ($this->hasRequiredColumns($this->normalizeHeader(str_getcsv($line, $candidate, '"', '')))) {
                    $delimiter = $candidate;
                    break 2;
                }
            }
        }
        if ($delimiter === null) {
            throw new \RuntimeException('Colonnes non reconnues : le fichier doit contenir une date, un libellé et un débit (ou un montant signé).');
        }

        $handle = fopen('php://temp', 'w+b');
        if ($handle === false) {
            throw new \RuntimeException('Impossible de préparer la lecture du CSV.');
        }
        try {
            fwrite($handle, $contents);
            rewind($handle);
            $lines = (static function () use ($handle, $delimiter): \Generator {
                while (($line = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                    yield $line;
                }
            })();

            return $this->parseRows($lines, false);
        } finally {
            fclose($handle);
        }
    }

    private function parseSpreadsheet(string $filepath): array
    {
        // Bound decompression before handing a workbook to PhpSpreadsheet.
        $zip = new \ZipArchive();
        if ($zip->open($filepath) === true) {
            try {
                $size = 0;
                for ($i = 0; $i < $zip->numFiles; ++$i) {
                    $size += ($zip->statIndex($i)['size'] ?? 0);
                    if ($zip->numFiles > 1000 || $size > 50 * 1024 * 1024) {
                        throw new \RuntimeException('Le classeur est trop volumineux. Exportez uniquement les opérations à importer.');
                    }
                }
            } finally {
                $zip->close();
            }
        }

        $calendar = Date::getExcelCalendar();
        $spreadsheet = null;
        try {
            $type = IOFactory::identify($filepath, [IOFactory::READER_XLSX, IOFactory::READER_XLS]);
            $reader = IOFactory::createReader($type);
            $reader->setReadDataOnly(true);
            $totalRows = 0;
            foreach ($reader->listWorksheetInfo($filepath) as $worksheet) {
                $totalRows += $worksheet['totalRows'];
                if ($totalRows > self::MAX_ROWS + self::MAX_HEADER_ROWS || $worksheet['totalColumns'] > self::MAX_COLUMNS) {
                    throw new \RuntimeException('Le classeur dépasse la limite de 10 000 opérations ou 100 colonnes.');
                }
            }
            $spreadsheet = $reader->load($filepath);
            Date::setExcelCalendar($spreadsheet->getExcelCalendar());
            $sheet = $spreadsheet->getActiveSheet();
            $lastRow = $sheet->getHighestDataRow();
            $lastColumn = $sheet->getHighestDataColumn();
            if ($lastRow > self::MAX_ROWS + self::MAX_HEADER_ROWS || Coordinate::columnIndexFromString($lastColumn) > self::MAX_COLUMNS) {
                throw new \RuntimeException('Le classeur dépasse la limite de 10 000 opérations ou 100 colonnes.');
            }
            $lines = (static function () use ($sheet, $lastRow, $lastColumn): \Generator {
                for ($row = 1; $row <= $lastRow; ++$row) {
                    // Never evaluate spreadsheet formulas or external references from an upload.
                    yield $sheet->rangeToArray("A{$row}:{$lastColumn}{$row}", null, false, false)[0] ?? [];
                }
            })();

            return $this->parseRows($lines, true);
        } catch (\PhpOffice\PhpSpreadsheet\Exception $e) {
            throw new \RuntimeException('Le classeur est illisible ou ne correspond pas à un fichier XLS/XLSX valide.', 0, $e);
        } finally {
            $spreadsheet?->disconnectWorksheets();
            Date::setExcelCalendar($calendar);
        }
    }

    private function parseRows(iterable $lines, bool $excel): array
    {
        $header = null;
        $out = [];
        $lineNumber = 0;
        $dataRows = 0;
        foreach ($lines as $line) {
            ++$lineNumber;
            if (count($line) > self::MAX_COLUMNS) {
                throw new \RuntimeException("Ligne {$lineNumber} : le fichier dépasse 100 colonnes.");
            }
            if ($header === null) {
                $candidate = $this->normalizeHeader($line);
                if ($this->hasRequiredColumns($candidate)) {
                    $header = $candidate;
                } elseif ($lineNumber >= self::MAX_HEADER_ROWS) {
                    break;
                }
                continue;
            }
            if (++$dataRows > self::MAX_ROWS) {
                throw new \RuntimeException('Le fichier dépasse la limite de 10 000 opérations.');
            }
            if (!array_filter($line, static fn ($value) => $value !== null && trim((string) $value) !== '')) {
                continue;
            }
            if (!$excel && count($line) !== count($header)) {
                throw new \RuntimeException("Ligne {$lineNumber} : le nombre de colonnes ne correspond pas à l’en-tête. Vérifiez le séparateur et les guillemets du CSV.");
            }
            $row = [];
            foreach ($header as $index => $key) {
                if ($key !== '') {
                    $row[$key] = $line[$index] ?? null;
                }
            }
            try {
                $debit = isset($row['debit']) ? abs($this->parseMoneyToCents($row['debit'])) : max(0, -$this->parseMoneyToCents($row['amount'] ?? null));
                if ($debit === 0) {
                    continue;
                }
                if (isset($row['credit']) && $this->parseMoneyToCents($row['credit']) !== 0) {
                    throw new \RuntimeException('un débit et un crédit sont renseignés simultanément.');
                }
                $currency = mb_strtoupper(trim((string) ($row['currency'] ?? 'EUR')));
                if (!in_array($currency, ['', 'EUR', '€'], true)) {
                    throw new \RuntimeException('seules les opérations en EUR sont prises en charge.');
                }
                $date = $this->parseDate($row['date'] ?? $row['booking_date'] ?? $row['value_date'] ?? null, $excel);
                $label = trim((string) ($row['libelle'] ?? ''));
                if ($label === '') {
                    throw new \RuntimeException('le libellé de cette dépense est vide.');
                }
                $out[] = ['date' => $date, 'libelle' => $label, 'debitCents' => $debit, 'raw' => $row];
            } catch (\RuntimeException $e) {
                throw new \RuntimeException("Ligne {$lineNumber} : {$e->getMessage()}", 0, $e);
            }
        }
        if ($header === null) {
            throw new \RuntimeException('Colonnes non reconnues : le fichier doit contenir une date, un libellé et un débit (ou un montant signé).');
        }

        return $out;
    }

    private function normalizeHeader(array $cols): array
    {
        $seen = [];
        return array_map(static function ($heading) use (&$seen): string {
            $heading = ExpenseImportRules::normalize((string) $heading);
            $key = match (true) {
                str_contains($heading, 'date') && str_contains($heading, 'valeur') => 'value_date',
                str_contains($heading, 'date') && (str_contains($heading, 'compta') || str_contains($heading, 'booking')) => 'booking_date',
                str_contains($heading, 'date') => 'date',
                str_contains($heading, 'libelle'), in_array($heading, ['description', 'label', 'intitule', 'operation', 'transaction'], true) => 'libelle',
                str_contains($heading, 'debit') => 'debit',
                str_contains($heading, 'credit') => 'credit',
                str_starts_with($heading, 'montant'), str_starts_with($heading, 'amount') => 'amount',
                in_array($heading, ['devise', 'currency'], true) => 'currency',
                default => '',
            };
            // Prefer the first matching column; a later "libellé complémentaire" must not erase it.
            if (isset($seen[$key])) {
                return '';
            }
            $seen[$key] = true;
            return $key;
        }, $cols);
    }

    private function hasRequiredColumns(array $header): bool
    {
        return (bool) array_intersect(['date', 'booking_date', 'value_date'], $header)
            && in_array('libelle', $header, true)
            && (bool) array_intersect(['debit', 'amount'], $header);
    }

    private function parseDate(mixed $value, bool $excel): \DateTimeImmutable
    {
        if ($excel && (is_int($value) || is_float($value)) && $value >= 1 && $value < 2958466) {
            return \DateTimeImmutable::createFromMutable(Date::excelToDateTimeObject((float) $value))->setTime(0, 0);
        }
        $value = trim((string) $value);
        foreach (['d/m/Y' => '/^\d{1,2}\/\d{1,2}\/\d{4}$/D', 'd/m/y' => '/^\d{1,2}\/\d{1,2}\/\d{2}$/D', 'Y-m-d' => '/^\d{4}-\d{1,2}-\d{1,2}$/D', 'd-m-Y' => '/^\d{1,2}-\d{1,2}-\d{4}$/D', 'd.m.Y' => '/^\d{1,2}\.\d{1,2}\.\d{4}$/D', 'Y/m/d' => '/^\d{4}\/\d{1,2}\/\d{1,2}$/D'] as $format => $pattern) {
            if (!preg_match($pattern, $value)) {
                continue;
            }
            $date = \DateTimeImmutable::createFromFormat('!'.$format, $value);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date;
            }
        }
        throw new \RuntimeException('date invalide. Utilisez une date réelle au format JJ/MM/AAAA ou AAAA-MM-JJ.');
    }

    private function parseMoneyToCents(mixed $value): int
    {
        if ($value === null || trim((string) $value) === '' || trim((string) $value) === '-') {
            return 0;
        }
        if (is_int($value) || is_float($value)) {
            if (!is_finite((float) $value) || abs($value * 100) > self::MAX_CENTS || abs($value * 100 - round($value * 100)) > 0.00001) {
                throw new \RuntimeException('montant invalide ou trop élevé (deux décimales maximum).');
            }
            return (int) round($value * 100);
        }
        $value = preg_replace('/[\s\x{00A0}\x{202F}]/u', '', trim((string) $value)) ?? '';
        $value = preg_replace('/^(?:EUR|€)|(?:EUR|€)$/iu', '', $value) ?? '';
        if (preg_match('/^\((.+)\)$/D', $value, $matches)) {
            $value = '-'.$matches[1];
        }
        if (!preg_match('/^([+-]?)(\d[\d.,]*)$/D', $value, $parts)) {
            throw new \RuntimeException('montant invalide. Vérifiez les séparateurs et la devise.');
        }
        $number = $parts[2];
        $sign = $parts[1] === '-' ? -1 : 1;
        $lastComma = strrpos($number, ',');
        $lastDot = strrpos($number, '.');
        $decimal = null;
        if ($lastComma !== false && $lastDot !== false) {
            $decimal = $lastComma > $lastDot ? ',' : '.';
        } elseif ($lastComma !== false || $lastDot !== false) {
            $separator = $lastComma !== false ? ',' : '.';
            if (substr_count($number, $separator) === 1 && strlen(substr($number, strrpos($number, $separator) + 1)) <= 2) {
                $decimal = $separator;
            }
        }
        $fraction = '00';
        $integer = $number;
        if ($decimal !== null) {
            [$integer, $fraction] = explode($decimal, $number, 2);
            if (!preg_match('/^\d{1,2}$/D', $fraction)) {
                throw new \RuntimeException('montant invalide : deux décimales maximum.');
            }
            $fraction = str_pad($fraction, 2, '0');
        }
        if (!preg_match('/^\d+$/D', $integer) && !preg_match('/^\d{1,3}([.,])\d{3}(?:\1\d{3})*$/D', $integer)) {
            throw new \RuntimeException('séparateurs du montant invalides.');
        }
        $integer = str_replace([',', '.'], '', $integer);
        $cents = ((float) $integer * 100) + (int) $fraction;
        if ($cents > self::MAX_CENTS) {
            throw new \RuntimeException('montant trop élevé.');
        }
        return $sign * (int) $cents;
    }
}
