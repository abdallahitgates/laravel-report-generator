<?php

namespace Abdalla\ReportGenerator\Dashboards;

use Illuminate\Support\Arr;

/**
 * A saved definition with what a viewer picked: the report filters
 * (period, lines, divisions, employees) replace the saved ones; the sort and
 * extra filters apply to the report's own columns only.
 */
final class ViewerChoices
{
    /** @param  array{context?: array, sort?: list<array>, filters?: list<array{field: string, op: string, value?: mixed}>}  $choices */
    public static function apply(array $definition, array $choices): array
    {
        if (isset($choices['sort']) && is_array($choices['sort'])) {
            $aliases = array_column($definition['fields'] ?? [], 'as');
            $definition['sort'] = array_values(array_filter($choices['sort'], fn ($s) => is_array($s) && in_array($s['field'] ?? null, $aliases, true)));
        }

        // Viewer filters only narrow the saved report: on its own columns, AND-ed with its filters.
        if (isset($choices['filters']) && is_array($choices['filters'])) {
            $aliases = array_column($definition['fields'] ?? [], 'as');
            $extra = [];
            foreach ($choices['filters'] as $filter) {
                if (! is_array($filter) || ! in_array($filter['field'] ?? null, $aliases, true) || ! is_string($filter['op'] ?? null)) {
                    continue;
                }
                $extra[] = array_filter(
                    ['field' => $filter['field'], 'op' => $filter['op'], 'value' => $filter['value'] ?? null],
                    fn ($v, $k) => $k !== 'value' || ! in_array($filter['op'], ['is_null', 'not_null'], true),
                    ARRAY_FILTER_USE_BOTH,
                );
            }
            if ($extra !== []) {
                $definition['filters'] = ['and' => [...(isset($definition['filters']) ? [$definition['filters']] : []), ...$extra]];
            }
        }

        $picked = Arr::only((array) ($choices['context'] ?? []), ['period', 'lines', 'divisions', 'employees']);
        $context = array_merge((array) ($definition['context'] ?? []), $picked);
        $definition['context'] = array_filter($context, fn ($v) => $v !== null && $v !== []);

        return $definition;
    }
}
