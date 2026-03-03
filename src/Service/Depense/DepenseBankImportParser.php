<?php

namespace App\Service\Depense;

use PhpOffice\PhpSpreadsheet\IOFactory;

final class DepenseBankImportParser
{
  /**
   * @return array<int, array{
   *   date:\DateTimeImmutable|null,
   *   libelle:string,
   *   debitCents:int,
   *   raw:array<string,mixed>
   * }>
   */
  public function parse(string $filepath, string $originalName): array
  {
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if ($ext === 'csv') {
      return $this->parseCsv($filepath);
    }

    if ($ext === 'xlsx' || $ext === 'xls') {
      return $this->parseXlsx($filepath);
    }

    throw new \RuntimeException('Format non supporté. Utilise .csv ou .xlsx');
  }

  /** @return array<int,array{date:\DateTimeImmutable|null,libelle:string,debitCents:int,raw:array<string,mixed>}> */
  private function parseCsv(string $filepath): array
  {
    $rows = [];
    $handle = fopen($filepath, 'rb');
    if (!$handle) throw new \RuntimeException('Impossible de lire le CSV.');

    // tentative auto delimiter ; sinon ';'
    $firstLine = fgets($handle);
    if ($firstLine === false) return [];
    $delimiter = (substr_count($firstLine, ';') >= substr_count($firstLine, ',')) ? ';' : ',';
    rewind($handle);

    $header = null;
    while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
      $data = array_map(fn($v) => is_string($v) ? trim($v) : $v, $data);

      if ($header === null) {
        $header = $this->normalizeHeader($data);
        continue;
      }

      $rowAssoc = [];
      foreach ($header as $i => $key) {
        if ($key === '') continue;
        $rowAssoc[$key] = $data[$i] ?? null;
      }

      $rows[] = $this->mapRow($rowAssoc);
    }

    fclose($handle);

    return array_values(array_filter($rows, fn($r) => $r['debitCents'] > 0));
  }

  /** @return array<int,array{date:\DateTimeImmutable|null,libelle:string,debitCents:int,raw:array<string,mixed>}> */
  private function parseXlsx(string $filepath): array
  {
    $sheet = IOFactory::load($filepath)->getActiveSheet();
    $highestRow = $sheet->getHighestDataRow();
    $highestCol = $sheet->getHighestDataColumn();

    $headerRow = $sheet->rangeToArray("A1:{$highestCol}1", null, true, false)[0] ?? [];
    $header = $this->normalizeHeader($headerRow);

    $out = [];
    for ($r = 2; $r <= $highestRow; $r++) {
      $line = $sheet->rangeToArray("A{$r}:{$highestCol}{$r}", null, true, false)[0] ?? [];
      $rowAssoc = [];
      foreach ($header as $i => $key) {
        if ($key === '') continue;
        $rowAssoc[$key] = $line[$i] ?? null;
      }
      $out[] = $this->mapRow($rowAssoc);
    }

    return array_values(array_filter($out, fn($r) => $r['debitCents'] > 0));
  }

  /** @param array<int,string> $cols */
  private function normalizeHeader(array $cols): array
  {
    return array_map(function ($h) {
      $h = mb_strtolower(trim((string)$h));
      $h = str_replace(['é', 'è', 'ê', 'à', 'ù', 'ç', 'ô', 'î'], ['e', 'e', 'e', 'a', 'u', 'c', 'o', 'i'], $h);

      // on accepte ton modèle et CA
      if (str_contains($h, 'date')) return 'date';
      if (str_contains($h, 'libelle')) return 'libelle';
      if (str_contains($h, 'debit')) return 'debit';
      if (str_contains($h, 'credit')) return 'credit';

      return '';
    }, $cols);
  }

  /** @param array<string,mixed> $row */
  private function mapRow(array $row): array
  {
    $date = $this->parseDate($row['date'] ?? null);
    $libelle = trim((string)($row['libelle'] ?? ''));

    // banque => valeurs positives
    $debit = $this->parseMoneyToCents($row['debit'] ?? 0);

    // on ignore crédit ici (tu veux que les débits)
    return [
      'date' => $date,
      'libelle' => $libelle !== '' ? $libelle : '—',
      'debitCents' => max(0, $debit),
      'raw' => $row,
    ];
  }

  private function parseDate(mixed $v): ?\DateTimeImmutable
  {
    if ($v === null) return null;

    // si c'est déjà une date Excel (num)
    if (is_numeric($v)) {
      // PhpSpreadsheet renvoie parfois un float, on tente conversion via Date::excelToDateTimeObject
      try {
        $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$v);
        return \DateTimeImmutable::createFromMutable($dt)->setTime(0, 0);
      } catch (\Throwable) {
      }
    }

    $s = trim((string)$v);
    if ($s === '') return null;

    // formats fréquents: dd/mm/yyyy, yyyy-mm-dd
    foreach (['d/m/Y', 'd/m/y', 'Y-m-d'] as $fmt) {
      $dt = \DateTimeImmutable::createFromFormat($fmt, $s);
      if ($dt instanceof \DateTimeImmutable) return $dt->setTime(0, 0);
    }

    // fallback
    try {
      return (new \DateTimeImmutable($s))->setTime(0, 0);
    } catch (\Throwable) {
      return null;
    }
  }

  private function parseMoneyToCents(mixed $v): int
  {
    if ($v === null) return 0;
    if (is_int($v)) return $v * 100;
    if (is_float($v)) return (int) round($v * 100);

    $s = trim((string)$v);
    if ($s === '') return 0;

    // "1 234,56" -> 1234.56
    $s = str_replace(["\u{202F}", ' '], '', $s);
    $s = str_replace(',', '.', $s);
    $s = preg_replace('/[^\d\.\-]/', '', $s) ?? '0';

    $n = (float)$s;
    return (int) round($n * 100);
  }
}
