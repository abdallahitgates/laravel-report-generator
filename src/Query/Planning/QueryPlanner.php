<?php

namespace Abdalla\ReportGenerator\Query\Planning;

use Abdalla\ReportGenerator\Enums\Aggregation;
use Abdalla\ReportGenerator\Enums\DataType;
use Abdalla\ReportGenerator\Query\Definition\FieldSpec;
use Abdalla\ReportGenerator\Query\Definition\FilterCondition;
use Abdalla\ReportGenerator\Query\Definition\ReportDefinition;
use Abdalla\ReportGenerator\Query\Formula\FormulaException;
use Abdalla\ReportGenerator\Query\Formula\Parser;
use Abdalla\ReportGenerator\Query\QueryException;
use Abdalla\ReportGenerator\Semantic\Published\SemanticModel;
use Closure;

/**
 * Checks a definition against the published model and resolves every
 * field and filter to a dataset instance in the join tree. How the tree
 * becomes SQL (single query, EXISTS filters, per-branch aggregation) is the
 * compiler's job.
 */
final class QueryPlanner
{
    /** Virtual field on every dataset: its number of rows. */
    public const ROW_COUNT = '__rows';

    private const STRING_OPERATORS = ['contains', 'not_contains', 'starts_with', 'ends_with'];

    /** @param  Closure(string, bool): void  $assertAllowed */
    public function plan(ReportDefinition $definition, SemanticModel $model, Closure $assertAllowed): QueryPlan
    {
        $joins = new JoinPlanner($model, new JoinGraph($model), $definition->root, $assertAllowed);

        $fields = [];
        foreach ($definition->fields as $i => $spec) {
            if (! $spec->isFormula()) {
                $fields[$spec->alias] = $this->column($spec, $joins, "fields.{$i}");
            }
        }
        foreach ($definition->fields as $i => $spec) {
            if ($spec->isFormula()) {
                $fields[$spec->alias] = null; // placeholder keeps report order
            }
        }
        $this->formulas($definition, $fields);

        // Restore the order the author chose.
        $ordered = [];
        foreach ($definition->fields as $spec) {
            $ordered[$spec->alias] = $fields[$spec->alias];
        }

        $conditions = [];
        foreach ($definition->filters?->conditions() ?? [] as $condition) {
            $conditions[spl_object_id($condition)] = $this->condition($condition, $joins, $ordered);
        }

        if ($definition->subtotals && ! array_filter($ordered, fn (PlannedField $f) => $f->kind === PlannedField::MEASURE)) {
            throw new QueryException('SUBTOTALS_NEED_TOTALS', 'Subtotals need at least one aggregated field (sum, count, ...).', 'subtotals');
        }

        return new QueryPlan($definition, $model, $joins, $ordered, $conditions);
    }

    private function column(FieldSpec $spec, JoinPlanner $joins, string $at): PlannedField
    {
        $node = $joins->nodeFor($spec->dataset, $spec->path, "{$at}.ref");
        $dataset = $node->dataset;

        if ($spec->field === self::ROW_COUNT) {
            if ($spec->aggregation !== Aggregation::Count && $spec->aggregation !== Aggregation::CountDistinct) {
                throw new QueryException('INVALID_AGGREGATION', "\"".($dataset->countLabel ?? "Number of {$dataset->label}")."\" can only be counted.", "{$at}.agg");
            }
            if ($dataset->primaryKey === [] && ! $node->isRoot()) {
                throw new QueryException('NO_PRIMARY_KEY', "{$dataset->label} has no primary key, so its rows cannot be counted through a join.", "{$at}.ref");
            }

            return new PlannedField($spec, PlannedField::MEASURE, DataType::Integer, $spec->label ?? $dataset->countLabel ?? "Number of {$dataset->label}", $node);
        }

        $field = $dataset->field($spec->field)
            ?? throw new QueryException('UNKNOWN_FIELD', "{$dataset->label} has no field \"{$spec->field}\" (or it is hidden).", "{$at}.ref");

        if ($spec->grain !== null) {
            if (! $field->type->isTemporal()) {
                throw new QueryException('INVALID_GRAIN', "{$field->label} is not a date, so it cannot be grouped by {$spec->grain}.", "{$at}.grain");
            }
            if (in_array($spec->grain, ['hour', 'hour_of_day'], true) && $field->type !== DataType::DateTime) {
                throw new QueryException('INVALID_GRAIN', "{$field->label} has no time of day, so it cannot be grouped by hour.", "{$at}.grain");
            }
            if ($spec->aggregation !== null) {
                throw new QueryException('INVALID_GRAIN', 'A field is either grouped by a date part or aggregated, not both.', "{$at}.grain");
            }
        }

        if ($spec->aggregation !== null) {
            if (! $field->allows($spec->aggregation)) {
                $allowed = implode(', ', array_map(fn (Aggregation $a) => $a->value, Aggregation::forType($field->type)));
                throw new QueryException('INVALID_AGGREGATION', "{$field->label} cannot use {$spec->aggregation->value}. It allows: {$allowed}.", "{$at}.agg");
            }

            $type = match ($spec->aggregation) {
                Aggregation::Count, Aggregation::CountDistinct => DataType::Integer,
                Aggregation::Avg => DataType::Decimal,
                default => $field->type,
            };

            return new PlannedField($spec, PlannedField::MEASURE, $type, $spec->label ?? $field->label, $node, $field);
        }

        $type = match ($spec->grain) {
            null, 'hour' => $field->type,
            'month_of_year', 'day_of_week', 'hour_of_day' => DataType::Integer,
            default => DataType::Date,
        };

        return new PlannedField($spec, PlannedField::DIMENSION, $type, $spec->label ?? $field->label, $node, $field);
    }

    /** @param  array<string, PlannedField|null>  $fields */
    private function formulas(ReportDefinition $definition, array &$fields): void
    {
        $asts = [];
        foreach ($definition->fields as $i => $spec) {
            if (! $spec->isFormula()) {
                continue;
            }
            try {
                $asts[$spec->alias] = [Parser::parse($spec->formula), $spec, $i];
            } catch (FormulaException $e) {
                throw new QueryException('INVALID_FORMULA', "Formula \"{$spec->alias}\": {$e->getMessage()}", "fields.{$i}.formula", ['position' => $e->position]);
            }
        }

        $resolving = [];
        $resolve = function (string $alias) use (&$resolve, &$resolving, &$fields, $asts): PlannedField {
            if ($fields[$alias] ?? null) {
                return $fields[$alias];
            }
            [$ast, $spec, $i] = $asts[$alias];
            if (isset($resolving[$alias])) {
                throw new QueryException('CIRCULAR_FORMULA', "Formula \"{$alias}\" refers to itself through other formulas.", "fields.{$i}.formula");
            }
            $resolving[$alias] = true;

            $aggregate = false;
            foreach ($ast->references() as $name) {
                if (! array_key_exists($name, $fields)) {
                    throw new QueryException('UNKNOWN_ALIAS', "Formula \"{$alias}\" uses \"{$name}\", which is not one of the report's fields.", "fields.{$i}.formula");
                }
                $aggregate = $aggregate || $resolve($name)->isTotal();
            }

            unset($resolving[$alias]);

            return $fields[$alias] = new PlannedField($spec, PlannedField::FORMULA, DataType::Decimal, $spec->label ?? $spec->alias, formula: $ast, aggregate: $aggregate);
        };

        foreach (array_keys($asts) as $alias) {
            $resolve($alias);
        }
    }

    /** @param  array<string, PlannedField>  $fields */
    private function condition(FilterCondition $condition, JoinPlanner $joins, array $fields): PlannedCondition
    {
        $at = $condition->location;

        if ($condition->targetsAlias()) {
            $target = $fields[$condition->alias];
            if ($target->kind !== PlannedField::FORMULA && $target->field) {
                $this->checkOperator($condition, $target->type, $target->label, $at);
            }

            return new PlannedCondition($condition, target: $target);
        }

        $node = $joins->nodeFor($condition->dataset, $condition->path, "{$at}.field");
        $field = $node->dataset->field($condition->field)
            ?? throw new QueryException('UNKNOWN_FIELD', "{$node->dataset->label} has no field \"{$condition->field}\" (or it is hidden).", "{$at}.field");
        $this->checkOperator($condition, $field->type, $field->label, $at);

        return new PlannedCondition($condition, $node, $field);
    }

    private function checkOperator(FilterCondition $condition, DataType $type, string $label, string $at): void
    {
        $operator = $condition->operator;

        if (in_array($operator, self::STRING_OPERATORS, true) && ! in_array($type, [DataType::String, DataType::Text], true)) {
            throw new QueryException('INVALID_OPERATOR', "\"{$operator}\" only works on text; {$label} is {$type->value}.", "{$at}.op");
        }
        if ($type === DataType::Boolean && ! in_array($operator, ['=', '!=', 'is_null', 'not_null'], true)) {
            throw new QueryException('INVALID_OPERATOR', "{$label} is yes/no: filter it with = or !=.", "{$at}.op");
        }

        if ($type->isNumeric() && $condition->value !== null) {
            foreach ((array) $condition->value as $value) {
                if (! is_numeric($value)) {
                    throw new QueryException('INVALID_VALUE', "{$label} is a number; \"{$value}\" is not.", "{$at}.value");
                }
            }
        }
        if ($type->isTemporal() && $condition->value !== null && ! in_array($operator, self::STRING_OPERATORS, true)) {
            foreach ((array) $condition->value as $value) {
                if (! is_string($value) || strtotime($value) === false) {
                    throw new QueryException('INVALID_VALUE', "{$label} is a date; \"{$value}\" is not a date.", "{$at}.value");
                }
            }
        }
    }
}
