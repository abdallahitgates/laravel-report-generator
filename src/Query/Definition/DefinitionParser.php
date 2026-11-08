<?php

namespace Abdalla\ReportGenerator\Query\Definition;

use Abdalla\ReportGenerator\Enums\Aggregation;
use Abdalla\ReportGenerator\Query\QueryException;
use Carbon\CarbonImmutable;

/**
 * Turns the JSON a client sends into a ReportDefinition, checking its shape
 * and substituting parameters. Whether fields exist and joins are possible
 * is checked later against the published model, by the planner.
 *
 * {
 *   "version": 1,
 *   "root": "visits",
 *   "parameters": [{"key": "period", "type": "date_range", "default": "this_quarter"}],
 *   "fields": [
 *     {"ref": "lines.name", "as": "line"},
 *     {"ref": "visits.visit_date", "grain": "month", "as": "month"},
 *     {"ref": "visits.id", "agg": "count", "as": "visits"},
 *     {"formula": "visits / doctors", "as": "per_doctor"}
 *   ],
 *   "filters": {"and": [{"field": "visits.visit_date", "op": "between", "value": "{{period}}"}]},
 *   "sort": [{"field": "visits", "dir": "desc"}],
 *   "limit": 1000,
 *   "subtotals": false
 * }
 */
final class DefinitionParser
{
    public const VERSION = 1;
    public const GRAINS = ['day', 'week', 'month', 'quarter', 'year', 'hour', 'month_of_year', 'day_of_week', 'hour_of_day'];
    private const IDENTIFIER = '/^[a-z_][a-z0-9_]{0,63}$/i';
    private const MAX_FIELDS = 100;
    private const MAX_CONDITIONS = 100;
    private const MAX_DEPTH = 5;
    private const MAX_IN_VALUES = 1000;

    private int $conditionCount = 0;
    /** @var array<string, mixed> */
    private array $parameters = [];
    private CarbonImmutable $now;

    /**
     * @param  array<string, mixed>  $runtimeParameters  values chosen by the user for this run
     */
    public function parse(array $data, array $runtimeParameters = [], ?CarbonImmutable $now = null): ReportDefinition
    {
        $this->conditionCount = 0;
        // Through Laravel's clock, so the app timezone and test time apply on every Carbon version.
        $this->now = $now ?? CarbonImmutable::instance(now());

        $version = $data['version'] ?? self::VERSION;
        if ($version !== self::VERSION) {
            $this->fail('UNSUPPORTED_VERSION', "This report uses definition version {$version}; this server understands version ".self::VERSION.'.', 'version');
        }

        $root = $data['root'] ?? null;
        if (! is_string($root) || ! preg_match(self::IDENTIFIER, $root)) {
            $this->fail('INVALID_ROOT', 'Choose the dataset the report is about (root).', 'root');
        }

        $this->parameters = $this->parameters($data['parameters'] ?? [], $runtimeParameters);
        $fields = $this->fields($data['fields'] ?? null);
        $aliases = array_flip(array_map(fn (FieldSpec $f) => $f->alias, $fields));

        $filters = null;
        if (isset($data['filters'])) {
            $filters = $this->group($data['filters'], 'filters', $aliases, 1);
            if ($filters->children === []) {
                $filters = null;
            }
        }

        return new ReportDefinition(
            root: $root,
            fields: $fields,
            filters: $filters,
            sort: $this->sort($data['sort'] ?? [], $aliases),
            limit: $this->limit($data['limit'] ?? null),
            subtotals: (bool) ($data['subtotals'] ?? false),
            context: $this->context($data['context'] ?? []),
        );
    }

    /**
     * The fixed report filters: period, lines, divisions, employees.
     */
    private function context(mixed $context): ReportContext
    {
        if (! is_array($context)) {
            $this->fail('INVALID_CONTEXT', 'The report filters (period, lines, divisions, employees) must be an object.', 'context');
        }

        $from = $to = null;
        $period = $this->substitute($context['period'] ?? null);
        if (is_string($period) && $period !== '') {
            [$from, $to] = DatePresets::resolve($period, $this->now)
                ?? $this->fail('INVALID_PERIOD', "Unknown period \"{$period}\". Use a preset such as this_month, or [from, to].", 'context.period');
        } elseif (is_array($period) && $period !== []) {
            if (count($period) !== 2 || ! is_string($period[0] ?? null) || ! is_string($period[1] ?? null)
                || strtotime($period[0]) === false || strtotime($period[1]) === false) {
                $this->fail('INVALID_PERIOD', 'The period needs two dates [from, to].', 'context.period');
            }
            [$from, $to] = [$period[0], $period[1]];
        }

        $ids = function (string $key) use ($context): array {
            $value = $this->substitute($context[$key] ?? []);
            if ($value === null || $value === '') {
                return [];
            }
            $value = is_array($value) ? $value : [$value];
            if (count($value) > self::MAX_IN_VALUES) {
                $this->fail('INVALID_CONTEXT', 'At most '.self::MAX_IN_VALUES." {$key} can be selected.", "context.{$key}");
            }
            foreach ($value as $id) {
                if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
                    $this->fail('INVALID_CONTEXT', "Pick {$key} from the list.", "context.{$key}");
                }
            }

            return array_values(array_unique(array_map('intval', $value)));
        };

        return new ReportContext($from, $to, $ids('lines'), $ids('divisions'), $ids('employees'));
    }

    /** @return list<FieldSpec> */
    private function fields(mixed $fields): array
    {
        if (! is_array($fields) || $fields === [] || ! array_is_list($fields)) {
            $this->fail('NO_FIELDS', 'Add at least one field to the report.', 'fields');
        }
        if (count($fields) > self::MAX_FIELDS) {
            $this->fail('TOO_MANY_FIELDS', 'A report can have at most '.self::MAX_FIELDS.' fields.', 'fields');
        }

        $specs = [];
        $seen = [];
        foreach ($fields as $i => $field) {
            $at = "fields.{$i}";
            if (! is_array($field)) {
                $this->fail('INVALID_FIELD', 'Each field must be an object.', $at);
            }

            $alias = $field['as'] ?? null;
            if (! is_string($alias) || ! preg_match(self::IDENTIFIER, $alias)) {
                $this->fail('INVALID_ALIAS', 'Each field needs a name ("as"): letters, digits and underscores, starting with a letter.', "{$at}.as");
            }
            if (isset($seen[$alias])) {
                $this->fail('DUPLICATE_ALIAS', "Two fields are named \"{$alias}\". Give each field its own name.", "{$at}.as");
            }
            $seen[$alias] = true;

            $label = isset($field['label']) ? (string) $field['label'] : null;
            $format = isset($field['format']) ? (string) $field['format'] : null;

            if (isset($field['formula'])) {
                if (! is_string($field['formula']) || trim($field['formula']) === '' || strlen($field['formula']) > 2000) {
                    $this->fail('INVALID_FORMULA', 'A formula must be text of at most 2000 characters.', "{$at}.formula");
                }
                $specs[] = new FieldSpec($alias, formula: $field['formula'], label: $label, format: $format);
                continue;
            }

            [$dataset, $key] = $this->ref($field['ref'] ?? null, "{$at}.ref");

            $aggregation = null;
            if (isset($field['agg'])) {
                $aggregation = Aggregation::tryFrom((string) $field['agg'])
                    ?? $this->fail('INVALID_AGGREGATION', 'Unknown aggregation "'.$field['agg'].'". Use one of: '.implode(', ', array_column(Aggregation::cases(), 'value')).'.', "{$at}.agg");
            }

            $grain = $field['grain'] ?? null;
            if ($grain !== null && ! in_array($grain, self::GRAINS, true)) {
                $this->fail('INVALID_GRAIN', 'Unknown date grouping "'.$grain.'". Use one of: '.implode(', ', self::GRAINS).'.', "{$at}.grain");
            }

            $specs[] = new FieldSpec(
                alias: $alias,
                dataset: $dataset,
                field: $key,
                aggregation: $aggregation,
                grain: $grain,
                path: $this->path($field['path'] ?? null, "{$at}.path"),
                label: $label,
                format: $format,
            );
        }

        return $specs;
    }

    /** @param  array<string, int>  $aliases */
    private function group(mixed $node, string $at, array $aliases, int $depth): FilterGroup
    {
        if ($depth > self::MAX_DEPTH) {
            $this->fail('FILTERS_TOO_DEEP', 'Filters can be nested at most '.self::MAX_DEPTH.' levels deep.', $at);
        }
        if (! is_array($node) || count($node) !== 1 || ! in_array(array_key_first($node), ['and', 'or'], true) || ! is_array(reset($node))) {
            $this->fail('INVALID_FILTER_GROUP', 'A filter group must look like {"and": [...]} or {"or": [...]}.', $at);
        }

        $boolean = array_key_first($node);
        $children = [];
        foreach (array_values($node[$boolean]) as $i => $child) {
            $childAt = "{$at}.{$boolean}.{$i}";
            $children[] = is_array($child) && (isset($child['and']) || isset($child['or']))
                ? $this->group($child, $childAt, $aliases, $depth + 1)
                : $this->condition($child, $childAt, $aliases);
        }

        return new FilterGroup($boolean, $children);
    }

    /** @param  array<string, int>  $aliases */
    private function condition(mixed $node, string $at, array $aliases): FilterCondition
    {
        if (++$this->conditionCount > self::MAX_CONDITIONS) {
            $this->fail('TOO_MANY_FILTERS', 'A report can have at most '.self::MAX_CONDITIONS.' filter conditions.', $at);
        }
        if (! is_array($node)) {
            $this->fail('INVALID_FILTER', 'Each filter must be an object with "field", "op" and "value".', $at);
        }

        $operator = $node['op'] ?? null;
        if (! in_array($operator, FilterCondition::OPERATORS, true)) {
            $this->fail('INVALID_OPERATOR', 'Unknown filter operator. Use one of: '.implode(', ', FilterCondition::OPERATORS).'.', "{$at}.op");
        }

        $target = $node['field'] ?? null;
        $dataset = $field = $alias = null;
        if (is_string($target) && ! str_contains($target, '.')) {
            if (! isset($aliases[$target])) {
                $this->fail('UNKNOWN_ALIAS', "The filter uses \"{$target}\", which is not one of the report's fields.", "{$at}.field");
            }
            $alias = $target;
        } else {
            [$dataset, $field] = $this->ref($target, "{$at}.field");
        }

        $value = $this->value($operator, $this->substitute($node['value'] ?? null), "{$at}.value");

        return new FilterCondition($operator, $value, $dataset, $field, $alias, $this->path($node['path'] ?? null, "{$at}.path"), $at);
    }

    private function value(string $operator, mixed $value, string $at): mixed
    {
        switch ($operator) {
            case 'is_null':
            case 'not_null':
                return null;
            case 'in':
            case 'not_in':
                if (! is_array($value) || $value === [] || ! array_is_list($value)) {
                    $this->fail('INVALID_VALUE', 'Pick at least one value.', $at);
                }
                if (count($value) > self::MAX_IN_VALUES) {
                    $this->fail('INVALID_VALUE', 'A list filter can hold at most '.self::MAX_IN_VALUES.' values.', $at);
                }
                foreach ($value as $item) {
                    $this->assertScalar($item, $at);
                }

                return array_values(array_unique($value, SORT_REGULAR));
            case 'between':
                if (is_string($value) && ($range = DatePresets::resolve($value, $this->now))) {
                    return $range;
                }
                if (! is_array($value) || count($value) !== 2 || ! array_is_list($value)) {
                    $this->fail('INVALID_VALUE', 'A "between" filter needs two values [from, to] or a date preset such as "this_month".', $at);
                }
                $this->assertScalar($value[0], $at);
                $this->assertScalar($value[1], $at);

                return $value;
            case 'contains':
            case 'not_contains':
            case 'starts_with':
            case 'ends_with':
                if (! is_string($value) || $value === '') {
                    $this->fail('INVALID_VALUE', 'Type the text to search for.', $at);
                }

                return $value;
            default:
                if ($value === null) {
                    $this->fail('INVALID_VALUE', 'This filter needs a value. To match empty values use "is_null".', $at);
                }
                $this->assertScalar($value, $at);

                return $value;
        }
    }

    /** "{{name}}" anywhere a value is expected is replaced by the parameter's value. */
    private function substitute(mixed $value): mixed
    {
        if (is_string($value) && preg_match('/^\{\{\s*([a-z_][a-z0-9_]*)\s*\}\}$/i', $value, $m)) {
            if (! array_key_exists($m[1], $this->parameters)) {
                $this->fail('UNKNOWN_PARAMETER', "The report uses parameter \"{$m[1]}\" but does not define it.", 'parameters');
            }

            return $this->parameters[$m[1]];
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $runtime
     * @return array<string, mixed>
     */
    private function parameters(mixed $definitions, array $runtime): array
    {
        if (! is_array($definitions)) {
            $this->fail('INVALID_PARAMETERS', 'Parameters must be a list.', 'parameters');
        }

        $values = [];
        foreach (array_values($definitions) as $i => $parameter) {
            $key = is_array($parameter) ? ($parameter['key'] ?? null) : null;
            if (! is_string($key) || ! preg_match(self::IDENTIFIER, $key)) {
                $this->fail('INVALID_PARAMETER', 'Each parameter needs a key.', "parameters.{$i}.key");
            }

            $value = array_key_exists($key, $runtime) ? $runtime[$key] : ($parameter['default'] ?? null);
            if (($value === null || $value === '' || $value === []) && ($parameter['required'] ?? false)) {
                $label = $parameter['label'] ?? $key;
                $this->fail('MISSING_PARAMETER', "Choose a value for \"{$label}\" to run this report.", "parameters.{$i}", ['parameter' => $key]);
            }
            $values[$key] = $value;
        }

        return $values;
    }

    /** @param  array<string, int>  $aliases */
    private function sort(mixed $sort, array $aliases): array
    {
        if (! is_array($sort)) {
            $this->fail('INVALID_SORT', 'Sort must be a list.', 'sort');
        }

        $specs = [];
        foreach (array_values($sort) as $i => $item) {
            $alias = is_array($item) ? ($item['field'] ?? null) : null;
            if (! is_string($alias) || ! isset($aliases[$alias])) {
                $this->fail('INVALID_SORT', 'You can only sort by one of the report\'s fields.', "sort.{$i}.field");
            }
            $direction = strtolower((string) ($item['dir'] ?? 'asc'));
            if (! in_array($direction, ['asc', 'desc'], true)) {
                $this->fail('INVALID_SORT', 'Sort direction must be "asc" or "desc".', "sort.{$i}.dir");
            }
            $specs[] = new SortSpec($alias, $direction === 'desc');
        }

        return $specs;
    }

    private function limit(mixed $limit): ?int
    {
        if ($limit === null) {
            return null;
        }
        if (! is_int($limit) || $limit < 1 || $limit > 10_000_000) {
            $this->fail('INVALID_LIMIT', 'Limit must be a whole number between 1 and 10,000,000.', 'limit');
        }

        return $limit;
    }

    /** @return array{0: string, 1: string} */
    private function ref(mixed $ref, string $at): array
    {
        if (! is_string($ref) || ! preg_match('/^([a-z_][a-z0-9_]{0,127})\.([a-z_][a-z0-9_]{0,127})$/i', $ref, $m)) {
            $this->fail('INVALID_REF', 'Refer to fields as "dataset.field", for example "visits.visit_date".', $at);
        }

        return [$m[1], $m[2]];
    }

    /** @return list<int>|null */
    private function path(mixed $path, string $at): ?array
    {
        if ($path === null) {
            return null;
        }
        if (! is_array($path) || ! array_is_list($path) || count($path) > 8) {
            $this->fail('INVALID_PATH', 'A path is a list of up to 8 relationship ids (negative to walk a relationship backwards).', $at);
        }
        foreach ($path as $id) {
            if (! is_int($id) || $id === 0) {
                $this->fail('INVALID_PATH', 'A path is a list of up to 8 relationship ids (negative to walk a relationship backwards).', $at);
            }
        }

        return $path;
    }

    private function assertScalar(mixed $value, string $at): void
    {
        if (! is_scalar($value) || (is_string($value) && strlen($value) > 1000)) {
            $this->fail('INVALID_VALUE', 'Filter values must be text, numbers or true/false (at most 1000 characters).', $at);
        }
    }

    private function fail(string $code, string $message, string $path, array $details = []): never
    {
        throw new QueryException($code, $message, $path, $details);
    }
}
