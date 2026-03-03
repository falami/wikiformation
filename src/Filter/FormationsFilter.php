<?php
// src/Filter/FormationsFilter.php
declare(strict_types=1);

namespace App\Filter;

use App\Enum\EnginType;
use DateTimeImmutable;

/**
 * Filtre des formations/sessions pour la page publique.
 * - destinationId : id d'un Site (destination)
 * - from/to       : plage de dates
 * - enginTypes   : EnginType[] (via leurs backed values côté query-string)
 */
final class FormationsFilter
{
    public ?int $destinationId = null;
    public ?DateTimeImmutable $from = null;
    public ?DateTimeImmutable $to = null;


    /** --------- TYPE D'ENGIN --------- */
    /** @var list<string> */
    private array $enginTypeValues = [];
    /** @var list<EnginType> */
    private array $enginTypeEnums = [];

    /**
     * Construit le filtre depuis la query-string.
     * Priorité : champs cachés "from"/"to" (YYYY-mm-dd) -> fallback sur "dates".
     */
    public static function fromQuery(array $q): self
    {
        $f = new self();

        // Destination
        $f->destinationId = isset($q['destination']) && $q['destination'] !== ''
            ? (int) $q['destination']
            : null;

        // Type d'engin (multi)
        $f->parseEnumMulti(
            $q['enginType'] ?? [],
            EnginType::cases(),
            $f->enginTypeValues,
            $f->enginTypeEnums
        );

        // Dates
        $fromStr = isset($q['from']) && $q['from'] !== '' ? (string)$q['from'] : null;
        $toStr   = isset($q['to'])   && $q['to']   !== '' ? (string)$q['to']   : null;

        $from = $fromStr ? self::parseIsoDate($fromStr) : null;
        $to   = $toStr   ? self::parseIsoDate($toStr)   : null;

        // Fallback : parser "dates" (range Flatpickr) si from/to absents
        if ((!$from || !$to) && !empty($q['dates'])) {
            [$fFrom, $fTo] = self::parseFlexibleRange((string)$q['dates']);
            $from ??= $fFrom;
            $to   ??= $fTo;
        }

        $f->from = $from;
        $f->to   = $to;

        return $f;
    }

    /**
     * Sérialise les valeurs pour réafficher les filtres dans Twig.
     * - from / to au format machine (YYYY-mm-dd) pour réinjecter dans les champs cachés
     * - datesHuman pour l’input joli (altInput)
     */
    public function toActiveFilters(): array
    {
        $datesHuman = null;
        if ($this->from && $this->to) {
            $datesHuman = $this->from->format('j F Y') . ' – ' . $this->to->format('j F Y');
        }

        return [
            'destination' => $this->destinationId,
            'enginTypes' => $this->enginTypeValues,  // pour pré-sélection Twig
            'from'        => $this->from?->format('Y-m-d'),
            'to'          => $this->to?->format('Y-m-d'),
            'datesHuman'  => $datesHuman,
        ];
    }

    /* ===================== Helpers de domaine ===================== */

    public function hasDateRange(): bool
    {
        return $this->from !== null && $this->to !== null;
    }

    // --- Type d'engin ---
    public function hasEnginTypes(): bool
    {
        return !empty($this->enginTypeValues);
    }

    /** @return list<string> Backed values */
    public function getEnginTypeValues(): array
    {
        return $this->enginTypeValues;
    }

    /** @return list<EnginType> Enums (à utiliser en DQL/QueryBuilder) */
    public function getEnginTypeEnums(): array
    {
        return $this->syncEnums($this->enginTypeEnums, $this->enginTypeValues, EnginType::cases());
    }

    /**
     * Mappe une entrée multi-select (strings) vers des enums valides.
     *
     * @template T of \BackedEnum
     * @param mixed     $input        valeurs provenant de la query (string|string[])
     * @param list<T>   $all          toutes les cases de l'enum
     * @param-out list<string> $outValues  backed values validées/dédoublonnées
     * @param-out list<T>      $outEnums   enums correspondantes (même ordre)
     */
    private function parseEnumMulti(mixed $input, array $all, array &$outValues, array &$outEnums): void
    {
        if (\is_string($input)) $input = [$input];
        if (!\is_array($input)) {
            $outValues = [];
            $outEnums = [];
            return;
        }

        $byValue = [];
        foreach ($all as $case) {
            $byValue[$case->value] = $case;
        }

        $vals = [];
        $ens = [];
        $seen = [];
        foreach ($input as $val) {
            $val = (string)$val;
            if ($val === '' || !isset($byValue[$val]) || isset($seen[$val])) continue;
            $seen[$val] = true;
            $vals[] = $val;
            $ens[]  = $byValue[$val];
        }
        $outValues = $vals;
        $outEnums  = $ens;
    }

    /**
     * Resynchronise un cache d’enums à partir des backed values.
     *
     * @template T of \BackedEnum
     * @param list<T>      $cached
     * @param list<string> $values
     * @param list<T>      $all
     * @return list<T>
     */
    private function syncEnums(array $cached, array $values, array $all): array
    {
        if (\count($cached) === \count($values)) return $cached;
        $byValue = [];
        foreach ($all as $c) {
            $byValue[$c->value] = $c;
        }
        $res = [];
        foreach ($values as $v) if (isset($byValue[$v])) $res[] = $byValue[$v];
        return $res;
    }

    /* ===================== Helpers parsing dates ===================== */

    /** Parse YYYY-mm-dd (sans heure) */
    private static function parseIsoDate(string $d): ?DateTimeImmutable
    {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', trim($d));
        return $dt ?: null;
    }

    /** Parse d/m/Y (sans heure) */
    private static function parseFrDate(string $d): ?DateTimeImmutable
    {
        $dt = DateTimeImmutable::createFromFormat('!d/m/Y', trim($d));
        return $dt ?: null;
    }

    /**
     * Essaie d’extraire 2 dates depuis une chaîne "range" (Flatpickr).
     * Accepte divers séparateurs: ' - ', ' – ', ' to ', ' à ', etc.
     * Accepte les formats Y-m-d ou d/m/Y.
     *
     * @return array{0:?DateTimeImmutable,1:?DateTimeImmutable}
     */
    private static function parseFlexibleRange(string $range): array
    {
        $s = trim($range);

        // 1) tenter deux dates ISO (YYYY-mm-dd)
        if (preg_match_all('~\d{4}-\d{2}-\d{2}~', $s, $m) === 2) {
            $from = self::parseIsoDate($m[0][0] ?? '');
            $to   = self::parseIsoDate($m[0][1] ?? '');
            return [$from, $to];
        }

        // 2) sinon découper au séparateur et tenter d/m/Y
        $parts = preg_split('/\s*(?:-|–|to|à|au|jusqu\'?au)\s*/ui', $s);
        $parts = array_values(array_filter(array_map('trim', $parts)));
        if (count($parts) >= 2) {
            $from = self::parseFrDate($parts[0]) ?? self::parseIsoDate($parts[0]) ?? null;
            $to   = self::parseFrDate($parts[1]) ?? self::parseIsoDate($parts[1]) ?? null;
            return [$from, $to];
        }

        return [null, null];
    }
}
