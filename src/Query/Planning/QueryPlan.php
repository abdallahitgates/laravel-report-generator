<?php

namespace Abdalla\ReportGenerator\Query\Planning;

use Abdalla\ReportGenerator\Query\Definition\FilterCondition;
use Abdalla\ReportGenerator\Query\Definition\ReportDefinition;
use Abdalla\ReportGenerator\Semantic\Published\SemanticModel;

final class QueryPlan
{
    /**
     * @param  array<string, PlannedField>  $fields  by alias, in report order
     * @param  array<int, PlannedCondition>  $conditions  by spl_object_id of the FilterCondition
     */
    public function __construct(
        public readonly ReportDefinition $definition,
        public readonly SemanticModel $model,
        public readonly JoinPlanner $joins,
        public readonly array $fields,
        public readonly array $conditions,
    ) {
    }

    public function condition(FilterCondition $condition): PlannedCondition
    {
        return $this->conditions[spl_object_id($condition)];
    }

    public function isAggregated(): bool
    {
        foreach ($this->fields as $field) {
            if ($field->kind === PlannedField::MEASURE) {
                return true;
            }
        }

        return false;
    }

    /** @return list<PlannedField> */
    public function dimensions(): array
    {
        return array_values(array_filter($this->fields, fn (PlannedField $f) => ! $f->isTotal()));
    }

    /** @return list<PlannedField> */
    public function measures(): array
    {
        return array_values(array_filter($this->fields, fn (PlannedField $f) => $f->kind === PlannedField::MEASURE));
    }

    /** @return list<string> dataset keys the report reads */
    public function datasets(): array
    {
        $keys = [];
        foreach ($this->fields as $field) {
            if ($field->node) {
                $keys[$field->node->dataset->key] = true;
            }
        }
        foreach ($this->conditions as $condition) {
            if ($condition->node) {
                $keys[$condition->node->dataset->key] = true;
            }
        }

        return array_keys($keys);
    }
}
