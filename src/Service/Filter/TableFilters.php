<?php
namespace App\Service\Filter;

use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\Request;

/** Server-side facets. Call on the tenant-scoped query BEFORE text search/pagination. */
final class TableFilters
{
    /** @param array<string,array{field:string,label:string,display?:string,labels?:array}> $fields */
    public static function prepare(QueryBuilder $qb, Request $request, array $fields): array
    {
        $facets = [];
        foreach ($fields as $key => $config) {
            $query = clone $qb;
            $rows = $query->resetDQLPart('orderBy')->resetDQLPart('groupBy')
                ->select($config['field'] . ' AS value', ($config['display'] ?? $config['field']) . ' AS label')
                ->distinct()->setFirstResult(0)->setMaxResults(null)->getQuery()->getArrayResult();
            $options = [];
            foreach ($rows as $row) {
                $value = self::scalar($row['value']);
                $label = self::scalar($row['label']);
                $options[$value] = ['value' => $value, 'label' => $config['labels'][$value] ?? (in_array($label, ['', '__empty__'], true) ? 'Non renseigné' : $label)];
            }
            uasort($options, static fn(array $a, array $b) => strnatcasecmp($a['label'], $b['label']));
            $facets[] = ['key' => $key, 'label' => $config['label'], 'options' => array_values($options)];
        }
        self::apply($qb, $request, $fields);
        return $facets;
    }

    public static function apply(QueryBuilder $qb, Request $request, array $fields): void
    {
        $raw = $request->request->get('wfFilters', '{}');
        $filters = json_decode(is_string($raw) ? $raw : '{}', true);
        if (!is_array($filters)) return;
        foreach ($fields as $key => $config) {
            if (!array_key_exists($key, $filters) || $filters[$key] === '*') continue;
            $values = is_array($filters[$key]) ? $filters[$key] : [];
            $values = array_values(array_filter($values, static fn($v) => is_string($v) || is_int($v)));
            if (!$values || in_array('__none__', $values, true)) { $qb->andWhere('1 = 0'); continue; }
            $nullable = in_array('__empty__', $values, true);
            $values = array_values(array_filter($values, static fn($v) => $v !== '__empty__'));
            $clauses = [];
            if ($nullable) $clauses[] = $config['field'] . ' IS NULL';
            if ($values) {
                $parameter = 'wf_' . $key;
                $clauses[] = $config['field'] . ' IN (:' . $parameter . ')';
                $qb->setParameter($parameter, $values);
            }
            $qb->andWhere('(' . implode(' OR ', $clauses) . ')');
        }
    }

    private static function scalar(mixed $value): string
    {
        if ($value === null) return '__empty__';
        if ($value instanceof \BackedEnum) return (string) $value->value;
        if (is_bool($value)) return $value ? '1' : '0';
        return (string) $value;
    }
}
