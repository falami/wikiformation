<?php

namespace App\Service\Depense;

/** Conservative proposals: an unreadable amount is unknown, never a made-up zero or VAT rate. */
final class ReceiptParser
{
    public function parse(array $ocr): array
    {
        $rawLines = $ocr['lines'] ?? [];
        if (!is_array($rawLines) || $rawLines === []) {
            $rawLines = preg_split('/\R/u', is_string($ocr['text'] ?? null) ? $ocr['text'] : '') ?: [];
        }
        $lines = [];
        foreach ($rawLines as $line) {
            if (is_string($line) && ($line = $this->normalize($line)) !== '') {
                $lines[] = $line;
            }
        }

        $warnings = [];
        $amounts = $this->extractAmounts($lines);
        $totalPattern = '/\b(?:total\s*ttc|montant\s*ttc|ttc|net a payer|total a payer|montant a payer|a payer|grand total)\b/';
        $ttc = $this->pick($amounts, $totalPattern);
        if (!array_filter($amounts, static fn (array $row): bool => (bool) preg_match($totalPattern, $row['label']))) {
            $ttc = $this->pick($amounts, '/\btotal\b/', '/\b(?:ht|tva|vat|taxe|taxes|hors|sous|subtotal|articles?|remise)\b|sous-total/');
        }
        // A cash tendered amount, a date or an article price must never become the total.
        if ($ttc === null) {
            $warnings[] = 'Le total TTC n’a pas été identifié avec certitude. Renseignez-le après vérification du justificatif.';
        } elseif ($ttc <= 0) {
            $ttc = null;
            $warnings[] = 'Ce document semble être un avoir ou un total nul. Vérifiez le montant et le type d’opération manuellement.';
        }

        $ht = $this->pick($amounts, '/\b(?:total\s*ht|montant\s*ht|hors taxes?|base\s*ht|ht)\b/', '/\b(?:ttc|tva|vat)\b/');
        $tva = $this->pick($amounts, '/\b(?:total\s*(?:tva|vat|taxes?)|montant\s*(?:tva|vat|taxes?))\b/');
        if ($tva === null) {
            $vatRows = [];
            foreach ($amounts as $row) {
                if (preg_match('/\b(?:tva|vat|taxes?)\b/', $row['label']) && !preg_match('/\b(?:ttc|ht|hors|base)\b/', $row['label'])) {
                    $vatRows[$row['index']] = $row['cents']; // last amount, excluding percentages
                }
            }
            if ($vatRows !== []) {
                $tva = array_sum($vatRows);
            }
        }

        $rates = $this->extractVatRates($lines);
        $multipleRates = count($rates) > 1;
        $rate = count($rates) === 1 ? $rates[0] : null;
        if ($multipleRates) {
            $warnings[] = 'Plusieurs taux de TVA figurent sur ce justificatif. Vérifiez la ventilation de TVA avant d’enregistrer.';
        }

        $inconsistent = ($ht !== null && $ht < 0) || ($tva !== null && $tva < 0);
        if ($ttc !== null) {
            $inconsistent = $inconsistent || ($ht !== null && $ht > $ttc) || ($tva !== null && $tva > $ttc);
            if ($ht !== null && $tva !== null && abs($ht + $tva - $ttc) > 1) {
                $inconsistent = true;
            }
            if (!$inconsistent) {
                if ($ht !== null && $tva === null) {
                    $tva = $ttc - $ht;
                } elseif ($tva !== null && $ht === null) {
                    $ht = $ttc - $tva;
                } elseif ($ht === null && $tva === null && $rate !== null) {
                    $ht = (int) round($ttc / (1 + $rate / 100));
                    $tva = $ttc - $ht;
                }
            }
        }
        if ($inconsistent) {
            $ht = $tva = $rate = null;
            $warnings[] = 'Les montants HT, TVA et TTC lus sont incohérents. Aucun montant de TVA n’a été prérempli.';
        } elseif (!$multipleRates && $ttc !== null && $ht !== null && $tva !== null) {
            if ($rate !== null && abs($tva - (int) round($ht * $rate / 100)) > 1) {
                $rate = null;
                $warnings[] = 'Le taux de TVA lu ne correspond pas aux montants. Vérifiez-le sur le justificatif.';
            } elseif ($rate === null && $ht > 0) {
                $matchingRates = [];
                foreach ([20.0, 10.0, 5.5, 2.1, 0.0] as $candidate) {
                    if (abs($tva - (int) round($ht * $candidate / 100)) <= 1) {
                        $matchingRates[] = $candidate;
                    }
                }
                $rate = count($matchingRates) === 1 ? $matchingRates[0] : null;
            }
        }
        if ($rate === null && !$multipleRates && !$inconsistent) {
            $warnings[] = 'Le taux de TVA n’a pas été identifié. Il doit être confirmé sur le justificatif.';
        }

        $date = $this->extractDate($lines);
        [$vendor, $siret] = $this->extractVendor($lines);
        $score = ($ttc !== null ? 40 : 0) + ($tva !== null ? 15 : 0) + ($ht !== null ? 15 : 0)
            + ($rate !== null ? 10 : 0) + ($date !== null ? 10 : 0) + ($vendor !== null ? 5 : 0);

        return [
            'confidence' => $warnings ? min(70, $score) : $score,
            'date' => $date,
            'fournisseurNom' => $vendor,
            'fournisseurSiret' => $siret,
            'montantTtcCents' => $ttc,
            'montantTvaCents' => $tva,
            'montantHtCents' => $ht,
            'tauxTva' => $rate,
            'multipleVatRates' => $multipleRates,
            'warnings' => $warnings,
        ];
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower($value, 'UTF-8');
        $value = strtr($value, ['à' => 'a', 'â' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u']);
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    /** @return list<array{label:string,cents:int,index:int}> */
    private function extractAmounts(array $lines): array
    {
        $amounts = [];
        foreach ($lines as $index => $line) {
            $values = $this->amountsOnLine($line);
            // Vision sometimes puts the value on the line immediately below its label.
            if ($values === [] && preg_match('/^(?:total(?: ttc| ht| tva)?|ttc|ht|tva|net a payer|montant ttc)\s*:?[ ]*$/', $line)) {
                $next = $lines[$index + 1] ?? '';
                if (preg_match('/^[\d\s.,€$£+\-]+(?:eur)?$/u', $next)) {
                    $values = $this->amountsOnLine($next);
                }
            }
            foreach ($values as $value) {
                $amounts[] = ['label' => $line, 'cents' => $value, 'index' => $index];
            }
        }
        return $amounts;
    }

    /** @return list<int> */
    private function amountsOnLine(string $line): array
    {
        // Dates and percentages can look like monetary decimals; remove them first.
        $line = preg_replace('/\b(?:\d{1,2}[.\/-]\d{1,2}[.\/-]\d{2,4}|\d{4}[.\/-]\d{1,2}[.\/-]\d{1,2})\b|\d+(?:[,.]\d+)?\s*%/u', ' ', $line) ?? '';
        $pattern = '/(?<![\d.,])([+\-]?(?:\d{1,3}(?:[ .,]\d{3})+|\d+)[,.]\d{2})(?![\d.,])/u';
        preg_match_all($pattern, $line, $matches);
        $values = [];
        foreach ($matches[1] as $raw) {
            $digits = preg_replace('/[^\d]/', '', $raw);
            if (strlen($digits) > 10) { // DB money columns are signed 32-bit cents.
                continue;
            }
            $cents = (int) $digits;
            if ($cents > 2147483647) {
                continue;
            }
            $values[] = str_starts_with($raw, '-') ? -$cents : $cents;
        }
        // Whole euros are accepted only with a currency or a standalone total label.
        if ($values === [] && preg_match('/(?:\b(?:total(?: ttc| ht| tva)?|ttc|ht|tva|net a payer)\s*:?\s*)([+\-]?\d{1,7})\s*(?:€|eur)?\s*$|(?<![\d.,])([+\-]?\d{1,7})\s*(?:€|eur)(?!\w)/u', $line, $m)) {
            $values[] = (int) (($m[1] ?? '') !== '' ? $m[1] : $m[2]) * 100;
        }
        return $values;
    }

    private function pick(array $amounts, string $include, ?string $exclude = null): ?int
    {
        $picked = [];
        foreach ($amounts as $row) {
            if (preg_match($include, $row['label']) && ($exclude === null || !preg_match($exclude, $row['label']))) {
                $picked[$row['cents']] = $row['cents'];
            }
        }
        // Multiple receipts or conflicting totals cannot safely fill a single expense.
        return count($picked) === 1 ? array_values($picked)[0] : null;
    }

    /** @return list<float> */
    private function extractVatRates(array $lines): array
    {
        $rates = [];
        foreach ($lines as $index => $line) {
            if (!preg_match('/\b(?:tva|vat)\b/', $line) && !preg_match('/\b(?:tva|vat)\b/', $lines[$index - 1] ?? '')) {
                continue;
            }
            preg_match_all('/(?<![\d.,])(\d{1,2}(?:[,.]\d{1,2})?)\s*%/', $line, $matches);
            foreach ($matches[1] as $raw) {
                $rate = (float) str_replace(',', '.', $raw);
                $rates[(string) $rate] = $rate;
            }
        }
        return array_values($rates);
    }

    private function extractDate(array $lines): ?string
    {
        // Prefer an explicit issue/purchase date over a payment due date.
        usort($lines, static fn (string $a, string $b): int => (int) !preg_match('/date|facture|achat|ticket/', $a) <=> (int) !preg_match('/date|facture|achat|ticket/', $b));
        foreach ($lines as $line) {
            if (preg_match('/echeance|expiration|validite|livraison/', $line)) {
                continue;
            }
            if (preg_match('/\b(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})\b/', $line, $m)) {
                [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            } elseif (preg_match('/\b(\d{4})[.\/-](\d{1,2})[.\/-](\d{1,2})\b/', $line, $m)) {
                [$day, $month, $year] = [(int) $m[3], (int) $m[2], (int) $m[1]];
            } else {
                continue;
            }
            if (checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }
        return null;
    }

    /** @return array{?string,?string} */
    private function extractVendor(array $lines): array
    {
        $siret = null;
        foreach ($lines as $line) {
            if (preg_match('/\bsiret\s*:?\s*(\d{3}\s?\d{3}\s?\d{3}\s?\d{5})\b/', $line, $m)) {
                $siret = preg_replace('/\s+/', '', $m[1]);
                break;
            }
        }
        $vendor = null;
        foreach (array_slice($lines, 0, 6) as $line) {
            if (mb_strlen($line) < 3 || !preg_match('/[a-z]{3}/', $line) || preg_match('/ticket|facture|recu|merci|tva|total|siret|date|\bht\b/', $line)) {
                continue;
            }
            $vendor = mb_strtoupper(mb_substr($line, 0, 100));
            break;
        }
        return [$vendor, $siret];
    }
}
