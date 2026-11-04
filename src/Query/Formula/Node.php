<?php

namespace Abdalla\ReportGenerator\Query\Formula;

/**
 * Formula AST. kind: number | string | bool | null | ref | unary | binary | call
 */
final class Node
{
    /** @param  list<Node>  $children */
    public function __construct(
        public readonly string $kind,
        public readonly string $value = '',
        public readonly array $children = [],
    ) {
    }

    /** @return list<string> field names referenced anywhere in the expression */
    public function references(): array
    {
        if ($this->kind === 'ref') {
            return [$this->value];
        }

        $names = [];
        foreach ($this->children as $child) {
            array_push($names, ...$child->references());
        }

        return array_values(array_unique($names));
    }
}
