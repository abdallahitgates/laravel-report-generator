<?php

namespace Abdalla\ReportGenerator\Dashboards;

use Abdalla\ReportGenerator\Combine\Combiner;
use Abdalla\ReportGenerator\Models\Report;
use Abdalla\ReportGenerator\Query\Formula\Parser;
use Abdalla\ReportGenerator\Query\QueryEngine;
use Abdalla\ReportGenerator\Query\QueryException;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The data behind one dashboard widget, always from a saved report and
 * always with the viewer's own access:
 *
 * - kpi:   totals of some of the report's numbers (one aggregate query);
 * - bar / line / pie: the report's numbers per one of its columns,
 *          re-aggregated by the database (combined reports: in memory from
 *          the cached result);
 * - table: the report's first rows, as it sorts them.
 *
 * Widget: {id, type, title, report (uuid), category (alias), values
 * (aliases), top, size}.
 */
final class WidgetData
{
    public const TYPES = ['kpi', 'bar', 'line', 'pie', 'table'];

    private const TIME_GRAINS = ['day', 'week', 'month', 'quarter', 'year', 'hour'];

    public function __construct(private readonly QueryEngine $engine)
    {
    }

    /**
     * @param  array{context?: array}  $choices  the dashboard's report filters
     * @return array{type: string, columns: list<array>, rows: list<array>, meta: array}
     */
    public function compute(Report $report, array $widget, array $choices, ?Authenticatable $user): array
    {
        $type = $widget['type'] ?? null;
        if (! in_array($type, self::TYPES, true)) {
            throw new QueryException('INVALID_WIDGET', 'A widget is a kpi, bar, line, pie or table.', 'type');
        }

        $definition = ViewerChoices::apply($report->definition, $choices);
        $values = array_values(array_filter((array) ($widget['values'] ?? []), 'is_string'));
        $top = max(1, min((int) ($widget['top'] ?? 10), $type === 'line' ? 400 : 50));

        if ($type === 'table') {
            $result = $this->engine->rows($definition, [], $user, 0, $top);
            if ($values !== []) {
                $keep = array_flip($values);
                $result['columns'] = array_values(array_filter($result['columns'], fn ($c) => isset($keep[$c['alias']])));
                $result['rows'] = array_map(fn ($r) => array_intersect_key($r, $keep), $result['rows']);
            }

            return ['type' => $type, 'columns' => $result['columns'], 'rows' => $result['rows'], 'meta' => $result['meta']];
        }

        if ($values === []) {
            throw new QueryException('INVALID_WIDGET', 'Pick at least one number to show.', 'values');
        }
        $category = $type === 'kpi' ? null : ($widget['category'] ?? null);
        if ($type !== 'kpi' && ! is_string($category)) {
            throw new QueryException('INVALID_WIDGET', 'Pick the column to chart by.', 'category');
        }

        return Combiner::isCombined($definition)
            ? $this->combined($type, $definition, $category, $values, $top, $user)
            : $this->topic($type, $definition, $category, $values, $top, $user);
    }

    /** Topic reports: a smaller definition with just the widget's columns, aggregated by the database. */
    private function topic(string $type, array $definition, ?string $category, array $values, int $top, ?Authenticatable $user): array
    {
        $specs = [];
        foreach ($definition['fields'] ?? [] as $field) {
            if (isset($field['as'])) {
                $specs[$field['as']] = $field;
            }
        }
        foreach ([$category, ...$values] as $alias) {
            if ($alias !== null && ! isset($specs[$alias])) {
                throw new QueryException('UNKNOWN_FIELD', "The report has no column \"{$alias}\".", 'values');
            }
        }
        if ($category !== null && (isset($specs[$category]['agg']) || isset($specs[$category]['formula']))) {
            throw new QueryException('INVALID_WIDGET', 'Chart by a column that groups rows (a name, a month), not a total.', 'category');
        }

        // The widget's columns, plus whatever their formulas and the report's total filters use.
        $needed = array_values(array_filter([$category, ...$values]));
        $queue = $needed;
        $filterAliases = $this->filterAliases($definition['filters'] ?? []);
        array_push($queue, ...$filterAliases);
        $keep = [];
        while ($queue !== []) {
            $alias = array_shift($queue);
            if (isset($keep[$alias]) || ! isset($specs[$alias])) {
                continue;
            }
            $keep[$alias] = true;
            if (isset($specs[$alias]['formula'])) {
                array_push($queue, ...Parser::parse($specs[$alias]['formula'])->references());
            }
        }

        $fields = [];
        foreach ($specs as $alias => $spec) {
            if (! isset($keep[$alias]) || ($alias !== $category && ! isset($spec['agg']) && ! isset($spec['formula']) && $this->isDimension($spec, $alias, $values))) {
                continue;
            }
            // Numbers shown in a total-less (detail) report are added up.
            if ($alias !== $category && ! isset($spec['agg']) && ! isset($spec['formula'])) {
                $spec['agg'] = 'sum';
            }
            $fields[] = $spec;
        }
        // Category first, as the chart's x axis.
        usort($fields, fn ($a, $b) => ($b['as'] === $category) <=> ($a['as'] === $category));

        $derived = $definition;
        $derived['fields'] = $fields;
        unset($derived['ui'], $derived['subtotals']);
        $first = $values[0];
        $timeLike = $category !== null && in_array($specs[$category]['grain'] ?? null, self::TIME_GRAINS, true);
        $derived['sort'] = match (true) {
            $category === null => [],
            $type === 'line' || $timeLike => [['field' => $category, 'dir' => 'asc']],
            default => [['field' => $first, 'dir' => 'desc']],
        };

        $result = $this->engine->rows($derived, [], $user, 0, $type === 'kpi' ? 1 : $top);
        $keepColumns = array_flip(array_filter([$category, ...$values]));
        $columns = array_values(array_filter($result['columns'], fn ($c) => isset($keepColumns[$c['alias']])));
        $rows = array_map(fn ($r) => array_intersect_key($r, $keepColumns), $result['rows']);

        return ['type' => $type, 'columns' => $columns, 'rows' => $rows, 'meta' => $result['meta'] + ['category' => $category, 'values' => $values]];
    }

    /** Combined reports: aggregate the cached rows per category in memory. */
    private function combined(string $type, array $definition, ?string $category, array $values, int $top, ?Authenticatable $user): array
    {
        $stream = $this->engine->stream($definition, [], $user);
        $columns = array_column($stream['columns'], null, 'alias');
        foreach ([$category, ...$values] as $alias) {
            if ($alias !== null && ! isset($columns[$alias])) {
                throw new QueryException('UNKNOWN_FIELD', "The report has no column \"{$alias}\".", 'values');
            }
        }

        $groups = [];
        foreach ($stream['rows'] as $row) {
            $key = $category === null ? '' : (string) ($row[$category] ?? '');
            $groups[$key] ??= ['label' => $category === null ? null : ($row[$category] ?? null), 'values' => []];
            foreach ($values as $alias) {
                if (is_numeric($row[$alias] ?? null)) {
                    $groups[$key]['values'][$alias][] = $row[$alias] + 0;
                }
            }
        }

        $rows = [];
        foreach ($groups as $group) {
            $out = $category === null ? [] : [$category => $group['label']];
            foreach ($values as $alias) {
                $list = $group['values'][$alias] ?? [];
                $out[$alias] = $list === [] ? null : match ($columns[$alias]['total'] ?? null) {
                    'sum' => array_sum($list),
                    'min' => min($list),
                    'max' => max($list),
                    // Averages and rates: the average across the group.
                    default => array_sum($list) / count($list),
                };
            }
            $rows[] = $out;
        }

        $first = $values[0];
        usort($rows, $type === 'line'
            ? fn ($a, $b) => strnatcasecmp((string) ($a[$category] ?? ''), (string) ($b[$category] ?? ''))
            : fn ($a, $b) => ($b[$first] ?? -INF) <=> ($a[$first] ?? -INF));
        $rows = array_slice($rows, 0, $type === 'kpi' ? 1 : $top);

        $keep = array_filter([$category, ...$values]);

        return ['type' => $type, 'columns' => array_values(array_map(fn ($a) => $columns[$a], $keep)), 'rows' => $rows, 'meta' => ['mode' => 'combined', 'category' => $category, 'values' => $values]];
    }

    private function isDimension(array $spec, string $alias, array $values): bool
    {
        // A non-total column the widget does not show (a formula input, a filtered column) stays as-is only if asked for.
        return ! in_array($alias, $values, true) && ! isset($spec['agg']);
    }

    /** @return list<string> report column names (no dot) used in filters, i.e. filters on totals */
    private function filterAliases(mixed $node): array
    {
        $out = [];
        if (is_array($node)) {
            if (isset($node['field']) && is_string($node['field']) && ! str_contains($node['field'], '.')) {
                $out[] = $node['field'];
            }
            foreach ($node as $child) {
                array_push($out, ...$this->filterAliases($child));
            }
        }

        return $out;
    }
}
