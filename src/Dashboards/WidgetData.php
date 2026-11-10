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
 * - bar / column / line / area / pie / donut / share: the report's numbers
 *          per one of its columns,
 *          re-aggregated by the database (combined reports: in memory from
 *          the cached result);
 * - table: the report's first rows, as it sorts them.
 *
 * Widget: {id, type, title, report (uuid), category (alias), values
 * (aliases), top, size}.
 */
final class WidgetData
{
    public const TYPES = ['kpi', 'bar', 'column', 'line', 'area', 'pie', 'donut', 'share', 'table'];

    /** Types drawn in order along their column (time): sorted by it, more points allowed. */
    public const ORDERED = ['line', 'area'];

    /** Types that show parts of a whole (the rest folded into "Other"). */
    public const PARTS = ['pie', 'donut', 'share'];

    private const MAX_PARTS_ROWS = 500;

    /** Value alias for the report's row count. */
    public const ROW_COUNT = '__count';

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
            throw new QueryException('INVALID_WIDGET', 'A widget is a kpi, bar, column, line, area, pie, donut, share or table.', 'type');
        }

        $definition = ViewerChoices::apply($report->definition, $choices);
        $values = array_values(array_filter((array) ($widget['values'] ?? []), 'is_string'));
        $top = max(1, min((int) ($widget['top'] ?? 10), in_array($type, self::ORDERED, true) ? 400 : 50));
        // Parts of a whole: every category comes back, so the chart's "Other"
        // holds the rest and the total is the report's total. `top` is how
        // many parts get their own colour, which the chart decides.
        if (in_array($type, self::PARTS, true)) {
            $top = self::MAX_PARTS_ROWS;
        }

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

        // Combined reports, and reports that filter on their totals (HAVING:
        // "visits >= 80"), are charted from their own rows, so the widget
        // shows exactly what the report keeps.
        return Combiner::isCombined($definition) || $this->filterAliases($definition['filters'] ?? []) !== []
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
        // "Number of rows": always on offer, also for reports without totals.
        if (in_array(self::ROW_COUNT, $values, true) && ! isset($specs[self::ROW_COUNT]) && isset($definition['root'])) {
            $specs[self::ROW_COUNT] = ['ref' => $definition['root'].'.__rows', 'agg' => 'count', 'as' => self::ROW_COUNT, 'label' => 'Number of rows'];
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
            in_array($type, self::ORDERED, true) || $timeLike => [['field' => $category, 'dir' => 'asc']],
            default => [['field' => $first, 'dir' => 'desc']],
        };

        $result = $this->engine->rows($derived, [], $user, 0, $type === 'kpi' ? 1 : $top);
        $keepColumns = array_flip(array_filter([$category, ...$values]));
        $columns = array_values(array_filter($result['columns'], fn ($c) => isset($keepColumns[$c['alias']])));
        $rows = array_map(fn ($r) => array_intersect_key($r, $keepColumns), $result['rows']);

        return ['type' => $type, 'columns' => $columns, 'rows' => $rows, 'meta' => $result['meta'] + ['category' => $category, 'values' => $values]];
    }

    /** From the report's own rows (combined reports, totals filters): aggregated per category in memory. */
    private function combined(string $type, array $definition, ?string $category, array $values, int $top, ?Authenticatable $user): array
    {
        $stream = $this->engine->stream($definition, [], $user);
        $columns = array_column($stream['columns'], null, 'alias');
        $columns[self::ROW_COUNT] = ['alias' => self::ROW_COUNT, 'label' => 'Number of rows', 'type' => 'integer', 'kind' => 'measure', 'total' => 'sum'];
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
                if ($alias === self::ROW_COUNT) {
                    $groups[$key]['values'][$alias][] = 1;
                } elseif (is_numeric($row[$alias] ?? null)) {
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
        usort($rows, in_array($type, self::ORDERED, true)
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
