<?php

namespace Abdalla\ReportGenerator\Query\Planning;

use Abdalla\ReportGenerator\Query\Definition\FilterCondition;
use Abdalla\ReportGenerator\Semantic\Published\PublishedField;

final class PlannedCondition
{
    public function __construct(
        public readonly FilterCondition $condition,
        public readonly ?JoinNode $node = null,
        public readonly ?PublishedField $field = null,
        /** Set when the condition targets one of the report's output columns. */
        public readonly ?PlannedField $target = null,
    ) {
    }

    public function isTotal(): bool
    {
        return $this->target?->isTotal() ?? false;
    }
}
