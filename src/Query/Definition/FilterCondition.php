<?php

namespace Abdalla\ReportGenerator\Query\Definition;

final class FilterCondition
{
    public const OPERATORS = [
        '=', '!=', '>', '>=', '<', '<=', 'in', 'not_in', 'between',
        'contains', 'not_contains', 'starts_with', 'ends_with', 'is_null', 'not_null',
    ];

    /**
     * @param  string|null  $alias  set when the condition targets an output column of the report
     * @param  list<int>|null  $path
     */
    public function __construct(
        public readonly string $operator,
        public readonly mixed $value,
        public readonly ?string $dataset = null,
        public readonly ?string $field = null,
        public readonly ?string $alias = null,
        public readonly ?array $path = null,
        public readonly string $location = '',
    ) {
    }

    public function targetsAlias(): bool
    {
        return $this->alias !== null;
    }
}
