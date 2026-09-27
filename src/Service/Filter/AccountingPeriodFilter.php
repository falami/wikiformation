<?php

namespace App\Service\Filter;

use Doctrine\ORM\QueryBuilder;

/** Calendar facets shared by accounting SQL and DQL queries. Field names are internal constants. */
final class AccountingPeriodFilter
{
    /** @return array{sql:string, parameters:array<string,list<string>>} */
    public static function condition(string $field, mixed $type, mixed $years, mixed $months, mixed $quarters): array
    {
        $types = ChoiceFilter::values($type);
        if ($types === null) return ['sql' => '1 = 1', 'parameters' => []];
        $yearValues = self::numbers($years, 1000, 9999);
        if ($yearValues === []) return ['sql' => '1 = 0', 'parameters' => []];

        $parameters = [];
        $yearClause = '1 = 1';
        if ($yearValues !== null) {
            $yearClause = 'SUBSTRING(' . $field . ', 1, 4) IN (:wf_period_years)';
            $parameters['wf_period_years'] = array_map('strval', $yearValues);
        }
        $clauses = [];
        foreach (array_intersect($types, ['year', 'month', 'quarter']) as $selectedType) {
            if ($selectedType === 'year') {
                $clauses[] = $yearClause;
                continue;
            }
            $selected = self::numbers($selectedType === 'month' ? $months : $quarters, 1, $selectedType === 'month' ? 12 : 4);
            if ($selected === []) continue;
            if ($selected === null) {
                $clauses[] = $yearClause;
                continue;
            }
            $monthValues = [];
            foreach ($selected as $value) {
                foreach ($selectedType === 'quarter' ? range($value * 3 - 2, $value * 3) : [$value] as $month) {
                    $monthValues[] = sprintf('%02d', $month);
                }
            }
            $parameter = 'wf_period_' . $selectedType;
            $parameters[$parameter] = array_values(array_unique($monthValues));
            $clauses[] = '(' . $yearClause . ' AND SUBSTRING(' . $field . ', 6, 2) IN (:' . $parameter . '))';
        }
        if (!$clauses) return ['sql' => '1 = 0', 'parameters' => []];
        return ['sql' => '(' . implode(' OR ', array_unique($clauses)) . ')', 'parameters' => $parameters];
    }

    public static function apply(QueryBuilder $qb, string $field, mixed $type, mixed $years, mixed $months, mixed $quarters): void
    {
        $filter = self::condition($field, $type, $years, $months, $quarters);
        $qb->andWhere($filter['sql']);
        foreach ($filter['parameters'] as $name => $values) $qb->setParameter($name, $values);
    }

    /** @return list<int>|null */
    private static function numbers(mixed $raw, int $min, int $max): ?array
    {
        $values = ChoiceFilter::values($raw);
        if ($values === null) return null;
        return array_values(array_unique(array_map('intval', array_filter($values,
            static fn(string $value): bool => ctype_digit($value) && (int) $value >= $min && (int) $value <= $max))));
    }
}
