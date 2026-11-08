<?php

namespace Abdalla\ReportGenerator\Dashboards;

use Illuminate\Support\Arr;

/**
 * A saved definition with what a viewer picked: the report filters
 * (period, lines, divisions, employees) replace the saved ones, and the sort
 * applies to the report's own columns only.
 */
final class ViewerChoices
{
    /** @param  array{context?: array, sort?: list<array>}  $choices */
    public static function apply(array $definition, array $choices): array
    {
        if (isset($choices['sort']) && is_array($choices['sort'])) {
            $aliases = array_column($definition['fields'] ?? [], 'as');
            $definition['sort'] = array_values(array_filter($choices['sort'], fn ($s) => is_array($s) && in_array($s['field'] ?? null, $aliases, true)));
        }

        $picked = Arr::only((array) ($choices['context'] ?? []), ['period', 'lines', 'divisions', 'employees']);
        $context = array_merge((array) ($definition['context'] ?? []), $picked);
        $definition['context'] = array_filter($context, fn ($v) => $v !== null && $v !== []);

        return $definition;
    }
}
