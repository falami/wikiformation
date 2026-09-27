<?php

namespace App\Service\Filter;

use Doctrine\ORM\QueryBuilder;

/** Backward-compatible transport: all, one scalar value, or a JSON list (including []). */
final class ChoiceFilter
{
    /** @return list<string>|null Null means unrestricted; [] explicitly means no results. */
    public static function values(mixed $raw): ?array
    {
        if ($raw === null || $raw === '' || $raw === 'all' || $raw === '*') {
            return null;
        }
        if (is_string($raw) && str_starts_with(trim($raw), '[')) {
            $raw = json_decode($raw, true);
            if (!is_array($raw) || !array_is_list($raw)) return [];
        }
        if (!is_array($raw)) $raw = [$raw];
        return array_values(array_unique(array_map('strval', array_filter($raw,
            static fn($value) => is_string($value) || is_int($value)))));
    }

    public static function equals(QueryBuilder $qb, mixed $raw, string $field, string $parameter): void
    {
        $values = self::values($raw);
        if ($values === null) return;
        if (!$values) { $qb->andWhere('1 = 0'); return; }
        $qb->andWhere($field . ' IN (:' . $parameter . ')')->setParameter($parameter, $values);
    }

    /**
     * OR the conditions for each selected option, then AND them with the scoped query.
     * The callback adds predicates using existing aliases; it must not add joins.
     * Parameters are copied with unique names, so options cannot overwrite each other.
     */
    public static function any(QueryBuilder $qb, mixed $raw, callable $apply): void
    {
        $values = self::values($raw);
        if ($values === null) return;
        $clauses = [];
        foreach ($values as $value) {
            $branch = clone $qb;
            $branch->resetDQLPart('where');
            $apply($branch, $value);
            $predicate = (string) $branch->getDQLPart('where');
            // Unknown options must never broaden the query.
            if ($predicate === '') continue;
            $names = [];
            $predicate = preg_replace_callback('/:(\w+)/', static function ($match) use ($qb, $branch, &$names) {
                $name = $match[1];
                if (!isset($names[$name])) {
                    $parameter = $branch->getParameter($name);
                    if (!$parameter) throw new \LogicException('Missing filter parameter: ' . $name);
                    $target = 'wf_choice_' . count($qb->getParameters());
                    while ($qb->getParameter($target)) $target .= '_';
                    $qb->setParameter($target, $parameter->getValue(), $parameter->typeWasSpecified() ? $parameter->getType() : null);
                    $names[$name] = $target;
                }
                return ':' . $names[$name];
            }, $predicate);
            $clauses[] = '(' . $predicate . ')';
        }
        $qb->andWhere($clauses ? '(' . implode(' OR ', $clauses) . ')' : '1 = 0');
    }
}
