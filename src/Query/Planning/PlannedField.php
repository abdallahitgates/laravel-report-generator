<?php

namespace Abdalla\ReportGenerator\Query\Planning;

use Abdalla\ReportGenerator\Enums\DataType;
use Abdalla\ReportGenerator\Query\Definition\FieldSpec;
use Abdalla\ReportGenerator\Query\Formula\Node;
use Abdalla\ReportGenerator\Semantic\Published\PublishedField;

final class PlannedField
{
    public const DIMENSION = 'dimension';
    public const MEASURE = 'measure';
    public const FORMULA = 'formula';

    public function __construct(
        public readonly FieldSpec $spec,
        public readonly string $kind,
        public readonly DataType $type,
        public readonly string $label,
        public readonly ?JoinNode $node = null,
        /** null for the virtual __rows field and for formulas */
        public readonly ?PublishedField $field = null,
        public readonly ?Node $formula = null,
        /** For formulas: whether it uses a measure, which makes it a total rather than a row value. */
        public readonly bool $aggregate = false,
    ) {
    }

    public function alias(): string
    {
        return $this->spec->alias;
    }

    /** Totals (measures and formulas over measures) filter in HAVING; everything else in WHERE. */
    public function isTotal(): bool
    {
        return $this->kind === self::MEASURE || ($this->kind === self::FORMULA && $this->aggregate);
    }

    public function isRowCount(): bool
    {
        return $this->spec->field === QueryPlanner::ROW_COUNT;
    }
}
