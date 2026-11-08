<?php

namespace Abdalla\ReportGenerator\Security;

use Abdalla\ReportGenerator\Semantic\Published\PublishedDataset;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * What a row scope knows: who asks, which topic instance (alias) it is
 * restricting, which columns hold the employee / division / line, and the
 * report's period and lines (structure assignments change over time).
 */
final class ScopeContext
{
    /** @param  list<int|string>  $lines */
    public function __construct(
        public readonly PublishedDataset $dataset,
        public readonly string $alias,
        public readonly ?Authenticatable $user,
        public readonly ?string $from = null,
        public readonly ?string $to = null,
        public readonly array $lines = [],
    ) {
    }

    public function column(string $column): string
    {
        return $this->alias.'.'.$column;
    }

    /** Qualified employee column, or null when the topic has none. */
    public function employeeColumn(): ?string
    {
        return $this->dataset->employeeColumn ? $this->column($this->dataset->employeeColumn) : null;
    }

    public function divisionColumn(): ?string
    {
        return $this->dataset->divisionColumn ? $this->column($this->dataset->divisionColumn) : null;
    }

    public function lineColumn(): ?string
    {
        return $this->dataset->lineColumn ? $this->column($this->dataset->lineColumn) : null;
    }

    /** Qualified column the topic declares in $scopeColumns under this name, e.g. scopeColumn('product'). */
    public function scopeColumn(string $name): ?string
    {
        $column = $this->dataset->scopeColumns[$name] ?? null;

        return $column ? $this->column($column) : null;
    }
}
