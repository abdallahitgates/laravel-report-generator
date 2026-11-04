<?php

namespace Abdalla\ReportGenerator\Query\Definition;

use Abdalla\ReportGenerator\Enums\Aggregation;

/**
 * One output column of a report: a dataset field (optionally aggregated or
 * bucketed by a time grain) or a formula over other output columns.
 */
final class FieldSpec
{
    /** @param  list<int>|null  $path  relationship ids from the root, to pick one of several join paths */
    public function __construct(
        public readonly string $alias,
        public readonly ?string $dataset = null,
        public readonly ?string $field = null,
        public readonly ?Aggregation $aggregation = null,
        public readonly ?string $grain = null,
        public readonly ?array $path = null,
        public readonly ?string $formula = null,
        public readonly ?string $label = null,
        public readonly ?string $format = null,
    ) {
    }

    public function isFormula(): bool
    {
        return $this->formula !== null;
    }

    public function isMeasure(): bool
    {
        return $this->aggregation !== null;
    }

    public function ref(): string
    {
        return "{$this->dataset}.{$this->field}";
    }
}
