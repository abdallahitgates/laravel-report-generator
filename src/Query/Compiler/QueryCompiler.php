<?php

namespace Abdalla\ReportGenerator\Query\Compiler;

use Abdalla\ReportGenerator\Enums\Aggregation;
use Abdalla\ReportGenerator\Enums\DataType;
use Abdalla\ReportGenerator\Query\Definition\FilterCondition;
use Abdalla\ReportGenerator\Query\Definition\FilterGroup;
use Abdalla\ReportGenerator\Query\Formula\FormulaException;
use Abdalla\ReportGenerator\Query\Formula\Transcriber;
use Abdalla\ReportGenerator\Query\Planning\JoinNode;
use Abdalla\ReportGenerator\Query\Planning\PlannedCondition;
use Abdalla\ReportGenerator\Query\Planning\PlannedField;
use Abdalla\ReportGenerator\Query\Planning\QueryPlan;
use Abdalla\ReportGenerator\Query\QueryException;
use Abdalla\ReportGenerator\Security\PolicyContext;
use Abdalla\ReportGenerator\Semantic\Published\PublishedDataset;
use Abdalla\ReportGenerator\Security\ScopeContext;
use Abdalla\ReportGenerator\Security\PolicyRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\JoinClause;

/**
 * Turns a plan into one SQL statement.
 *
 * - No measures: a plain SELECT (detail rows).
 * - Measures whose rows no join repeats: one SELECT ... GROUP BY.
 * - Measures that a one-to-many join would count twice: each measure's
 *   branch is aggregated in its own SELECT, the branches are stacked with
 *   UNION ALL and combined per group. This is what keeps "visits per
 *   doctor" and "sales per doctor" correct in the same report.
 *
 * A filter on a dataset that is reached through a one-to-many join and is
 * not otherwise part of the query becomes EXISTS (...), so filtering never
 * multiplies rows either.
 *
 * Every identifier comes from the published model and is quoted by the
 * connection's grammar; every value is a binding.
 */
final class QueryCompiler
{
    /** Aggregations that give a wrong answer when the same row is counted twice. */
    private const DUPLICATE_SENSITIVE = [Aggregation::Sum, Aggregation::Avg, Aggregation::Count];
    private const GRAIN_RANGES = ['day', 'week', 'month', 'quarter', 'year'];

    private QueryPlan $plan;
    private Connection $connection;
    private Grammar $grammar;
    private Dialect $dialect;
    private ?Authenticatable $user;
    private int $timeoutMs;
    private bool $viewAll = false;

    public function __construct(private readonly PolicyRegistry $policies)
    {
    }

    public function compile(QueryPlan $plan, Connection $connection, Dialect $dialect, ?Authenticatable $user = null, int $timeoutMs = 0, bool $viewAll = false): CompiledQuery
    {
        $this->viewAll = $viewAll;
        $this->plan = $plan;
        $this->connection = $connection;
        $this->grammar = $connection->getQueryGrammar();
        $this->dialect = $dialect;
        $this->user = $user;
        $this->timeoutMs = $timeoutMs;

        if ($plan->definition->subtotals && ! $dialect->supportsRollup()) {
            throw new QueryException('SUBTOTALS_UNSUPPORTED', 'This database does not support subtotals.', 'subtotals');
        }

        if (! $plan->isAggregated()) {
            [$query, $mode] = [$this->single(false), CompiledQuery::DETAIL];
        } else {
            [$tree] = $this->prepareTree($this->columnNodes($plan->dimensions()) + $this->columnNodes($plan->measures()));
            $repeated = array_filter($plan->measures(), fn (PlannedField $m) => $this->sensitive($m) && $tree->duplicates($m->node));
            [$query, $mode] = $repeated === []
                ? [$this->single(true), CompiledQuery::AGGREGATE]
                : [$this->multiFact(), CompiledQuery::MULTI_FACT];
        }

        return new CompiledQuery($query, $this->columns(), $mode, $dialect, $connection, $plan->datasets(), $this->filteredFields());
    }

    // ---------------------------------------------------------------- single

    private function single(bool $aggregated): Builder
    {
        $plan = $this->plan;
        [$tree, $exists] = $this->prepareTree($this->columnNodes(array_values($plan->fields)));
        $query = $this->base($tree);

        $exprs = [];
        foreach ($plan->fields as $alias => $field) {
            if ($field->kind === PlannedField::DIMENSION) {
                $exprs[$alias] = $this->dimensionSql($field);
            } elseif ($field->kind === PlannedField::MEASURE) {
                $exprs[$alias] = $this->measureSql($field, $tree);
            }
        }
        $exprs = $this->withFormulas($exprs);

        $this->select($query, $exprs);

        $groupBy = array_map(fn (PlannedField $f) => $exprs[$f->alias()], $plan->dimensions());
        if ($aggregated && $groupBy !== []) {
            if ($plan->definition->subtotals) {
                $query->groupByRaw($this->dialect->rollupGroupBy($groupBy));
                $query->selectRaw('('.implode(' + ', array_map(fn ($e) => "GROUPING({$e})", $groupBy)).') as '.$this->grammar->wrap('__subtotal'));
            } else {
                $query->groupByRaw(implode(', ', $groupBy));
            }
        }

        $this->applyFilters($query, $tree, $exists, $exprs, true);
        $this->order($query, $tree, $aggregated);

        return $query;
    }

    // ------------------------------------------------------------ multi-fact

    private function multiFact(): Builder
    {
        $plan = $this->plan;

        if ($plan->definition->subtotals) {
            throw new QueryException('SUBTOTALS_UNSUPPORTED', 'Subtotals are not available when a report combines totals from several one-to-many branches. Remove the subtotals or one of the totals.', 'subtotals');
        }

        $columnDims = array_values(array_filter($plan->dimensions(), fn (PlannedField $f) => $f->kind === PlannedField::DIMENSION));
        $measures = $plan->measures();

        $groups = [];
        foreach ($measures as $measure) {
            $groups[$measure->node->key][] = $measure;
        }

        $branches = [];
        foreach ($groups as $members) {
            [$tree, $exists] = $this->prepareTree($this->columnNodes([...$columnDims, ...$members]));

            foreach ($members as $measure) {
                if ($this->sensitive($measure) && ($by = $tree->duplicatedBy($measure->node))) {
                    $this->fanTrap($measure, $by, $columnDims);
                }
            }

            $query = $this->base($tree);
            $exprs = [];
            foreach ($columnDims as $dim) {
                $exprs[$dim->alias()] = $this->dimensionSql($dim);
                $query->selectRaw($exprs[$dim->alias()].' as '.$this->grammar->wrap($dim->alias()));
            }
            foreach ($measures as $measure) {
                $sql = in_array($measure, $members, true) ? $this->measureSql($measure, $tree) : 'NULL';
                $query->selectRaw($sql.' as '.$this->grammar->wrap($measure->alias()));
            }
            if ($columnDims !== []) {
                $query->groupByRaw(implode(', ', array_map(fn (PlannedField $d) => $exprs[$d->alias()], $columnDims)));
            }

            // Row filters belong in every branch; filters on totals are applied once, outside.
            $this->applyFilters($query, $tree, $exists, $exprs, false);
            $branches[] = $query;
        }

        $union = array_shift($branches);
        foreach ($branches as $branch) {
            $union->unionAll($branch);
        }

        $outer = $this->connection->query()->fromSub($union, 'u');
        $exprs = [];
        foreach ($columnDims as $dim) {
            $exprs[$dim->alias()] = $this->grammar->wrap('u.'.$dim->alias());
        }
        foreach ($measures as $measure) {
            // Each branch yields exactly one row per group, so MAX just picks that value.
            $exprs[$measure->alias()] = 'MAX('.$this->grammar->wrap('u.'.$measure->alias()).')';
        }
        $exprs = $this->withFormulas($exprs);

        $this->select($outer, $exprs);
        $groupBy = array_map(fn (PlannedField $f) => $exprs[$f->alias()], $plan->dimensions());
        if ($groupBy !== []) {
            $outer->groupByRaw(implode(', ', $groupBy));
        }

        [, $having] = $this->filterFragments(
            fn (PlannedCondition $c) => $this->predicate($exprs[$c->target->alias()], $c->condition, $c->target->type),
            where: false,
            having: true,
        );
        if ($having) {
            $outer->havingRaw($having->sql, $having->bindings);
        }
        $this->order($outer, null, true);

        return $outer;
    }

    /** @param  list<PlannedField>  $dims */
    private function fanTrap(PlannedField $measure, JoinNode $by, array $dims): never
    {
        $culprits = array_values(array_filter($dims, fn (PlannedField $d) => $by->contains($d->node)));
        $with = $culprits ? implode(', ', array_map(fn (PlannedField $d) => "\"{$d->label}\"", $culprits)) : 'a filter';
        $parent = $by->parent?->dataset->label ?? '';

        throw new QueryException(
            'FAN_TRAP',
            "\"{$measure->label}\" cannot be combined with {$with}: each {$parent} has many {$by->dataset->label}, so {$measure->label} would be counted more than once. Remove one of them, use a distinct count, or start the report from {$by->dataset->label}.",
            'fields',
            ['measure' => $measure->alias(), 'fields' => array_map(fn (PlannedField $d) => $d->alias(), $culprits)],
        );
    }

    // ------------------------------------------------------------------ tree

    /**
     * Adds the nodes needed by the selected fields and decides, per filter,
     * whether it joins inline or runs as EXISTS.
     *
     * @param  array<string, JoinNode>  $nodes
     * @return array{0: QueryTree, 1: array<int, JoinNode>}  tree, and anchor node per EXISTS condition
     */
    private function prepareTree(array $nodes): array
    {
        $tree = new QueryTree($this->plan->joins->root());
        foreach ($nodes as $node) {
            $tree->add($node);
        }

        $exists = [];
        foreach ($this->plan->conditions as $id => $condition) {
            if ($condition->node === null) {
                continue;
            }

            $lineage = $condition->node->lineage();
            $anchor = 0;
            foreach ($lineage as $i => $node) {
                if ($tree->has($node)) {
                    $anchor = $i;
                }
            }

            $remainder = array_slice($lineage, $anchor + 1);
            $fansOut = array_filter($remainder, fn (JoinNode $n) => $n->fansOut()) !== [];
            if ($fansOut) {
                $exists[$id] = $lineage[$anchor];
            } else {
                $tree->add($condition->node);
            }
        }

        return [$tree, $exists];
    }

    private function base(QueryTree $tree): Builder
    {
        $root = $tree->root();
        $query = $this->connection->query()->from($this->tableRef($root));

        $root->dataset->constrain($query, $root->alias);

        foreach ($tree->nodes() as $node) {
            if ($node->isRoot()) {
                continue;
            }
            $this->join($query, $node, $node->step->relationship->joinType === 'inner' ? 'join' : 'leftJoin');
        }

        foreach ($tree->nodes() as $node) {
            $this->secure($query, $node);
        }

        $this->applyContext($query, $root);

        return $query;
    }

    /** Policies registered for the dataset, plus the row scope for employee/division topics. */
    private function secure(Builder $query, JoinNode $node): void
    {
        $this->policies->apply($query, new PolicyContext($node->dataset->key, $node->alias, $this->user));

        if (! $this->viewAll) {
            $context = $this->plan->definition->context;
            $this->policies->applyScope($query, new ScopeContext($node->dataset, $node->alias, $this->user, $context->from, $context->to, $context->lines));
        }
    }

    /**
     * The period as the column compares it: date columns by day; a date-time
     * column with a day-only "to" includes that whole day.
     *
     * @return array{0: string, 1: string}
     */
    private function contextBounds(PublishedDataset $dataset, string $column, string $from, string $to): array
    {
        $type = $dataset->field($column)?->type ?? DataType::Date;

        return $type === DataType::Date
            ? [substr($from, 0, 10), substr($to, 0, 10)]
            : [$from, strlen($to) === 10 ? $to.' 23:59:59' : $to];
    }

    /** The fixed report filters, through the columns the main topic declares. */
    private function applyContext(Builder $query, JoinNode $root): void
    {
        $context = $this->plan->definition->context;
        $dataset = $root->dataset;

        if ($context->from !== null && $dataset->validFromColumn) {
            // Valid at any time in the period: overlapping spans, open-ended "to".
            [$from, $to] = $this->contextBounds($dataset, $dataset->validFromColumn, $context->from, $context->to);
            $query->where("{$root->alias}.{$dataset->validFromColumn}", '<=', $to);
            if ($dataset->validToColumn) {
                $toColumn = "{$root->alias}.{$dataset->validToColumn}";
                [$from] = $this->contextBounds($dataset, $dataset->validToColumn, $context->from, $context->to);
                $query->where(fn (Builder $q) => $q->whereNull($toColumn)->orWhere($toColumn, '>=', $from));
            }
        } elseif ($context->from !== null && $dataset->dateColumn) {
            [$from, $to] = $this->contextBounds($dataset, $dataset->dateColumn, $context->from, $context->to);
            $query->whereBetween("{$root->alias}.{$dataset->dateColumn}", [$from, $to]);
        }
        foreach ([
            [$context->lines, $dataset->lineColumn],
            [$context->divisions, $dataset->divisionColumn],
            [$context->employees, $dataset->employeeColumn],
        ] as [$ids, $column]) {
            if ($ids !== [] && $column) {
                $query->whereIn("{$root->alias}.{$column}", $ids);
            }
        }
    }

    private function join(Builder $query, JoinNode $node, string $method): void
    {
        $parent = $node->parent;
        $step = $node->step;
        $query->{$method}($this->tableRef($node), function (JoinClause $join) use ($parent, $node, $step) {
            $join->on("{$parent->alias}.{$step->sourceColumn()}", '=', "{$node->alias}.{$step->targetColumn()}");
            // Deleted or excluded lookup rows disappear from the join without hiding the rows that point at them.
            $node->dataset->constrain($join, $node->alias);
        });
    }

    private function tableRef(JoinNode $node): string
    {
        return "{$node->dataset->sourceName} as {$node->alias}";
    }

    /**
     * @param  list<PlannedField>  $fields
     * @return array<string, JoinNode>
     */
    private function columnNodes(array $fields): array
    {
        $nodes = [];
        foreach ($fields as $field) {
            if ($field->node) {
                $nodes[$field->node->key] = $field->node;
            }
        }

        return $nodes;
    }

    // ----------------------------------------------------------- expressions

    private function column(JoinNode $node, string $column): string
    {
        return $this->grammar->wrap("{$node->alias}.{$column}");
    }

    private function dimensionSql(PlannedField $field): string
    {
        $column = $this->column($field->node, $field->field->column);

        return $field->spec->grain ? $this->dialect->timeGrain($column, $field->spec->grain) : $column;
    }

    private function measureSql(PlannedField $field, QueryTree $tree): string
    {
        $node = $field->node;

        if ($field->isRowCount()) {
            $pk = $node->dataset->primaryKey;
            if ($pk === []) {
                return 'COUNT(*)';
            }
            $repeated = $tree->duplicates($node);
            if (count($pk) > 1 && $repeated) {
                throw new QueryException('NO_PRIMARY_KEY', "{$node->dataset->label} has a composite primary key, so its rows cannot be counted through a join that repeats them.", 'fields');
            }
            $column = $this->column($node, $pk[0]);

            // Rows repeated by a join are counted once each.
            return $repeated || $field->spec->aggregation === Aggregation::CountDistinct
                ? "COUNT(DISTINCT {$column})"
                : "COUNT({$column})";
        }

        $column = $this->column($node, $field->field->column);

        return match ($field->spec->aggregation) {
            Aggregation::Sum => "SUM({$column})",
            Aggregation::Avg => "AVG({$column})",
            Aggregation::Min => "MIN({$column})",
            Aggregation::Max => "MAX({$column})",
            Aggregation::Count => "COUNT({$column})",
            Aggregation::CountDistinct => "COUNT(DISTINCT {$column})",
        };
    }

    private function sensitive(PlannedField $measure): bool
    {
        return ! $measure->isRowCount() && in_array($measure->spec->aggregation, self::DUPLICATE_SENSITIVE, true);
    }

    /**
     * Adds formula expressions, resolving formulas that use other formulas.
     *
     * @param  array<string, string>  $exprs
     * @return array<string, string>
     */
    private function withFormulas(array $exprs): array
    {
        $resolve = function (string $alias) use (&$resolve, &$exprs): string {
            if (isset($exprs[$alias])) {
                return $exprs[$alias];
            }
            $field = $this->plan->fields[$alias];
            $transcriber = new Transcriber($this->dialect, $resolve);
            try {
                return $exprs[$alias] = $transcriber->sql($field->formula);
            } catch (FormulaException $e) {
                throw new QueryException('INVALID_FORMULA', "Formula \"{$alias}\": {$e->getMessage()}", 'fields');
            }
        };

        foreach ($this->plan->fields as $alias => $field) {
            if ($field->kind === PlannedField::FORMULA) {
                $resolve($alias);
            }
        }

        // Keep the author's column order.
        $ordered = [];
        foreach (array_keys($this->plan->fields) as $alias) {
            $ordered[$alias] = $exprs[$alias];
        }

        return $ordered;
    }

    /** @param  array<string, string>  $exprs */
    private function select(Builder $query, array $exprs): void
    {
        $hint = $this->dialect->timeoutHint($this->timeoutMs);
        foreach ($exprs as $alias => $sql) {
            $query->selectRaw($hint.$sql.' as '.$this->grammar->wrap($alias));
            $hint = '';
        }
    }

    private function order(Builder $query, ?QueryTree $tree, bool $aggregated): void
    {
        $sort = $this->plan->definition->sort;
        foreach ($sort as $item) {
            $query->orderByRaw($this->grammar->wrap($item->alias).($item->descending ? ' desc' : ' asc'));
        }

        // A stable order makes paging deterministic.
        if ($aggregated) {
            foreach ($this->plan->dimensions() as $dim) {
                if (! array_filter($sort, fn ($s) => $s->alias === $dim->alias())) {
                    $query->orderByRaw($this->grammar->wrap($dim->alias()).' asc');
                }
            }
        } elseif ($tree && $tree->root()->dataset->primaryKey !== []) {
            foreach ($tree->root()->dataset->primaryKey as $column) {
                $query->orderByRaw($this->column($tree->root(), $column).' asc');
            }
        }
    }

    // --------------------------------------------------------------- filters

    /**
     * @param  array<int, JoinNode>  $exists
     * @param  array<string, string>  $exprs
     */
    private function applyFilters(Builder $query, QueryTree $tree, array $exists, array $exprs, bool $withTotals): void
    {
        [$where, $having] = $this->filterFragments(function (PlannedCondition $c) use ($exists, $exprs, $withTotals) {
            if ($c->target) {
                if (! $withTotals && $c->target->kind === PlannedField::FORMULA) {
                    throw new QueryException('UNSUPPORTED_FILTER', "This report combines totals from several one-to-many branches, so it cannot filter on the formula \"{$c->target->label}\". Filter on the fields the formula uses instead.", $c->condition->location);
                }

                return $this->aliasPredicate($c, $exprs);
            }
            $id = spl_object_id($c->condition);
            if (isset($exists[$id])) {
                return $this->existsSql($c, $exists[$id]);
            }

            return $this->predicate($this->column($c->node, $c->field->column), $c->condition, $c->field->type);
        }, where: true, having: $withTotals);

        if ($where) {
            $query->whereRaw($where->sql, $where->bindings);
        }
        if ($having) {
            $query->havingRaw($having->sql, $having->bindings);
        }
    }

    /**
     * Splits the filter tree into a WHERE part (row values) and a HAVING part
     * (totals). The two can be combined with AND only.
     *
     * @param  \Closure(PlannedCondition): Fragment  $leaf
     * @return array{0: ?Fragment, 1: ?Fragment}  [where, having]
     */
    private function filterFragments(\Closure $leaf, bool $where, bool $having): array
    {
        $root = $this->plan->definition->filters;
        if ($root === null) {
            return [null, null];
        }

        $rows = [];
        $totals = [];
        foreach ($root->boolean === 'and' ? $root->children : [$root] as $child) {
            $kind = $this->kindOf($child);
            if ($kind === 'mixed') {
                throw new QueryException('MIXED_FILTER', 'Filters on totals (sums, counts, ...) and filters on row values can be combined with AND, but not with OR.', 'filters');
            }
            if ($kind === 'total' && $having) {
                $totals[] = $this->groupSql($child, $leaf);
            } elseif ($kind === 'row' && $where) {
                $rows[] = $this->groupSql($child, $leaf);
            }
        }

        return [
            $rows ? Fragment::join($rows, 'AND') : null,
            $totals ? Fragment::join($totals, 'AND') : null,
        ];
    }

    private function kindOf(FilterGroup|FilterCondition $node): string
    {
        if ($node instanceof FilterCondition) {
            return $this->plan->condition($node)->isTotal() ? 'total' : 'row';
        }

        $kinds = array_unique(array_map(fn ($child) => $this->kindOf($child), $node->children));

        return count($kinds) === 1 ? $kinds[0] : ($kinds === [] ? 'row' : 'mixed');
    }

    /** @param  \Closure(PlannedCondition): Fragment  $leaf */
    private function groupSql(FilterGroup|FilterCondition $node, \Closure $leaf): Fragment
    {
        if ($node instanceof FilterCondition) {
            return $leaf($this->plan->condition($node));
        }

        return Fragment::join(array_map(fn ($child) => $this->groupSql($child, $leaf), $node->children), strtoupper($node->boolean));
    }

    /** @param  array<string, string>  $exprs */
    private function aliasPredicate(PlannedCondition $c, array $exprs): Fragment
    {
        $target = $c->target;
        $condition = $c->condition;

        // Drilling into a month (or day, week, ...) group: compare the raw column with a range,
        // which can use an index, instead of comparing a computed bucket with the value.
        if ($target->kind === PlannedField::DIMENSION
            && in_array($target->spec->grain, self::GRAIN_RANGES, true)
            && $condition->operator === '='
            && is_string($condition->value)
            && strtotime($condition->value) !== false
        ) {
            [$start, $end] = $this->grainRange($target->spec->grain, $condition->value);
            $format = $target->field->type === DataType::Date ? 'Y-m-d' : 'Y-m-d H:i:s';
            $column = $this->column($target->node, $target->field->column);

            return new Fragment("({$column} >= ? AND {$column} < ?)", [$start->format($format), $end->format($format)]);
        }

        return $this->predicate($exprs[$target->alias()], $condition, $target->type);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} [start, end) */
    private function grainRange(string $grain, string $value): array
    {
        $date = CarbonImmutable::parse($value);
        $start = match ($grain) {
            'day' => $date->startOfDay(),
            'week' => $date->startOfWeek(),
            'month' => $date->startOfMonth(),
            'quarter' => $date->startOfQuarter(),
            'year' => $date->startOfYear(),
        };
        $end = match ($grain) {
            'day' => $start->addDay(),
            'week' => $start->addWeek(),
            'month' => $start->addMonth(),
            'quarter' => $start->addQuarter(),
            'year' => $start->addYear(),
        };

        return [$start, $end];
    }

    private function existsSql(PlannedCondition $c, JoinNode $anchor): Fragment
    {
        $lineage = $c->node->lineage();
        $chain = array_slice($lineage, array_search($anchor, $lineage, true) + 1);
        $first = array_shift($chain);

        $sub = $this->connection->query()->selectRaw('1')->from($this->tableRef($first));
        $sub->whereColumn("{$first->alias}.{$first->step->targetColumn()}", '=', "{$anchor->alias}.{$first->step->sourceColumn()}");
        $first->dataset->constrain($sub, $first->alias);
        foreach ($chain as $node) {
            $this->join($sub, $node, 'join');
        }
        foreach ([$first, ...$chain] as $node) {
            $this->secure($sub, $node);
        }

        $predicate = $this->predicate($this->column($c->node, $c->field->column), $c->condition, $c->field->type);
        $sub->whereRaw($predicate->sql, $predicate->bindings);

        return new Fragment('EXISTS ('.$sub->toSql().')', $sub->getBindings());
    }

    private function predicate(string $expr, FilterCondition $condition, DataType $type): Fragment
    {
        $value = $this->normalize($condition->value, $type);
        $like = $this->dialect->likeOperator();

        return match ($condition->operator) {
            '=' => new Fragment("{$expr} = ?", [$value]),
            '!=' => new Fragment("({$expr} <> ? OR {$expr} IS NULL)", [$value]),
            '>', '>=', '<', '<=' => new Fragment("{$expr} {$condition->operator} ?", [$value]),
            'in' => new Fragment("{$expr} IN (".$this->placeholders($value).')', $value),
            'not_in' => new Fragment("({$expr} NOT IN (".$this->placeholders($value).") OR {$expr} IS NULL)", $value),
            'between' => new Fragment("{$expr} BETWEEN ? AND ?", $value),
            'contains' => new Fragment("{$expr} {$like} ? ESCAPE '!'", ['%'.$this->escapeLike($value).'%']),
            'not_contains' => new Fragment("({$expr} NOT {$like} ? ESCAPE '!' OR {$expr} IS NULL)", ['%'.$this->escapeLike($value).'%']),
            'starts_with' => new Fragment("{$expr} {$like} ? ESCAPE '!'", [$this->escapeLike($value).'%']),
            'ends_with' => new Fragment("{$expr} {$like} ? ESCAPE '!'", ['%'.$this->escapeLike($value)]),
            'is_null' => new Fragment("{$expr} IS NULL"),
            'not_null' => new Fragment("{$expr} IS NOT NULL"),
        };
    }

    private function normalize(mixed $value, DataType $type): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($v) => $this->normalize($v, $type), $value);
        }
        if ($type === DataType::Boolean && $value !== null) {
            return $this->dialect->booleanBinding(filter_var($value, FILTER_VALIDATE_BOOL));
        }
        // A date column compared with "2026-03-31 23:59:59" would miss that day in databases
        // that compare dates as text, so date filters use the date part only.
        if ($type === DataType::Date && is_string($value) && preg_match('/^(\d{4}-\d{2}-\d{2})[ T]/', $value, $m)) {
            return $m[1];
        }

        return $value;
    }

    private function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    // -------------------------------------------------------------- metadata

    private function columns(): array
    {
        $columns = [];
        foreach ($this->plan->fields as $alias => $field) {
            $columns[] = [
                'alias' => $alias,
                'label' => $field->label,
                'type' => $field->type->value,
                'kind' => $field->isTotal() ? 'measure' : 'dimension',
                'format' => $field->spec->format ?? ($field->kind === PlannedField::MEASURE && $field->spec->aggregation !== Aggregation::Count && ! $field->isRowCount() ? $field->field?->format : null),
                'dataset' => $field->node?->dataset->key,
                'total' => $this->grandTotal($field),
            ];
        }
        if ($this->plan->definition->subtotals) {
            $columns[] = ['alias' => '__subtotal', 'label' => 'Subtotal level', 'type' => 'integer', 'kind' => 'meta', 'format' => null, 'dataset' => null];
        }

        return $columns;
    }

    /**
     * How a column's grand total is worked out from its values: sums and
     * counts add up, min / max of min / max; averages, distinct counts and
     * formulas have no meaningful total.
     */
    private function grandTotal(PlannedField $field): ?string
    {
        if ($field->kind === PlannedField::FORMULA || $field->formula !== null) {
            return null;
        }
        if ($field->kind === PlannedField::MEASURE) {
            return match ($field->spec->aggregation) {
                Aggregation::Sum, Aggregation::Count => 'sum',
                Aggregation::Min => 'min',
                Aggregation::Max => 'max',
                default => null,
            };
        }

        // Detail rows: amounts add up, codes and ids do not.
        return $field->field?->role === \Abdalla\ReportGenerator\Enums\FieldRole::Measure
            && in_array($field->type, [DataType::Integer, DataType::Decimal], true) ? 'sum' : null;
    }

    private function filteredFields(): array
    {
        $refs = [];
        foreach ($this->plan->conditions as $condition) {
            if ($condition->node) {
                $refs[] = "{$condition->node->dataset->key}.{$condition->field->key}";
            }
        }

        return array_values(array_unique($refs));
    }
}
