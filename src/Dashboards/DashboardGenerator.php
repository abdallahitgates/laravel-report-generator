<?php

namespace Abdalla\ReportGenerator\Dashboards;

use Abdalla\ReportGenerator\Models\Report;
use Abdalla\ReportGenerator\Query\QueryEngine;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;

/**
 * A first dashboard for a report, from its columns: a KPI per main number,
 * a line chart over its time column (one number: one axis), a bar chart of
 * the top items of its first other column, a donut of the second, and the
 * report's top rows. Any widget can be switched to another type later.
 * People then adjust it in the editor.
 */
final class DashboardGenerator
{
    private const TIME_GRAINS = ['day', 'week', 'month', 'quarter', 'year'];

    public function __construct(private readonly QueryEngine $engine)
    {
    }

    /** @return array{name: string, context: array, widgets: list<array>} */
    public function generate(Report $report, ?Authenticatable $user): array
    {
        $columns = $this->engine->rows($report->definition, [], $user, 0, 1)['columns'];
        $grains = [];
        foreach ($report->definition['fields'] ?? [] as $field) {
            if (isset($field['as'], $field['grain'])) {
                $grains[$field['as']] = $field['grain'];
            }
        }

        $numbers = array_values(array_filter($columns, fn ($c) => $c['kind'] === 'measure' && in_array($c['type'], ['integer', 'decimal'], true)));
        $dims = array_values(array_filter($columns, fn ($c) => $c['kind'] === 'dimension' && $c['alias'] !== '__subtotal'));
        $time = array_values(array_filter($dims, fn ($c) => in_array($grains[$c['alias']] ?? null, self::TIME_GRAINS, true)));
        $other = array_values(array_filter($dims, fn ($c) => ! in_array($c, $time, true) && in_array($c['type'], ['string', 'boolean', 'integer'], true)));

        $widgets = [];
        $add = function (array $widget) use (&$widgets, $report) {
            $widgets[] = ['id' => 'w'.(count($widgets) + 1), 'report' => $report->uuid] + $widget;
        };

        // Detail reports have no totals of their own: count rows instead of listing numbers.
        $kpis = array_slice($numbers, 0, 4);
        foreach ($kpis as $c) {
            $add(['type' => 'kpi', 'title' => $c['label'], 'values' => [$c['alias']], 'size' => 'sm']);
        }

        $main = array_map(fn ($c) => $c['alias'], array_slice($numbers, 0, 2));
        // One number per line chart: two numbers of different scales never share an axis.
        foreach (array_slice($main, 0, $time !== [] ? 2 : 0) as $i => $alias) {
            $add(['type' => 'line', 'title' => $this->title($numbers, [$alias], $time[0]), 'category' => $time[0]['alias'], 'values' => [$alias], 'top' => 120, 'size' => count($main) > 1 ? 'md' : 'full']);
        }
        if ($main !== [] && isset($other[0])) {
            $add(['type' => 'bar', 'title' => 'Top '.Str::plural(Str::lower($other[0]['label'])).' by '.Str::lower($numbers[0]['label']), 'category' => $other[0]['alias'], 'values' => [$main[0]], 'top' => 10, 'size' => isset($other[1]) ? 'md' : 'full']);
        }
        if ($main !== [] && isset($other[1])) {
            $add(['type' => 'donut', 'title' => $numbers[0]['label'].' by '.Str::lower($other[1]['label']), 'category' => $other[1]['alias'], 'values' => [$main[0]], 'top' => 6, 'size' => 'md']);
        }
        $add(['type' => 'table', 'title' => $report->name, 'top' => 10, 'size' => 'full']);

        return [
            'name' => $report->name.' dashboard',
            'context' => (object) array_intersect_key((array) ($report->definition['context'] ?? []), array_flip(['period', 'lines'])),
            'widgets' => $widgets,
        ];
    }

    private function title(array $numbers, array $aliases, array $time): string
    {
        $labels = array_map(fn ($a) => collect($numbers)->firstWhere('alias', $a)['label'] ?? $a, $aliases);

        return implode(' and ', $labels).' by '.Str::lower($time['label']);
    }
}
