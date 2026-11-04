<?php

namespace Abdalla\ReportGenerator\Query\Definition;

final class FilterGroup
{
    /** @param  list<FilterGroup|FilterCondition>  $children */
    public function __construct(
        public readonly string $boolean,
        public readonly array $children,
    ) {
    }

    /** @return iterable<FilterCondition> */
    public function conditions(): iterable
    {
        foreach ($this->children as $child) {
            if ($child instanceof FilterGroup) {
                yield from $child->conditions();
            } else {
                yield $child;
            }
        }
    }
}
