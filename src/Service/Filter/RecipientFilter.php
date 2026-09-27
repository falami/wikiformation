<?php
namespace App\Service\Filter;

use Symfony\Component\HttpFoundation\InputBag;

/** Explicit selection state: an empty selection must never mean all recipients. */
final class RecipientFilter
{
    /** @return array{sql:string, parameters:array<string,list<int>>} */
    public static function condition(InputBag $input, string $userField, string $companyField): array
    {
        $users = self::ids($input->all('payeurUserIds'));
        $companies = self::ids($input->all('payeurEntrepriseIds'));
        $legacyFiltered = $users !== [] || $companies !== [];
        $userMode = self::mode($input->get('payeurUserMode'), $users, $legacyFiltered);
        $companyMode = self::mode($input->get('payeurEntrepriseMode'), $companies, $legacyFiltered);
        if ($userMode === 'all' && $companyMode === 'all') return ['sql' => '1 = 1', 'parameters' => []];
        $parts = []; $parameters = [];
        if ($companyMode === 'all') $parts[] = "$companyField IS NOT NULL";
        if ($companyMode === 'selected' && $companies !== []) {
            $parts[] = "$companyField IN (:wfCompanies)";
            $parameters['wfCompanies'] = $companies;
        }
        // An invoice can also keep a contact while being addressed to a company.
        if ($userMode === 'all') $parts[] = "$companyField IS NULL";
        if ($userMode === 'selected' && $users !== []) {
            $parts[] = "($companyField IS NULL AND $userField IN (:wfUsers))";
            $parameters['wfUsers'] = $users;
        }
        return ['sql' => $parts ? '(' . implode(' OR ', $parts) . ')' : '1 = 0', 'parameters' => $parameters];
    }

    private static function mode(?string $mode, array $ids, bool $legacyFiltered): string
    {
        if (in_array($mode, ['all', 'none', 'selected'], true)) return $mode;
        return !$legacyFiltered ? 'all' : ($ids ? 'selected' : 'none');
    }

    private static function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id) => $id > 0)));
    }
}
