<?php
// src/Service/Depense/ReceiptParser.php
namespace App\Service\Depense;

final class ReceiptParser
{
  public function parse(array $ocr): array
  {
    $text = $this->normalize($ocr['text'] ?? '');
    $lines = array_values(array_filter(array_map([$this, 'normalize'], $ocr['lines'] ?? preg_split("/\R/u", $text) ?: [])));

    // 1) Montants candidats
    $amounts = $this->extractAmounts($lines);

    // 2) Détection TTC / TVA / HT via libellés
    $ttc = $this->pickByKeywords($amounts, ['total ttc', 'ttc', 'montant ttc', 'net a payer', 'a payer', 'total a payer', 'total']);
    $tva = $this->pickByKeywords($amounts, ['tva', 'taxe', 'vat']);
    $ht  = $this->pickByKeywords($amounts, ['total ht', 'ht', 'hors taxe', 'base ht']);

    // fallback : si pas de “TOTAL TTC”, prends le montant le + grand (souvent TTC)
    if (!$ttc && $amounts) {
      $ttc = max(array_column($amounts, 'cents'));
    }

    // 3) Taux TVA (20 / 10 / 5.5 / 2.1 / 0)
    $taux = $this->extractVatRate($lines);

    // 4) Si TVA/HT manquants, on calcule
    if ($ttc && (!$ht || !$tva)) {
      if ($tva && !$ht) {
        $ht = max(0, $ttc - $tva);
      } elseif ($ht && !$tva) {
        $tva = max(0, $ttc - $ht);
      } elseif ($taux !== null) {
        [$htCalc, $tvaCalc] = $this->calcFromTtc($ttc, $taux);
        $ht ??= $htCalc;
        $tva ??= $tvaCalc;
      }
    }

    // 5) Date (dd/mm/yyyy, dd-mm-yyyy, yyyy-mm-dd)
    $date = $this->extractDate($lines); // returns 'Y-m-d' or null

    // 6) Fournisseur / SIRET
    [$vendorName, $siret] = $this->extractVendor($lines);

    // 7) Score (heuristique)
    $score = 0;
    $score += $ttc ? 40 : 0;
    $score += ($tva !== null) ? 20 : 0;
    $score += ($ht !== null) ? 15 : 0;
    $score += ($taux !== null) ? 10 : 0;
    $score += ($date !== null) ? 10 : 0;
    $score += ($vendorName !== null) ? 5 : 0;

    return [
      'confidence' => min(100, $score),
      'date' => $date,                 // 'Y-m-d' ou null
      'fournisseurNom' => $vendorName, // ou null
      'fournisseurSiret' => $siret,    // ou null

      // 💡 proposition montants (cents)
      'montantTtcCents' => $ttc ?: 0,
      'montantTvaCents' => $tva ?? 0,
      'montantHtCents'  => $ht ?? 0,
      'tauxTva' => $taux ?? 20.0,

      // debug utile en prod
      'debug' => [
        'amountCandidates' => array_slice($amounts, 0, 20),
        'linesSample' => array_slice($lines, 0, 20),
      ],
    ];
  }

  private function normalize(string $s): string
  {
    $s = mb_strtolower($s);
    $s = str_replace(["\t", "\r"], ' ', $s);
    $s = preg_replace('/\s+/u', ' ', $s) ?: $s;
    return trim($s);
  }

  /** @return array<int,array{label:string,cents:int,line:string}> */
  private function extractAmounts(array $lines): array
  {
    $out = [];

    foreach ($lines as $line) {

      // normalise NBSP -> espace (PCRE2 safe)
      $line = str_replace("\xC2\xA0", ' ', $line);

      // capture montants type: 12,34 / 12.34 / 1 234,56 / 1.234,56 / 1,234.56
      // IMPORTANT: groupe capturant autour du nombre
      if (preg_match_all('/\b(\d{1,3}(?:[ .]\d{3})*(?:[,.]\d{2})|\d+(?:[,.]\d{2}))\b/u', $line, $m)) {
        foreach ($m[1] as $raw) {
          $cents = $this->toCents($raw);
          if ($cents <= 0) continue;
          $out[] = ['label' => $line, 'cents' => $cents, 'line' => $line];
        }
      }
    }

    // dédoublonne
    $uniq = [];
    foreach ($out as $a) {
      $uniq[$a['line'] . '|' . $a['cents']] = $a;
    }

    return array_values($uniq);
  }


  private function toCents(string $raw): int
  {
    $s = trim($raw);

    // enlève espaces insécables (NBSP), espaces, symboles €
    $s = str_replace(["\xC2\xA0", ' ', '€'], '', $s);


    // garde seulement chiffres + séparateurs possibles
    $s = preg_replace('/[^0-9\.,\-]/u', '', $s) ?? $s;
    if ($s === '' || $s === '-' || $s === ',' || $s === '.') return 0;

    // signe
    $negative = false;
    if (str_starts_with($s, '-')) {
      $negative = true;
      $s = substr($s, 1);
    }

    // si aucun séparateur → entier (euros)
    if (!str_contains($s, ',') && !str_contains($s, '.')) {
      if (!ctype_digit($s)) return 0;
      $cents = ((int)$s) * 100;
      return $negative ? -$cents : $cents;
    }

    $lastComma = strrpos($s, ',');
    $lastDot   = strrpos($s, '.');

    // On décide quel est le séparateur décimal = le dernier séparateur rencontré
    $decimalSep = null;
    if ($lastComma !== false && $lastDot !== false) {
      $decimalSep = ($lastComma > $lastDot) ? ',' : '.';
    } elseif ($lastComma !== false) {
      $decimalSep = ',';
    } elseif ($lastDot !== false) {
      $decimalSep = '.';
    }

    // Séparateur de milliers = l'autre (si présent)
    $thousandSep = ($decimalSep === ',') ? '.' : ',';

    // Cas ambigus : "7.462" peut être 7462 (milliers) OU 7.46 (décimal)
    // Heuristique : si le "dernier séparateur" est suivi de 3 chiffres → milliers (pas décimal)
    // Exemple: 7.462 => milliers ; 74.62 => décimal
    if ($decimalSep !== null) {
      $pos = strrpos($s, $decimalSep);
      $digitsAfter = $pos !== false ? strlen(substr($s, $pos + 1)) : 0;

      if ($digitsAfter === 3) {
        // on traite ce séparateur comme milliers, donc pas de décimal
        $s = str_replace([$decimalSep, $thousandSep], '', $s);
        if (!ctype_digit($s)) return 0;
        $cents = ((int)$s) * 100;
        return $negative ? -$cents : $cents;
      }
    }

    // On enlève les séparateurs de milliers
    $s = str_replace($thousandSep, '', $s);

    // On normalise le séparateur décimal en "."
    if ($decimalSep === ',') {
      $s = str_replace(',', '.', $s);
    }

    // Il ne doit rester qu’un seul "."
    if (substr_count($s, '.') > 1) {
      // fallback : on garde le dernier point comme décimal
      $parts = explode('.', $s);
      $dec = array_pop($parts);
      $int = implode('', $parts);
      $s = $int . '.' . $dec;
    }

    if (!is_numeric($s)) return 0;

    $val = (float)$s;
    $cents = (int) round($val * 100);

    return $negative ? -$cents : $cents;
  }


  private function pickByKeywords(array $amounts, array $keywords): ?int
  {
    foreach ($amounts as $a) {
      foreach ($keywords as $k) {
        if (str_contains($a['label'], $k)) {
          return $a['cents'];
        }
      }
    }
    return null;
  }

  private function extractVatRate(array $lines): ?float
  {
    $rates = [20.0, 10.0, 5.5, 2.1, 0.0];
    foreach ($lines as $l) {
      if (!str_contains($l, 'tva') && !str_contains($l, 'vat')) continue;
      foreach ($rates as $r) {
        $needle = (string)$r;
        $needle = str_replace('.0', '', $needle);
        if (preg_match('/\b' . preg_quote($needle, '/') . '\s*%/u', $l)) return (float)$r;
      }
    }
    return null;
  }

  private function extractDate(array $lines): ?string
  {
    foreach ($lines as $l) {
      if (preg_match('/\b(\d{2})[\/\-](\d{2})[\/\-](\d{4})\b/u', $l, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
      }
      if (preg_match('/\b(\d{4})[\/\-](\d{2})[\/\-](\d{2})\b/u', $l, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
      }
    }
    return null;
  }

  /** @return array{0:?string,1:?string} */
  private function extractVendor(array $lines): array
  {
    $siret = null;

    foreach ($lines as $l) {
      if (preg_match('/\b(\d{3}\s?\d{3}\s?\d{3}\s?\d{5})\b/u', $l, $m)) {
        $siret = preg_replace('/\s+/', '', $m[1]);
        break;
      }
    }

    // vendor name: prend les 1-3 premières lignes “non vides”, non génériques
    $candidates = array_slice($lines, 0, 6);
    $vendor = null;
    foreach ($candidates as $c) {
      if (mb_strlen($c) < 3) continue;
      if (preg_match('/(ticket|facture|recu|merci|tva|total)/u', $c)) continue;
      $vendor = mb_strtoupper(mb_substr($c, 0, 60));
      break;
    }

    return [$vendor, $siret];
  }

  /** @return array{0:int,1:int} */
  private function calcFromTtc(int $ttcCents, float $taux): array
  {
    $taux = max(0.0, $taux);
    if ($ttcCents <= 0) return [0, 0];
    if ($taux <= 0.0001) return [$ttcCents, 0];

    $ht = (int) round($ttcCents / (1 + ($taux / 100)));
    $tva = max(0, $ttcCents - $ht);
    return [$ht, $tva];
  }
}
