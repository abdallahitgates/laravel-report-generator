<?php

namespace Abdalla\ReportGenerator\Combine;

use Abdalla\ReportGenerator\Contracts\CombinableReport;
use Abdalla\ReportGenerator\Enums\Ability;
use Abdalla\ReportGenerator\Query\Definition\DatePresets;
use Abdalla\ReportGenerator\Query\Formula\Evaluator;
use Abdalla\ReportGenerator\Query\Formula\FormulaException;
use Abdalla\ReportGenerator\Query\Formula\Node;
use Abdalla\ReportGenerator\Query\QueryEngine;
use Abdalla\ReportGenerator\Query\QueryException;
use Abdalla\ReportGenerator\ReportGenerator;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Str;

/**
 * Combined reports: columns from several existing reports, side by side,
 * one row per employee or per division.
 *
 *   {
 *     "type": "combined",
 *     "level": "employee",
 *     "context": {"period": "this_month", "lines": [1]},
 *     "fields": [
 *       {"base": "employee", "as": "employee"},
 *       {"report": "visit_statistics", "field": "total_actual", "as": "visits", "label": "Visits"},
 *       {"report": "sales_achievement", "field": "value", "as": "sales"},
 *       {"formula": "sales / visits", "as": "sales_per_visit", "label": "Sales per visit"}
 *     ],
 *     "sort": [{"field": "visits", "dir": "desc"}]
 *   }
 *
 * Each report runs once per combined run (as the viewer, with its own row
 * rules), rows are joined on the employee / division id, and the merged
 * table is cached so paging, sorting, totals and exports reuse it.
 */
final class Combiner
{
    public const BASE = ['line' => 'Line', 'division' => 'Division', 'employee' => 'Employee'];

    public function __construct(
        private readonly CombineRegistry $reports,
        private readonly ReportGenerator $generator,
        private readonly Cache $cache,
    ) {
    }

    public static function isCombined(array $definition): bool
    {
        return ($definition['type'] ?? null) === 'combined';
    }

    /** Reports this user may combine. @return list<CombinableReport> */
    public function available(?Authenticatable $user): array
    {
        return array_values(array_filter($this->reports->all(), fn (CombinableReport $r) => $this->allowed($r, $user)));
    }

    /**
     * Columns a report offers for this level and period: declared, or found
     * by running it once (cached).
     *
     * @return list<array{key: string, label: string, type: string, total: ?string}>
     */
    public function fieldsOf(string $key, array $context, string $level, ?Authenticatable $user): array
    {
        $report = $this->report($key, $user, "report");
        if ($report->fields() !== null) {
            return $this->describe($report->fields(), []);
        }

        $run = $this->run(['level' => $level, 'context' => $context], $user);
        $rows = $this->rowsOf($report, $run);

        return $this->describe(null, array_slice($rows, 0, 200), $report);
    }

    /** Throws when the definition is not a valid combined report. */
    public function check(array $definition, ?Authenticatable $user): void
    {
        $this->parse($definition, $user);
    }

    /**
     * One page of the combined report, in the shape of QueryEngine::rows().
     */
    public function page(array $definition, array $parameters, ?Authenticatable $user, int $offset, int $size): array
    {
        $started = microtime(true);
        [$columns, $rows, $cached] = $this->table($definition, $user);
        $page = array_slice($rows, $offset, $size + 1);

        return [
            'columns' => $columns,
            'rows' => array_slice($page, 0, $size),
            'page' => ['offset' => $offset, 'size' => $size, 'has_more' => count($page) > $size],
            'meta' => ['mode' => 'combined', 'duration_ms' => (int) round((microtime(true) - $started) * 1000), 'cached' => $cached],
        ];
    }

    /** Count and grand totals, in the shape of QueryEngine::summary(). */
    public function summary(array $definition, ?Authenticatable $user): array
    {
        $started = microtime(true);
        [$columns, $rows, $cached] = $this->table($definition, $user);
        $totals = [];
        foreach ($columns as $column) {
            if (($column['total'] ?? null) === null) {
                continue;
            }
            $values = array_values(array_filter(array_column($rows, $column['alias']), 'is_numeric'));
            $totals[$column['alias']] = $values === [] ? null : match ($column['total']) {
                'sum' => array_sum($values),
                'min' => min($values),
                'max' => max($values),
                default => null,
            };
        }

        return ['rows' => count($rows), 'totals' => $totals, 'duration_ms' => (int) round((microtime(true) - $started) * 1000), 'cached' => $cached];
    }

    /** Every row, in the shape of QueryEngine::stream(). */
    public function stream(array $definition, ?Authenticatable $user): array
    {
        [$columns, $rows] = $this->table($definition, $user);

        return ['columns' => $columns, 'rows' => (fn () => yield from $rows)()];
    }

    // ----------------------------------------------------------------------

    /** @return array{0: list<array>, 1: list<array<string, mixed>>, 2: bool} columns, sorted rows, from cache */
    private function table(array $definition, ?Authenticatable $user): array
    {
        $plan = $this->parse($definition, $user);
        $cacheKey = 'report-generator:combined:'.hash('sha256', json_encode([$plan['fields'], $plan['sort'], $plan['run']->key(), $this->policyVersion()]));

        if (($hit = $this->cache->get($cacheKey)) !== null) {
            return [$hit['columns'], $hit['rows'], true];
        }

        $run = $plan['run'];
        $byId = [];
        $columnsMeta = [];
        foreach ($plan['reports'] as $key => $report) {
            $rows = $this->rowsOf($report, $run);
            $meta = $this->describe($report->fields(), array_slice($rows, 0, 200), $report);
            $columnsMeta[$key] = array_column($meta, null, 'key');
            foreach ($rows as $row) {
                $id = (string) $row['id'];
                $byId[$id] ??= ['__id' => $row['id']];
                foreach (array_keys(self::BASE) as $base) {
                    if (($byId[$id][$base] ?? null) === null && isset($row[$base]) && $row[$base] !== '') {
                        $byId[$id][$base] = $row[$base];
                    }
                }
                $byId[$id]['__r'][$key] = $row;
            }
        }

        $columns = [];
        foreach ($plan['fields'] as $field) {
            $columns[] = $this->column($field, $columnsMeta);
        }
        $types = array_column($columns, 'source_type', 'alias');

        $rows = [];
        foreach ($byId as $entry) {
            $row = [];
            foreach ($plan['fields'] as $field) {
                if (isset($field['base'])) {
                    $row[$field['as']] = $entry[$field['base']] ?? null;
                } elseif (isset($field['report'])) {
                    $row[$field['as']] = $this->value($entry['__r'][$field['report']][$field['field']] ?? null, $types[$field['as']] ?? 'string');
                }
            }
            foreach ($plan['fields'] as $field) {
                if (isset($field['formula'])) {
                    $row[$field['as']] = $this->formula($field, $row);
                }
            }
            $rows[] = $row;
        }

        $rows = $this->sort($rows, $plan['sort'], $plan['fields']);
        $columns = array_map(fn ($c) => array_diff_key($c, ['source_type' => 1]), $columns);
        $this->cache->put($cacheKey, ['columns' => $columns, 'rows' => $rows], (int) config('report-generator.cache.ttl', 600));

        return [$columns, $rows, false];
    }

    /** @return array{run: CombineRun, fields: list<array>, sort: list<array>, reports: array<string, CombinableReport>} */
    private function parse(array $definition, ?Authenticatable $user): array
    {
        $run = $this->run($definition, $user);
        $fields = $definition['fields'] ?? null;
        if (! is_array($fields) || $fields === []) {
            throw new QueryException('NO_FIELDS', 'Add at least one column.', 'fields');
        }

        $reports = [];
        $aliases = [];
        $clean = [];
        foreach (array_values($fields) as $i => $field) {
            $at = "fields.{$i}";
            $as = $field['as'] ?? null;
            if (! is_string($as) || ! preg_match('/^[a-z][a-z0-9_]{0,63}$/i', $as)) {
                throw new QueryException('INVALID_ALIAS', 'Each column needs a name ("as"): letters, digits and underscores, starting with a letter.', "{$at}.as");
            }
            if (isset($aliases[$as])) {
                throw new QueryException('DUPLICATE_ALIAS', "Two columns are named \"{$as}\".", "{$at}.as");
            }
            $aliases[$as] = true;
            $label = isset($field['label']) && is_string($field['label']) ? mb_substr($field['label'], 0, 120) : null;

            if (isset($field['base'])) {
                if (! isset(self::BASE[$field['base']])) {
                    throw new QueryException('UNKNOWN_FIELD', 'Base columns are line, division and employee.', "{$at}.base");
                }
                $clean[] = ['base' => $field['base'], 'as' => $as, 'label' => $label];
            } elseif (isset($field['report'])) {
                $report = $this->report((string) $field['report'], $user, "{$at}.report");
                if (! in_array($run->level, $report->levels(), true)) {
                    throw new QueryException('LEVEL_UNSUPPORTED', "{$report->label()} has no rows per {$run->level}.", "{$at}.report");
                }
                if (! is_string($field['field'] ?? null) || $field['field'] === '') {
                    throw new QueryException('UNKNOWN_FIELD', "Pick a column of {$report->label()}.", "{$at}.field");
                }
                $reports[$report->key()] = $report;
                $clean[] = ['report' => $report->key(), 'field' => $field['field'], 'as' => $as, 'label' => $label];
            } elseif (isset($field['formula'])) {
                try {
                    $node = \Abdalla\ReportGenerator\Query\Formula\Parser::parse((string) $field['formula']);
                } catch (FormulaException $e) {
                    throw new QueryException('INVALID_FORMULA', $e->getMessage(), "{$at}.formula");
                }
                $clean[] = ['formula' => (string) $field['formula'], 'node' => $node, 'as' => $as, 'label' => $label, 'at' => "{$at}.formula"];
            } else {
                throw new QueryException('INVALID_FIELD', 'A column is a base column, a report column or a formula.', $at);
            }
        }
        foreach ($clean as $field) {
            if (isset($field['node'])) {
                foreach ($field['node']->references() as $ref) {
                    if (! isset($aliases[$ref])) {
                        throw new QueryException('UNKNOWN_FIELD', "The formula uses \"{$ref}\", which is not a column of this report.", $field['at']);
                    }
                }
            }
        }

        $sort = [];
        foreach ((array) ($definition['sort'] ?? []) as $s) {
            if (is_array($s) && isset($aliases[$s['field'] ?? '']) && in_array($s['dir'] ?? 'asc', ['asc', 'desc'], true)) {
                $sort[] = ['field' => $s['field'], 'dir' => $s['dir'] ?? 'asc'];
            }
        }

        return ['run' => $run, 'fields' => $clean, 'sort' => $sort, 'reports' => $reports];
    }

    private function run(array $definition, ?Authenticatable $user): CombineRun
    {
        $level = $definition['level'] ?? null;
        if (! in_array($level, ['employee', 'division'], true)) {
            throw new QueryException('INVALID_LEVEL', 'A combined report has one row per employee or per division.', 'level');
        }

        $context = (array) ($definition['context'] ?? []);
        $period = $context['period'] ?? 'this_month';
        [$from, $to] = match (true) {
            is_array($period) && count($period) === 2 => [substr((string) $period[0], 0, 10).' 00:00:00', substr((string) $period[1], 0, 10).' 23:59:59'],
            is_string($period) => DatePresets::resolve($period) ?? throw new QueryException('INVALID_PERIOD', "Unknown period \"{$period}\".", 'context.period'),
            default => throw new QueryException('INVALID_PERIOD', 'The period needs two dates [from, to] or a preset.', 'context.period'),
        };

        $ids = fn (string $k) => array_values(array_map('intval', array_filter((array) ($context[$k] ?? []), 'is_numeric')));
        $lines = $ids('lines');
        if ($lines === []) {
            // The viewer's own lines, through the configured lines topic and their row scope.
            $lines = array_map(fn ($o) => (int) $o['id'], app(QueryEngine::class)->contextOptions('lines', null, [], [$from, $to], $user, 500));
        }

        return new CombineRun($level, $from, $to, $lines, $ids('divisions'), $ids('employees'), $user);
    }

    private function report(string $key, ?Authenticatable $user, string $at): CombinableReport
    {
        $report = $this->reports->get($key) ?? throw new QueryException('UNKNOWN_REPORT', "There is no report \"{$key}\" to combine.", $at);
        if (! $this->allowed($report, $user)) {
            throw new QueryException('DATASET_FORBIDDEN', "You do not have access to {$report->label()}.", $at, status: 403);
        }

        return $report;
    }

    private function allowed(CombinableReport $report, ?Authenticatable $user): bool
    {
        return $report->permission() === null
            || $this->generator->allows($user, Ability::ViewAllReports)
            || $this->generator->allows($user, $report->permission());
    }

    /** @return list<array<string, mixed>> rows of one report for a run, cached briefly */
    private function rowsOf(CombinableReport $report, CombineRun $run): array
    {
        $key = 'report-generator:combine-rows:'.hash('sha256', $report->key().'|'.$run->key().'|'.$this->policyVersion());

        return $this->cache->remember($key, (int) config('report-generator.cache.ttl', 600), function () use ($report, $run) {
            $rows = [];
            foreach ($report->rows($run) as $row) {
                // Restrict to the picked divisions / employees when the report does not.
                if ($run->byEmployee() ? ($run->employees !== [] && ! in_array((int) $row['id'], $run->employees, true))
                    : ($run->divisions !== [] && ! in_array((int) $row['id'], $run->divisions, true))) {
                    continue;
                }
                $rows[] = $row;
            }

            return $rows;
        });
    }

    /**
     * Column descriptions from declared fields, or guessed from sample rows.
     *
     * @return list<array{key: string, label: string, type: string, total: ?string}>
     */
    private function describe(?array $declared, array $sample, ?CombinableReport $report = null): array
    {
        if ($declared !== null) {
            $out = [];
            foreach ($declared as $key => $meta) {
                $type = $meta['type'] ?? 'decimal';
                $out[] = ['key' => (string) $key, 'label' => $meta['label'] ?? Str::headline((string) $key), 'type' => $type,
                    'total' => array_key_exists('total', $meta) ? $meta['total'] : $this->defaultTotal((string) $key, $type)];
            }

            return $out;
        }

        $keys = [];
        foreach ($sample as $row) {
            foreach ($row as $key => $value) {
                if ($key === 'id' || isset(self::BASE[$key])) {
                    continue;
                }
                $keys[$key][] = $value;
            }
        }

        $out = [];
        foreach ($keys as $key => $values) {
            $filled = array_filter($values, fn ($v) => $v !== null && $v !== '');
            $type = match (true) {
                $filled === [] => 'decimal',
                array_filter($filled, fn ($v) => ! is_numeric($v) && ! (is_string($v) && preg_match('/^-?[\d.,]+\s*%$/', $v))) !== [] => 'string',
                array_filter($filled, fn ($v) => is_string($v) && str_ends_with(trim($v), '%')) !== [] => 'percent',
                array_filter($filled, fn ($v) => ! preg_match('/^-?\d+$/', (string) $v)) === [] => 'integer',
                default => 'decimal',
            };
            if (is_array(reset($filled)) || is_object(reset($filled))) {
                continue;
            }
            $out[] = ['key' => (string) $key, 'label' => Str::headline((string) $key), 'type' => $type, 'total' => $this->defaultTotal((string) $key, $type)];
        }

        return $out;
    }

    private function defaultTotal(string $key, string $type): ?string
    {
        if (! in_array($type, ['integer', 'decimal'], true)) {
            return null;
        }

        // Averages and rates do not add up across employees.
        return preg_match('/avg|average|rate|ratio|percent|achievement|per_|_per|%/i', $key) ? null : 'sum';
    }

    private function column(array $field, array $meta): array
    {
        if (isset($field['base'])) {
            return ['alias' => $field['as'], 'label' => $field['label'] ?? self::BASE[$field['base']], 'type' => 'string', 'kind' => 'dimension', 'format' => null, 'dataset' => null, 'total' => null, 'source_type' => 'string'];
        }
        if (isset($field['formula'])) {
            return ['alias' => $field['as'], 'label' => $field['label'] ?? Str::headline($field['as']), 'type' => 'decimal', 'kind' => 'measure', 'format' => '#,##0.00', 'dataset' => null, 'total' => null, 'source_type' => 'decimal'];
        }

        $m = $meta[$field['report']][$field['field']] ?? ['label' => Str::headline($field['field']), 'type' => 'decimal', 'total' => null];
        $label = $field['label'] ?? (($this->reports->get($field['report'])?->label() ?? $field['report']).': '.$m['label']);

        return [
            'alias' => $field['as'],
            'label' => $label,
            'type' => $m['type'] === 'percent' ? 'decimal' : $m['type'],
            'kind' => $m['type'] === 'string' ? 'dimension' : 'measure',
            'format' => $m['type'] === 'percent' ? '0.00%' : null,
            'dataset' => $field['report'],
            'total' => $m['total'],
            'source_type' => $m['type'],
        ];
    }

    private function value(mixed $value, string $type): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($type === 'percent') {
            $number = (float) str_replace([',', '%', ' '], '', (string) $value);

            return $number / 100;
        }
        if ($type === 'integer') {
            return is_numeric($value) ? (int) $value : null;
        }
        if ($type === 'decimal') {
            return is_numeric($value) ? (float) $value : null;
        }

        return is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    private function formula(array $field, array $row): mixed
    {
        try {
            $value = (new Evaluator(fn (string $ref) => $row[$ref] ?? null))->evaluate($field['node']);
        } catch (FormulaException) {
            return null;
        }

        return is_float($value) && (is_nan($value) || is_infinite($value)) ? null : $value;
    }

    /** @return list<array<string, mixed>> */
    private function sort(array $rows, array $sort, array $fields): array
    {
        if ($sort === []) {
            $first = $fields[0]['as'] ?? null;
            $sort = $first ? [['field' => $first, 'dir' => 'asc']] : [];
        }
        usort($rows, function ($a, $b) use ($sort) {
            foreach ($sort as $s) {
                $x = $a[$s['field']] ?? null;
                $y = $b[$s['field']] ?? null;
                if ($x === $y) {
                    continue;
                }
                // Empty values last, whatever the direction.
                if ($x === null) {
                    return 1;
                }
                if ($y === null) {
                    return -1;
                }
                $cmp = is_numeric($x) && is_numeric($y) ? $x <=> $y : strnatcasecmp((string) $x, (string) $y);
                if ($cmp !== 0) {
                    return $s['dir'] === 'desc' ? -$cmp : $cmp;
                }
            }

            return 0;
        });

        return $rows;
    }

    /** Part of cache keys: row rules may differ per user (the user is in the run key). */
    private function policyVersion(): string
    {
        return (string) config('report-generator.cache.version', '1');
    }
}
