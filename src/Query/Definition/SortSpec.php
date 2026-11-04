<?php

namespace Abdalla\ReportGenerator\Query\Definition;

final class SortSpec
{
    public function __construct(
        public readonly string $alias,
        public readonly bool $descending = false,
    ) {
    }
}
