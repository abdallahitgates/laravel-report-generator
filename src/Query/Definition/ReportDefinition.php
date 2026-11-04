<?php

namespace Abdalla\ReportGenerator\Query\Definition;

/**
 * A validated report, with parameters already substituted. Immutable;
 * drill-down creates a derived copy.
 */
final class ReportDefinition
{
    /** Row count added to group levels of reports without totals. */
    public const GROUP_COUNT = '__group_count';

    /**
     * @param  list<FieldSpec>  $fields
     * @param  list<SortSpec>  $sort
     */
    public function __construct(
        public readonly string $root,
        public readonly array $fields,
        public readonly ?FilterGroup $filters = null,
        public readonly array $sort = [],
        public readonly ?int $limit = null,
        public readonly bool $subtotals = false,
    ) {
    }

    public function field(string $alias): ?FieldSpec
    {
        foreach ($this->fields as $field) {
            if ($field->alias === $alias) {
                return $field;
            }
        }

        return null;
    }

    public function isAggregated(): bool
    {
        foreach ($this->fields as $field) {
            if ($field->isMeasure()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The definition for one level of a drill-down (AG Grid server-side row
     * model): group by the next column, filtered to the keys opened so far.
     * At the leaf level the full definition is returned, filtered.
     *
     * @param  list<string>  $groupColumns  aliases of dimension fields, outermost first
     * @param  list<mixed>  $keys  values of the groups already opened
     */
    public function atGroupLevel(array $groupColumns, array $keys): self
    {
        $conditions = [];
        foreach (array_values($keys) as $i => $key) {
            $conditions[] = new FilterCondition(
                $key === null ? 'is_null' : '=', $key, alias: $groupColumns[$i], location: "groups.keys.{$i}"
            );
        }

        $filters = match (true) {
            $conditions === [] => $this->filters,
            $this->filters === null => new FilterGroup('and', $conditions),
            default => new FilterGroup('and', [$this->filters, ...$conditions]),
        };

        $level = count($keys);
        if ($level >= count($groupColumns)) {
            return new self($this->root, $this->fields, $filters, $this->sort, $this->limit, false);
        }

        // Group level: the groups opened so far (constant within this level, and the
        // filters above refer to them), the next group column, and every measure.
        $groupAlias = $groupColumns[$level];
        $levels = array_flip(array_slice($groupColumns, 0, $level + 1));
        $kept = [];
        foreach ($this->fields as $f) {
            if (isset($levels[$f->alias]) || $f->isMeasure()) {
                $kept[$f->alias] = true;
            }
        }
        // Formulas stay when everything they use is still there.
        do {
            $added = false;
            foreach ($this->fields as $f) {
                if ($f->isFormula() && ! isset($kept[$f->alias]) && $this->formulaInputsKept($f, $kept)) {
                    $kept[$f->alias] = $added = true;
                }
            }
        } while ($added);

        $fields = array_values(array_filter($this->fields, fn (FieldSpec $f) => isset($kept[$f->alias])));

        // A detail report has nothing to total: count the rows in each group, which also
        // makes the group rows distinct and tells the grid how many items a group holds.
        if (! $this->isAggregated()) {
            $fields[] = new FieldSpec(self::GROUP_COUNT, $this->root, '__rows', \Abdalla\ReportGenerator\Enums\Aggregation::Count, label: 'Rows');
        }
        $sort = array_values(array_filter($this->sort, fn (SortSpec $s) => isset($kept[$s->alias])));

        return new self($this->root, $fields, $filters, $sort ?: [new SortSpec($groupAlias)], $this->limit, false);
    }

    /** @param  array<string, true>  $kept */
    private function formulaInputsKept(FieldSpec $formula, array $kept): bool
    {
        try {
            $references = \Abdalla\ReportGenerator\Query\Formula\Parser::parse($formula->formula)->references();
        } catch (\Abdalla\ReportGenerator\Query\Formula\FormulaException) {
            return true; // keep it, so the planner reports the formula error
        }

        foreach ($references as $name) {
            if (! isset($kept[$name])) {
                return false;
            }
        }

        return true;
    }
}
