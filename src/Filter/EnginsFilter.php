<?php
// src/Filter/EnginsFilter.php
declare(strict_types=1);

namespace App\Filter;

/**
 * Filtres pour la flotte :
 * - destinationId : filtrer par Site
 * - types         : liste de backed values de EnginType (ex: "Catamaran")
 * - capMin        : capacité embarquée minimale
 * - couchMin      : capacité couchage minimale
 */
final class EnginsFilter
{
    public ?int   $destinationId = null;
    /** @var list<string> */
    public array  $types = [];
    public ?int   $capMin = null;
    public ?int   $couchMin = null;

    /** Construit le filtre depuis la query-string. */
    public static function fromQuery(array $q): self
    {
        $f = new self();

        // destination (Site id)
        $dest = $q['destination'] ?? null;
        if (is_numeric($dest)) {
            $f->destinationId = (int)$dest ?: null;
        }

        // types (multi)
        $types = $q['types'] ?? [];
        if (is_string($types)) { $types = [$types]; }
        if (is_array($types)) {
            // garder uniquement des strings non vides
            $f->types = array_values(array_filter(array_map('strval', $types), static fn($v) => $v !== ''));
        }

        // capMin / couchMin
        $cap    = $q['capMin']   ?? null;
        $couch  = $q['couchMin'] ?? null;
        $f->capMin   = is_numeric($cap)   ? max(0, (int)$cap)   : null;
        $f->couchMin = is_numeric($couch) ? max(0, (int)$couch) : null;

        return $f;
    }

    /** Pour re-remplir les champs/placeholder côté Twig */
    public function toActiveFilters(): array
    {
        return [
            'destination' => $this->destinationId,
            'types'       => $this->types,
            'capMin'      => $this->capMin,
            'couchMin'    => $this->couchMin,
        ];
    }
}
